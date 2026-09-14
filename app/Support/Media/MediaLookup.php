<?php

declare(strict_types=1);

namespace App\Support\Media;

use App\Enums\CustomFieldType;
use App\Models\CustomFieldValue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class MediaLookup
{
    private const string IMAGE_ID = '/data-id="([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})"/';

    /** @var array<string, Media|null> */
    private array $byUuid = [];

    /** @param iterable<Model> $models */
    public function prime(iterable $models): void
    {
        $uuids = [];

        foreach ($models as $model) {
            if (! $model->relationLoaded('customFieldValues')) {
                continue;
            }

            foreach ($model->getRelation('customFieldValues') as $fieldValue) {
                if (! $fieldValue instanceof CustomFieldValue || ! $fieldValue->relationLoaded('customField')) {
                    continue;
                }

                array_push($uuids, ...$this->referencedUuids($fieldValue->customField->type, $fieldValue->getValue()));
            }
        }

        $missing = array_filter(array_unique($uuids), fn (string $uuid): bool => ! array_key_exists($uuid, $this->byUuid));

        if ($missing === []) {
            return;
        }

        $found = Media::query()->whereIn('uuid', $missing)->get()->keyBy('uuid');

        foreach ($missing as $uuid) {
            $this->byUuid[$uuid] = $found->get($uuid);
        }
    }

    public function find(string $workspaceId, string $uuid): ?Media
    {
        if (! array_key_exists($uuid, $this->byUuid)) {
            $this->byUuid[$uuid] = Str::isUuid($uuid) ? Media::query()->where('uuid', $uuid)->first() : null;
        }

        $media = $this->byUuid[$uuid];

        return $media?->workspace_id === $workspaceId ? $media : null;
    }

    /** @return list<string> */
    public function referencedUuids(string $fieldType, mixed $value): array
    {
        if (! is_string($value)) {
            return [];
        }

        return match ($fieldType) {
            CustomFieldType::FILE_UPLOAD->value => Str::isUuid($value) ? [$value] : [],
            CustomFieldType::RICH_EDITOR->value => $this->imageUuids($value),
            default => [],
        };
    }

    /** @return list<string> */
    public function imageUuids(string $html): array
    {
        preg_match_all(self::IMAGE_ID, $html, $matches);

        return array_values(array_unique($matches[1]));
    }
}
