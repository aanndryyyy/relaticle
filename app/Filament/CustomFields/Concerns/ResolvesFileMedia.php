<?php

declare(strict_types=1);

namespace App\Filament\CustomFields\Concerns;

use App\Support\Media\MediaPaths;
use Illuminate\Database\Eloquent\Model;
use Relaticle\CustomFields\Models\Contracts\HasCustomFields;
use Relaticle\CustomFields\Models\CustomField;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

trait ResolvesFileMedia
{
    private function resolveMedia(HasCustomFields&Model $record, CustomField $customField): ?Media
    {
        $value = $record->getCustomFieldValue($customField);

        if (! is_string($value)) {
            return null;
        }

        return resolve(MediaPaths::class)->find((string) $record->getAttribute('workspace_id'), $value);
    }

    private function label(?Media $media): ?string
    {
        return $media?->getCustomProperty('original_name', $media->file_name);
    }
}
