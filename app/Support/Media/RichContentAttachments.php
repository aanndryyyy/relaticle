<?php

declare(strict_types=1);

namespace App\Support\Media;

use App\Actions\Upload\StorePendingUpload;
use App\Enums\UploadSource;
use App\Exceptions\UploadException;
use App\Models\User;
use App\Models\Workspace;
use Filament\Forms\Components\RichEditor\FileAttachmentProviders\Contracts\FileAttachmentProvider;
use Filament\Forms\Components\RichEditor\RichContentAttribute;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final readonly class RichContentAttachments implements FileAttachmentProvider
{
    private const string UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    // Filament's default flow stored the bare public-disk filename as data-id. Those bodies
    // keep rendering until media:backfill-rich-editor-attachments has rewritten them.
    private const string LEGACY_FILENAME = '/^[A-Za-z0-9][A-Za-z0-9._-]*$/';

    private const string TAGGED_IMAGE = '/<img\b[^>]*\bdata-id="('.self::UUID.')"[^>]*>/i';

    private const string UNTAGGED_IMAGE = '/<img\b(?![^>]*\bdata-id=)[^>]*\bsrc="([^"]*)"[^>]*>/i';

    private const string OWNED_URL = '#/(?:uploads|media)/('.self::UUID.')(?:/|\?|$)#';

    private function __construct(private string $workspaceId, private MediaPaths $paths) {}

    public static function forWorkspace(string $workspaceId): self
    {
        return new self($workspaceId, resolve(MediaPaths::class));
    }

    public function attribute(RichContentAttribute $attribute): static
    {
        return $this;
    }

    public function getFileAttachmentUrl(mixed $file): ?string
    {
        if (! is_string($file) || $file === '') {
            return null;
        }

        if (preg_match('/^'.self::UUID.'$/', $file) === 1) {
            return $this->paths->findByUuid($this->workspaceId, $file)?->getUrl();
        }

        if (preg_match(self::LEGACY_FILENAME, $file) !== 1) {
            return null;
        }

        $disk = Storage::disk('public');

        return $disk->exists($file) ? $disk->url($file) : null;
    }

    public function saveUploadedFileAttachment(TemporaryUploadedFile $file): string
    {
        $user = auth()->user();
        $workspace = Workspace::query()->find($this->workspaceId);

        abort_unless($user instanceof User && $workspace instanceof Workspace, 403);

        try {
            return resolve(StorePendingUpload::class)
                ->execute($user, $workspace, $file->getRealPath(), $file->getClientOriginalName(), UploadSource::Panel)
                ->uuid;
        } catch (UploadException $exception) {
            throw ValidationException::withMessages(['attachment' => $exception->getMessage()]);
        }
    }

    public function getDefaultFileAttachmentVisibility(): ?string
    {
        return null;
    }

    public function isExistingRecordRequiredToSaveNewFileAttachments(): bool
    {
        return false;
    }

    /** @param array<mixed> $exceptIds */
    public function cleanUpFileAttachments(array $exceptIds): void
    {
        // UploadClaims releases dropped images when the value is saved; a per-editor
        // cleanup would delete another member's pending draft image in the same workspace.
    }

    public function tagOwnedImages(string $html): string
    {
        return (string) preg_replace_callback(self::UNTAGGED_IMAGE, function (array $match): string {
            if (preg_match(self::OWNED_URL, $match[1], $url) !== 1) {
                return $match[0];
            }

            if (! $this->paths->findByUuid($this->workspaceId, $url[1]) instanceof Media) {
                return $match[0];
            }

            return '<img data-id="'.$url[1].'"'.substr($match[0], 4);
        }, $html);
    }

    public function rewriteImageSources(string $html): string
    {
        return (string) preg_replace_callback(self::TAGGED_IMAGE, function (array $match): string {
            $url = $this->getFileAttachmentUrl($match[1]);

            if ($url === null) {
                return $match[0];
            }

            $tag = (string) preg_replace('/\ssrc="[^"]*"/i', '', $match[0]);

            return '<img src="'.e($url).'"'.substr($tag, 4);
        }, $html);
    }
}
