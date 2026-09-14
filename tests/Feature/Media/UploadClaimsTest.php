<?php

declare(strict_types=1);

use App\Actions\Upload\StorePendingUpload;
use App\Enums\MediaCollection;
use App\Enums\UploadSource;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Note;
use App\Models\User;
use App\Observers\CustomFieldValueObserver;
use App\Support\Media\UploadClaims;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Relaticle\CustomFields\Services\TenantContextService;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

mutates(UploadClaims::class, CustomFieldValueObserver::class);

beforeEach(function (): void {
    Storage::fake('local');
    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = $this->user->personalWorkspace();
    TenantContextService::setTenantId($this->workspace->getKey());
    $this->contract = CustomField::factory()->create([
        'tenant_id' => $this->workspace->getKey(),
        'entity_type' => 'note',
        'code' => 'contract',
        'name' => 'Contract',
        'type' => 'file-upload',
        'validation_rules' => [],
        'active' => true,
        'system_defined' => false,
    ]);
    $this->body = CustomField::query()
        ->where('tenant_id', $this->workspace->getKey())
        ->where('entity_type', 'note')
        ->where('code', 'body')
        ->firstOrFail();
});

afterEach(function (): void {
    TenantContextService::setTenantId(null);
});

function pendingUpload(User $user, string $bytes, string $name): Media
{
    $path = tempnam(sys_get_temp_dir(), 'claim');
    file_put_contents($path, $bytes);

    return resolve(StorePendingUpload::class)->execute($user, $user->personalWorkspace(), $path, $name, UploadSource::Panel);
}

it('claims a pending upload onto the record when a file value references it', function (): void {
    $media = pendingUpload($this->user, pdfBytes(), 'a.pdf');
    $path = $media->getPathRelativeToRoot();
    $note = Note::factory()->create(['workspace_id' => $this->workspace->getKey()]);

    $note->saveCustomFieldValue($this->contract, $media->uuid);

    $media->refresh();
    expect($media->model_type)->toBe($note->getMorphClass())
        ->and($media->model_id)->toBe($note->getKey())
        ->and($media->collection_name)->toBe(MediaCollection::Attachments->value)
        ->and($media->custom_field_id)->toBe($this->contract->getKey())
        ->and($media->getPathRelativeToRoot())->toBe($path);
    Storage::disk('local')->assertExists($path);
});

it('releases the previous file when a file value is replaced', function (): void {
    $first = pendingUpload($this->user, pdfBytes(), 'a.pdf');
    $second = pendingUpload($this->user, pdfBytes(), 'b.pdf');
    $note = Note::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $note->saveCustomFieldValue($this->contract, $first->uuid);

    $note->saveCustomFieldValue($this->contract, $second->uuid);

    expect(Media::query()->find($first->getKey()))->toBeNull()
        ->and($second->refresh()->model_id)->toBe($note->getKey());
    Storage::disk('local')->assertMissing($first->getPathRelativeToRoot());
});

it('keeps the released file when the surrounding transaction rolls back', function (): void {
    $first = pendingUpload($this->user, pdfBytes(), 'a.pdf');
    $second = pendingUpload($this->user, pdfBytes(), 'b.pdf');
    $note = Note::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $note->saveCustomFieldValue($this->contract, $first->uuid);

    expect(fn (): mixed => DB::transaction(function () use ($note, $second): void {
        $note->saveCustomFieldValue($this->contract, $second->uuid);

        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class);

    expect(Media::query()->find($first->getKey()))->not->toBeNull();
    Storage::disk('local')->assertExists($first->getPathRelativeToRoot());
});

it('releases the file when a file value is cleared', function (): void {
    $media = pendingUpload($this->user, pdfBytes(), 'a.pdf');
    $note = Note::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $note->saveCustomFieldValue($this->contract, $media->uuid);

    $note->saveCustomFieldValue($this->contract, null);

    expect(Media::query()->find($media->getKey()))->toBeNull();
});

it('never claims another team\'s pending upload', function (): void {
    $stranger = User::factory()->withPersonalWorkspace()->create();
    $foreign = pendingUpload($stranger, pdfBytes(), 'a.pdf');
    $note = Note::factory()->create(['workspace_id' => $this->workspace->getKey()]);

    expect(fn (): mixed => $note->saveCustomFieldValue($this->contract, $foreign->uuid))
        ->toThrow(ValidationException::class);

    expect($foreign->refresh()->collection_name)->toBe(MediaCollection::PendingUploads->value)
        ->and($foreign->model_id)->toBe($stranger->personalWorkspace()->getKey());
});

it('refuses to store a file value whose upload another record owns', function (): void {
    $media = pendingUpload($this->user, pdfBytes(), 'a.pdf');
    $noteA = Note::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $noteB = Note::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $noteA->saveCustomFieldValue($this->contract, $media->uuid);

    expect(fn (): mixed => $noteB->saveCustomFieldValue($this->contract, $media->uuid))
        ->toThrow(ValidationException::class);

    expect(CustomFieldValue::query()
        ->where('entity_type', $noteB->getMorphClass())
        ->where('entity_id', $noteB->getKey())
        ->where('custom_field_id', $this->contract->getKey())
        ->exists())->toBeFalse();
});

it('claims every image a rich editor body references and releases the ones it drops', function (): void {
    $kept = pendingUpload($this->user, onePixelPng(), 'kept.png');
    $dropped = pendingUpload($this->user, onePixelPng(), 'dropped.png');
    $note = Note::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $note->saveCustomFieldValue($this->body, "<p><img data-id=\"{$kept->uuid}\" src=\"x\"><img data-id=\"{$dropped->uuid}\" src=\"y\"></p>");

    expect($kept->refresh()->collection_name)->toBe(MediaCollection::Attachments->value)
        ->and($kept->custom_field_id)->toBe($this->body->getKey())
        ->and($dropped->refresh()->model_id)->toBe($note->getKey());

    $note->saveCustomFieldValue($this->body, "<p><img data-id=\"{$kept->uuid}\" src=\"x\"></p>");

    expect(Media::query()->find($dropped->getKey()))->toBeNull()
        ->and(Media::query()->find($kept->getKey()))->not->toBeNull();
});

it('keeps releasing after the field code is renamed', function (): void {
    $first = pendingUpload($this->user, pdfBytes(), 'a.pdf');
    $second = pendingUpload($this->user, pdfBytes(), 'b.pdf');
    $note = Note::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $note->saveCustomFieldValue($this->contract, $first->uuid);

    $this->contract->update(['code' => 'agreement']);
    $note->saveCustomFieldValue($this->contract->refresh(), $second->uuid);

    expect(Media::query()->find($first->getKey()))->toBeNull()
        ->and($second->refresh()->custom_field_id)->toBe($this->contract->getKey());
});

it('deletes claimed media with the record', function (): void {
    $media = pendingUpload($this->user, pdfBytes(), 'a.pdf');
    $note = Note::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $note->saveCustomFieldValue($this->contract, $media->uuid);

    $note->forceDelete();

    expect(Media::query()->find($media->getKey()))->toBeNull();
});
