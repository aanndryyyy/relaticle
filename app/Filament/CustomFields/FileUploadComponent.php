<?php

declare(strict_types=1);

namespace App\Filament\CustomFields;

use App\Actions\Upload\DiscardPendingUpload;
use App\Actions\Upload\StorePendingUpload;
use App\Enums\UploadSource;
use App\Exceptions\UploadException;
use App\Models\User;
use App\Models\Workspace;
use App\Rules\OwnedUpload;
use App\Support\Media\MediaLookup;
use App\Support\Media\UploadAllowlist;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Relaticle\CustomFields\Filament\Integration\Base\AbstractFormComponent;
use Relaticle\CustomFields\Models\CustomField;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final readonly class FileUploadComponent extends AbstractFormComponent
{
    public function create(CustomField $customField): FileUpload
    {
        return FileUpload::make($customField->getFieldName())
            ->acceptedFileTypes(array_keys(UploadAllowlist::MIME_TYPES))
            ->maxSize((int) (UploadAllowlist::maxBytes() / 1024))
            ->downloadable()
            ->openable()
            ->previewable()
            ->preventFilePathTampering(allowFilePathUsing: fn (string $file, FileUpload $component): bool => $this->isOwned($file, $component, $customField))
            ->afterStateUpdated(function (FileUpload $component, Component $livewire): void {
                $livewire->resetValidation($component->getStatePath().'.*');
            })
            ->saveUploadedFileUsing(fn (TemporaryUploadedFile $file, FileUpload $component): string => $this->store($file, $component))
            ->getUploadedFileUsing(fn (string $file): ?array => $this->describe($file))
            ->deleteUploadedFileUsing(fn (string $file): null => $this->discardPending($file));
    }

    private function store(TemporaryUploadedFile $file, FileUpload $component): string
    {
        $user = auth()->user();
        $workspace = $this->workspace();

        abort_unless($user instanceof User && $workspace instanceof Workspace, 403);

        try {
            return resolve(StorePendingUpload::class)
                ->execute($user, $workspace, $file->getRealPath(), $file->getClientOriginalName(), UploadSource::Panel)
                ->uuid;
        } catch (UploadException $exception) {
            throw ValidationException::withMessages([$component->getStatePath() => $exception->getMessage()]);
        }
    }

    /** @return array{name: string, size: int, type: ?string, url: ?string}|null */
    private function describe(string $file): ?array
    {
        $workspace = $this->workspace();
        $media = $workspace instanceof Workspace
            ? resolve(MediaLookup::class)->find((string) $workspace->getKey(), $file)
            : null;

        if (! $media instanceof Media) {
            return null;
        }

        return [
            'name' => $media->name,
            'size' => (int) $media->size,
            'type' => $media->mime_type,
            'url' => $media->getUrl(),
        ];
    }

    private function discardPending(string $file): null
    {
        $user = auth()->user();
        $workspace = $this->workspace();

        if ($user instanceof User && $workspace instanceof Workspace) {
            resolve(DiscardPendingUpload::class)->execute($user, $workspace, $file);
        }

        return null;
    }

    private function isOwned(string $file, FileUpload $component, CustomField $customField): bool
    {
        $workspace = $this->workspace();

        if (! $workspace instanceof Workspace) {
            return false;
        }

        $record = $component->getRecord();
        $entityId = $record instanceof Model ? (string) $record->getKey() : null;

        return Validator::make(
            ['file' => $file],
            ['file' => [new OwnedUpload((string) $workspace->getKey(), $customField->entity_type, $customField, $entityId)]],
        )->passes();
    }

    private function workspace(): ?Workspace
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Workspace ? $tenant : null;
    }
}
