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

function bodyEmbedding(Media ...$media): string
{
    $tags = array_map(fn (Media $item): string => "<img data-id=\"{$item->uuid}\" src=\"x\">", $media);

    return '<p>'.implode('', $tags).'</p>';
}

it('claims a pending upload onto the record without moving the file', function (): void {
    $media = pendingUpload($this->user, onePixelPng(), 'a.png');
    $path = $media->getPathRelativeToRoot();
    $note = Note::factory()->create(['workspace_id' => $this->workspace->getKey()]);

    $note->saveCustomFieldValue($this->body, bodyEmbedding($media));

    $media->refresh();
    expect($media->model_type)->toBe($note->getMorphClass())
        ->and($media->model_id)->toBe($note->getKey())
        ->and($media->collection_name)->toBe(MediaCollection::Attachments->value)
        ->and($media->custom_field_id)->toBe($this->body->getKey())
        ->and($media->getPathRelativeToRoot())->toBe($path);
    Storage::disk('local')->assertExists($path);
});

it('claims every image a rich editor body references and releases the ones it drops', function (): void {
    $kept = pendingUpload($this->user, onePixelPng(), 'kept.png');
    $dropped = pendingUpload($this->user, onePixelPng(), 'dropped.png');
    $note = Note::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $note->saveCustomFieldValue($this->body, bodyEmbedding($kept, $dropped));

    expect($kept->refresh()->collection_name)->toBe(MediaCollection::Attachments->value)
        ->and($kept->custom_field_id)->toBe($this->body->getKey())
        ->and($dropped->refresh()->model_id)->toBe($note->getKey());

    $note->saveCustomFieldValue($this->body, bodyEmbedding($kept));

    expect(Media::query()->find($dropped->getKey()))->toBeNull()
        ->and(Media::query()->find($kept->getKey()))->not->toBeNull();
    Storage::disk('local')->assertMissing($dropped->getPathRelativeToRoot());
});

it('keeps the released file when the surrounding transaction rolls back', function (): void {
    $first = pendingUpload($this->user, onePixelPng(), 'a.png');
    $second = pendingUpload($this->user, onePixelPng(), 'b.png');
    $note = Note::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $note->saveCustomFieldValue($this->body, bodyEmbedding($first));

    expect(fn (): mixed => DB::transaction(function () use ($note, $second): void {
        $note->saveCustomFieldValue($this->body, bodyEmbedding($second));

        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class);

    expect(Media::query()->find($first->getKey()))->not->toBeNull();
    Storage::disk('local')->assertExists($first->getPathRelativeToRoot());
});

it('releases the images when a rich editor body is cleared', function (): void {
    $media = pendingUpload($this->user, onePixelPng(), 'a.png');
    $note = Note::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $note->saveCustomFieldValue($this->body, bodyEmbedding($media));

    $note->saveCustomFieldValue($this->body, null);

    expect(Media::query()->find($media->getKey()))->toBeNull();
});

it('never claims another team\'s upload', function (): void {
    $stranger = User::factory()->withPersonalWorkspace()->create();
    $foreign = pendingUpload($stranger, onePixelPng(), 'a.png');
    $note = Note::factory()->create(['workspace_id' => $this->workspace->getKey()]);

    $note->saveCustomFieldValue($this->body, bodyEmbedding($foreign));

    expect($foreign->refresh()->collection_name)->toBe(MediaCollection::PendingUploads->value)
        ->and($foreign->model_id)->toBe($stranger->personalWorkspace()->getKey());
});

it('refuses a body embedding an image another record owns', function (): void {
    $media = pendingUpload($this->user, onePixelPng(), 'a.png');
    $noteA = Note::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $noteB = Note::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $noteA->saveCustomFieldValue($this->body, bodyEmbedding($media));

    expect(fn (): mixed => $noteB->saveCustomFieldValue($this->body, bodyEmbedding($media)))
        ->toThrow(ValidationException::class);

    expect(CustomFieldValue::query()
        ->where('entity_type', $noteB->getMorphClass())
        ->where('entity_id', $noteB->getKey())
        ->where('custom_field_id', $this->body->getKey())
        ->exists())->toBeFalse();
});

it('keeps releasing after the field code is renamed', function (): void {
    $summary = CustomField::factory()->create([
        'tenant_id' => $this->workspace->getKey(),
        'entity_type' => 'note',
        'code' => 'summary',
        'name' => 'Summary',
        'type' => 'rich-editor',
        'validation_rules' => [],
        'active' => true,
        'system_defined' => false,
    ]);
    $first = pendingUpload($this->user, onePixelPng(), 'a.png');
    $second = pendingUpload($this->user, onePixelPng(), 'b.png');
    $note = Note::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $note->saveCustomFieldValue($summary, bodyEmbedding($first));

    $summary->update(['code' => 'narrative']);
    $note->saveCustomFieldValue($summary->refresh(), bodyEmbedding($second));

    expect(Media::query()->find($first->getKey()))->toBeNull()
        ->and($second->refresh()->custom_field_id)->toBe($summary->getKey());
});

it('deletes claimed media with the record', function (): void {
    $media = pendingUpload($this->user, onePixelPng(), 'a.png');
    $note = Note::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $note->saveCustomFieldValue($this->body, bodyEmbedding($media));

    $note->forceDelete();

    expect(Media::query()->find($media->getKey()))->toBeNull();
});
