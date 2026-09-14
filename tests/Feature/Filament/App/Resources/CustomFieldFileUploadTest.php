<?php

declare(strict_types=1);

use App\Actions\Upload\DiscardPendingUpload;
use App\Enums\MediaCollection;
use App\Filament\CustomFields\FileColumn;
use App\Filament\CustomFields\FileEntry;
use App\Filament\CustomFields\FileUploadComponent;
use App\Filament\CustomFields\FileUploadFieldType;
use App\Filament\Resources\CompanyResource\Pages\ViewCompany;
use App\Filament\Resources\NoteResource\Pages\ManageNotes;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Note;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Schemas\Components\Component;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

mutates(FileUploadFieldType::class, FileUploadComponent::class, FileEntry::class, FileColumn::class, DiscardPendingUpload::class);

beforeEach(function (): void {
    Storage::fake('local');
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);
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
});

it('stores a panel upload as pending media and claims it when the note is created', function (): void {
    livewire(ManageNotes::class)
        ->callAction('create', [
            'title' => 'With contract',
            'custom_fields' => ['contract' => UploadedFile::fake()->createWithContent('contract.pdf', pdfBytes())],
        ])
        ->assertHasNoActionErrors();

    $note = Note::query()->where('title', 'With contract')->with('customFieldValues.customField')->firstOrFail();
    $media = Media::query()->where('collection_name', MediaCollection::Attachments->value)->firstOrFail();

    expect($note->getCustomFieldValue($this->contract))->toBe($media->uuid)
        ->and($media->model_id)->toBe($note->getKey())
        ->and($media->custom_field_id)->toBe($this->contract->getKey())
        ->and($media->name)->toBe('contract.pdf');
    Storage::disk('local')->assertExists($media->getPathRelativeToRoot());
});

it('rejects an svg through the accepted file types', function (): void {
    livewire(ManageNotes::class)
        ->callAction('create', [
            'title' => 'With svg',
            'custom_fields' => ['contract' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>')],
        ])
        ->assertHasActionErrors(['custom_fields.contract']);

    expect(Note::query()->where('title', 'With svg')->exists())->toBeFalse();
});

it('clears a failed upload error after a successful retry', function (): void {
    $test = livewire(ManageNotes::class)->mountAction('create');
    $schemaName = $test->instance()->getMountedActionSchemaName();
    $schema = $test->instance()->{$schemaName};
    $component = $schema->getComponent(fn (Component|Action|ActionGroup $component): bool => $component instanceof FileUpload
        && $component->getName() === $this->contract->getFieldName());
    $statePath = $component->getStatePath();
    $livewire = $test->instance();
    $failedUploadPath = "{$statePath}.upload-key";
    $livewire->addError($failedUploadPath, "The {$failedUploadPath} failed to upload.");

    $component->callAfterStateUpdated();

    expect($livewire->getErrorBag()->has($failedUploadPath))->toBeFalse();
});

it('shows the original file name, not the storage name, as a link on the record', function (): void {
    $note = Note::factory()->recycle([$this->user, $this->workspace])->create();
    $media = $this->workspace->addMediaFromString(pdfBytes())
        ->usingFileName('01ARZ3NDEKTSV4RRFFQ69G5FAV.pdf')
        ->withAttributes(['workspace_id' => $this->workspace->getKey()])->usingName('Contract v2.pdf')
        ->toMediaCollection(MediaCollection::PendingUploads->value);
    $note->saveCustomFieldValue($this->contract, $media->uuid);

    livewire(ManageNotes::class)
        ->assertSee('Contract v2.pdf')
        ->assertSee($media->refresh()->getUrl());
});

it('shows the original file name through a real infolist entry', function (): void {
    $companyField = CustomField::factory()->create([
        'tenant_id' => $this->workspace->getKey(),
        'entity_type' => 'company',
        'code' => 'attachment',
        'name' => 'Attachment',
        'type' => 'file-upload',
        'validation_rules' => [],
        'active' => true,
        'system_defined' => false,
    ]);
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $media = $this->workspace->addMediaFromString(pdfBytes())
        ->usingFileName('01ARZ3NDEKTSV4RRFFQ69G5FAV.pdf')
        ->withAttributes(['workspace_id' => $this->workspace->getKey()])->usingName('Master Agreement.pdf')
        ->toMediaCollection(MediaCollection::PendingUploads->value);
    $company->saveCustomFieldValue($companyField, $media->uuid);

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->assertOk()
        ->assertSee('Master Agreement.pdf')
        ->assertSee($media->refresh()->getUrl());
});

it('releases the file when it is removed from the form', function (): void {
    $note = Note::factory()->recycle([$this->user, $this->workspace])->create();
    $media = $this->workspace->addMediaFromString(pdfBytes())
        ->usingFileName('01ARZ3NDEKTSV4RRFFQ69G5FAV.pdf')
        ->withAttributes(['workspace_id' => $this->workspace->getKey()])
        ->toMediaCollection(MediaCollection::PendingUploads->value);
    $note->saveCustomFieldValue($this->contract, $media->uuid);

    livewire(ManageNotes::class)
        ->callAction(TestAction::make('edit')->table($note), ['custom_fields' => ['contract' => null]])
        ->assertHasNoActionErrors();

    expect(Media::query()->find($media->getKey()))->toBeNull()
        ->and($note->fresh('customFieldValues.customField')->getCustomFieldValue($this->contract))->toBeNull();
});

it('resolves the original file name and url through getUploadedFileUsing', function (): void {
    $note = Note::factory()->recycle([$this->user, $this->workspace])->create();
    $media = $this->workspace->addMediaFromString(pdfBytes())
        ->usingFileName('01ARZ3NDEKTSV4RRFFQ69G5FAV.pdf')
        ->withAttributes(['workspace_id' => $this->workspace->getKey()])->usingName('Signed Contract.pdf')
        ->toMediaCollection(MediaCollection::PendingUploads->value);
    $note->saveCustomFieldValue($this->contract, $media->uuid);

    $test = livewire(ManageNotes::class)->mountAction(TestAction::make('edit')->table($note));

    $schemaName = $test->instance()->getMountedActionSchemaName();
    $schema = $test->instance()->{$schemaName};
    $component = $schema->getComponent(fn (Component|Action|ActionGroup $component): bool => $component instanceof FileUpload
        && $component->getName() === $this->contract->getFieldName());

    $files = $test->instance()->callSchemaComponentMethod($component->getKey(), 'getUploadedFiles');

    $file = array_values($files)[0];

    expect($file['name'])->toBe('Signed Contract.pdf')
        ->and($file['url'])->toBe($media->refresh()->getUrl());
});

it('deletes a pending upload but leaves a claimed one alone', function (): void {
    $pending = $this->workspace->addMediaFromString(pdfBytes())
        ->usingFileName('pending.pdf')
        ->withAttributes(['workspace_id' => $this->workspace->getKey()])
        ->toMediaCollection(MediaCollection::PendingUploads->value);

    $note = Note::factory()->recycle([$this->user, $this->workspace])->create();
    $claimed = $this->workspace->addMediaFromString(pdfBytes())
        ->usingFileName('claimed.pdf')
        ->withAttributes(['workspace_id' => $this->workspace->getKey()])
        ->toMediaCollection(MediaCollection::PendingUploads->value);
    $note->saveCustomFieldValue($this->contract, $claimed->uuid);

    resolve(DiscardPendingUpload::class)->execute($this->user, $this->workspace, $pending->uuid);
    resolve(DiscardPendingUpload::class)->execute($this->user, $this->workspace, $claimed->uuid);

    expect(Media::query()->find($pending->getKey()))->toBeNull()
        ->and(Media::query()->find($claimed->getKey()))->not->toBeNull();
});

it('rejects a pasted file id claimed by another record on the same field', function (): void {
    $noteA = Note::factory()->recycle([$this->user, $this->workspace])->create();
    $noteB = Note::factory()->recycle([$this->user, $this->workspace])->create();
    $media = $this->workspace->addMediaFromString(pdfBytes())
        ->usingFileName('01ARZ3NDEKTSV4RRFFQ69G5FAV.pdf')
        ->withAttributes(['workspace_id' => $this->workspace->getKey()])
        ->toMediaCollection(MediaCollection::PendingUploads->value);
    $noteA->saveCustomFieldValue($this->contract, $media->uuid);

    livewire(ManageNotes::class)
        ->callAction(TestAction::make('edit')->table($noteB), ['custom_fields' => ['contract' => [(string) Str::uuid() => $media->uuid]]])
        ->assertHasActionErrors(['custom_fields.contract']);
});

it('allows re-saving a record with its own currently claimed file id', function (): void {
    $note = Note::factory()->recycle([$this->user, $this->workspace])->create();
    $media = $this->workspace->addMediaFromString(pdfBytes())
        ->usingFileName('01ARZ3NDEKTSV4RRFFQ69G5FAV.pdf')
        ->withAttributes(['workspace_id' => $this->workspace->getKey()])
        ->toMediaCollection(MediaCollection::PendingUploads->value);
    $note->saveCustomFieldValue($this->contract, $media->uuid);

    livewire(ManageNotes::class)
        ->callAction(TestAction::make('edit')->table($note), ['custom_fields' => ['contract' => [(string) Str::uuid() => $media->uuid]]])
        ->assertHasNoActionErrors();
});
