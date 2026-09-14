<?php

declare(strict_types=1);

namespace App\Support\Media;

use App\Actions\Upload\StorePendingUpload;
use App\Enums\UploadSource;
use App\Exceptions\UploadException;
use App\Models\User;
use App\Models\Workspace;
use Dom\HTMLDocument;
use Filament\Forms\Components\RichEditor\FileAttachmentProviders\Contracts\FileAttachmentProvider;
use Filament\Forms\Components\RichEditor\RichContentAttribute;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final readonly class RichContentAttachments implements FileAttachmentProvider
{
    // Filament's default flow stored the bare public-disk filename as data-id. Those bodies
    // keep rendering until media:backfill-rich-editor-attachments has rewritten them.
    private const string LEGACY_FILENAME = '/^[A-Za-z0-9][A-Za-z0-9._-]*$/';

    private function __construct(private string $workspaceId, private MediaLookup $lookup) {}

    public static function forWorkspace(string $workspaceId): self
    {
        return new self($workspaceId, resolve(MediaLookup::class));
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

        if (Str::isUuid($file)) {
            return $this->lookup->find($this->workspaceId, $file)?->getUrl();
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

    public function tagOwnedAttachments(string $html): string
    {
        $document = HTMLDocument::createFromString('<body>'.$html, LIBXML_NOERROR, 'UTF-8');

        foreach ($document->querySelectorAll('img:not([data-id])') as $image) {
            $uuid = $this->lookup->uuidFromUrl($image->getAttribute('src') ?? '');

            if ($uuid !== null && $this->lookup->find($this->workspaceId, $uuid) instanceof Media) {
                $image->setAttribute('data-id', $uuid);
            }
        }

        return $this->bodyHtml($document);
    }

    public function rewriteAttachmentUrls(string $html): string
    {
        $document = HTMLDocument::createFromString('<body>'.$html, LIBXML_NOERROR, 'UTF-8');

        foreach ($document->querySelectorAll('img[data-id], a[href]') as $element) {
            $isImage = $element->localName === 'img';
            $uuid = $isImage
                ? $element->getAttribute('data-id')
                : $this->lookup->uuidFromUrl($element->getAttribute('href') ?? '');
            $url = $this->getFileAttachmentUrl($uuid);

            if ($url !== null) {
                $fragment = $isImage ? null : parse_url($element->getAttribute('href') ?? '', PHP_URL_FRAGMENT);

                if (is_string($fragment)) {
                    $url .= '#'.$fragment;
                }

                $element->setAttribute($isImage ? 'src' : 'href', $url);
            }
        }

        return $this->bodyHtml($document);
    }

    private function bodyHtml(HTMLDocument $document): string
    {
        $html = '';

        foreach ($document->body->childNodes as $node) {
            $html .= $document->saveHtml($node);
        }

        return $html;
    }
}
