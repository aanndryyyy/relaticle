<?php

declare(strict_types=1);

namespace App\Support\Media;

use App\Enums\CustomFieldType;
use App\Models\CustomFieldValue;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class MediaPaths
{
    private const string PATH_PATTERN = '#^uploads/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/[^/]+$#';

    /** @var array<string, Media|null> */
    private array $byPath = [];

    /** @var array<string, Media|null> */
    private array $byUuid = [];

    /** @param iterable<Model> $models */
    public function prime(iterable $models): void
    {
        $paths = [];
        $uuids = [];

        foreach ($models as $model) {
            if (! $model->relationLoaded('customFieldValues')) {
                continue;
            }

            foreach ($model->getRelation('customFieldValues') as $fieldValue) {
                if (! $fieldValue instanceof CustomFieldValue || ! isset($fieldValue->getRelations()['customField'])) {
                    continue;
                }

                $value = $fieldValue->getValue();

                if (! is_string($value)) {
                    continue;
                }

                $workspaceId = (string) $fieldValue->getAttribute('tenant_id');

                if ($fieldValue->customField->type === CustomFieldType::RICH_EDITOR->value) {
                    $uuids[$workspaceId] = [...($uuids[$workspaceId] ?? []), ...$this->imageUuids($value)];

                    continue;
                }

                if ($fieldValue->customField->type !== CustomFieldType::FILE_UPLOAD->value) {
                    continue;
                }

                $uuid = $this->uuidFromPath($value);

                if ($uuid !== null) {
                    $paths[$workspaceId][$value] = $uuid;
                    $uuids[$workspaceId][] = $uuid;
                }
            }
        }

        foreach ($uuids as $workspaceId => $wanted) {
            $missing = array_values(array_unique(array_filter(
                $wanted,
                fn (string $uuid): bool => ! array_key_exists($this->uuidKey($workspaceId, $uuid), $this->byUuid),
            )));

            if ($missing !== []) {
                $found = Media::query()
                    ->where('custom_properties->workspace_id', $workspaceId)
                    ->whereIn('uuid', $missing)
                    ->get()
                    ->keyBy('uuid');

                foreach ($missing as $uuid) {
                    $this->byUuid[$this->uuidKey($workspaceId, $uuid)] = $found->get($uuid);
                }
            }

            foreach ($paths[$workspaceId] ?? [] as $path => $uuid) {
                $media = $this->byUuid[$this->uuidKey($workspaceId, $uuid)];

                $this->byPath[$this->pathKey($workspaceId, $path)] = $media instanceof Media && $media->getPathRelativeToRoot() === $path
                    ? $media
                    : null;
            }
        }
    }

    /** @return list<string> */
    public function imageUuids(string $html): array
    {
        preg_match_all('/data-id="([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})"/', $html, $matches);

        return array_values(array_unique($matches[1]));
    }

    public function uuidFromPath(string $path): ?string
    {
        return preg_match(self::PATH_PATTERN, $path, $matches) === 1 ? $matches[1] : null;
    }

    public function find(string $workspaceId, string $path): ?Media
    {
        $key = $this->pathKey($workspaceId, $path);

        if (array_key_exists($key, $this->byPath)) {
            return $this->byPath[$key];
        }

        $uuid = $this->uuidFromPath($path);

        if ($uuid === null) {
            $this->byPath[$key] = null;

            return null;
        }

        $media = $this->findByUuid($workspaceId, $uuid);

        if (! $media instanceof Media || $media->getPathRelativeToRoot() !== $path) {
            $this->byPath[$key] = null;

            return null;
        }

        $this->byPath[$key] = $media;

        return $media;
    }

    public function findByUuid(string $workspaceId, string $uuid): ?Media
    {
        $key = $this->uuidKey($workspaceId, $uuid);

        if (array_key_exists($key, $this->byUuid)) {
            return $this->byUuid[$key];
        }

        $media = Media::query()
            ->where('uuid', $uuid)
            ->where('custom_properties->workspace_id', $workspaceId)
            ->first();

        $this->byUuid[$key] = $media;

        return $media;
    }

    private function pathKey(string $workspaceId, string $path): string
    {
        return $workspaceId.':'.$path;
    }

    private function uuidKey(string $workspaceId, string $uuid): string
    {
        return $workspaceId.':'.$uuid;
    }
}
