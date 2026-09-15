<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final readonly class ChatAttachment
{
    public function __construct(public Media $media) {}

    /**
     * Scoped on the media row's own workspace_id column, as every media query
     * is; the uploader property narrows it further, since an attachment is
     * private to the person who sent it.
     *
     * @return Builder<Media>
     */
    public static function query(Workspace $workspace, User $user): Builder
    {
        return Media::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('collection_name', Workspace::CHAT_ATTACHMENTS_MEDIA_COLLECTION)
            ->where('custom_properties->uploaded_by', (string) $user->getKey());
    }

    public static function find(Workspace $workspace, User $user, string $id): ?self
    {
        $media = self::query($workspace, $user)->where('uuid', $id)->first();

        return $media instanceof Media ? new self($media) : null;
    }

    public function id(): string
    {
        return (string) $this->media->uuid;
    }

    public function name(): string
    {
        return (string) $this->media->getCustomProperty('original_name', $this->media->file_name);
    }

    public function rowCount(): int
    {
        return (int) $this->media->getCustomProperty('row_count', 0);
    }

    /** @return list<string> */
    public function header(): array
    {
        $header = $this->media->getCustomProperty('header', []);

        return is_array($header) ? array_values(array_map(strval(...), $header)) : [];
    }

    public function conversationId(): ?string
    {
        $id = $this->media->getCustomProperty('conversation_id');

        return is_string($id) ? $id : null;
    }

    public function isConsumed(): bool
    {
        return $this->media->getCustomProperty('consumed_at') !== null;
    }

    public function importIdFor(ImportEntityType $type): ?string
    {
        $id = $this->media->getCustomProperty("imports.{$type->value}");

        return is_string($id) ? $id : null;
    }

    public function absolutePath(): string
    {
        return $this->media->getPath();
    }

    public function fileExists(): bool
    {
        return Storage::disk($this->media->disk)->exists($this->media->getPathRelativeToRoot());
    }

    /** @return array{id: string, name: string, row_count: int} */
    public function meta(): array
    {
        return ['id' => $this->id(), 'name' => $this->name(), 'row_count' => $this->rowCount()];
    }
}
