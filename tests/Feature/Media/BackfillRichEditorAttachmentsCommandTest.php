<?php

declare(strict_types=1);

use App\Actions\Upload\BackfillRichEditorAttachments;
use App\Console\Commands\BackfillRichEditorAttachmentsCommand;
use App\Enums\MediaCollection;
use App\Models\CustomField;
use App\Models\Note;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Relaticle\CustomFields\Services\TenantContextService;
use Spatie\Activitylog\Models\Activity;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

mutates(BackfillRichEditorAttachments::class, BackfillRichEditorAttachmentsCommand::class);

beforeEach(function (): void {
    Storage::fake('public');
    $this->workspace = User::factory()->withPersonalWorkspace()->create()->personalWorkspace();
    $this->body = CustomField::query()
        ->where('tenant_id', $this->workspace->getKey())
        ->where('entity_type', 'note')
        ->where('code', 'body')
        ->firstOrFail();
    Storage::disk('public')->put('legacy.png', onePixelPng());
    $this->note = Note::factory()->create(['workspace_id' => $this->workspace->getKey()]);

    TenantContextService::withTenant($this->workspace->getKey(), fn () => $this->note->saveCustomFieldValue(
        $this->body,
        '<p><img src="https://app.test/storage/legacy.png" alt="old" data-id="legacy.png"></p>',
    ));
});

it('reports without writing by default', function (): void {
    $this->artisan('media:backfill-rich-editor-attachments')
        ->expectsOutputToContain('1 image(s) would be migrated')
        ->assertSuccessful();

    expect(Media::query()->count())->toBe(0);
});

it('creates a media row on the record and rewrites the image with --force, outside any tenant context', function (): void {
    $activities = Activity::query()->count();

    $this->artisan('media:backfill-rich-editor-attachments --force')
        ->expectsOutputToContain('1 image(s) migrated.')
        ->assertSuccessful();

    $media = Media::query()->firstOrFail();
    $html = (string) TenantContextService::withTenant($this->workspace->getKey(), fn (): mixed => $this->note->refresh()->getCustomFieldValue($this->body));

    expect($media->model_id)->toBe($this->note->getKey())
        ->and($media->collection_name)->toBe(MediaCollection::forCustomField('body'))
        ->and($media->getCustomProperty('workspace_id'))->toBe($this->workspace->getKey())
        ->and($html)->toContain("data-id=\"{$media->uuid}\"")
        ->and($html)->toContain('src="'.e($media->getUrl()).'"')
        ->and($html)->toContain('alt="old"')
        ->and($html)->not->toContain('data-id="legacy.png"')
        ->and($html)->not->toContain('storage/legacy.png')
        ->and(Storage::disk('public')->exists('legacy.png'))->toBeTrue()
        ->and(Activity::query()->count())->toBe($activities);
});

it('is idempotent', function (): void {
    $this->artisan('media:backfill-rich-editor-attachments --force')->assertSuccessful();

    $this->artisan('media:backfill-rich-editor-attachments --force')
        ->expectsOutputToContain('0 image(s) migrated.')
        ->assertSuccessful();

    expect(Media::query()->count())->toBe(1);
});

it('skips an image whose file is gone from the public disk', function (): void {
    Storage::disk('public')->delete('legacy.png');

    $this->artisan('media:backfill-rich-editor-attachments --force')
        ->expectsOutputToContain('legacy.png is missing on the public disk, skipped.')
        ->expectsOutputToContain('0 image(s) migrated.')
        ->assertSuccessful();

    expect(Media::query()->count())->toBe(0);
});
