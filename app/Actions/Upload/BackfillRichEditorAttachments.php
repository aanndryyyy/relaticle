<?php

declare(strict_types=1);

namespace App\Actions\Upload;

use App\Enums\CustomFieldType;
use App\Enums\MediaCollection;
use App\Enums\UploadSource;
use App\Models\CustomFieldValue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\HasMedia;

final readonly class BackfillRichEditorAttachments
{
    private const string LEGACY_IMAGE = '/<img\b[^>]*\bdata-id="(?![0-9a-f]{8}-[0-9a-f]{4}-)([A-Za-z0-9][A-Za-z0-9._-]*)"[^>]*>/i';

    /** @return array{migrated: list<string>, skipped: list<string>} */
    public function execute(bool $write): array
    {
        $migrated = [];
        $skipped = [];
        $public = Storage::disk('public');

        $values = CustomFieldValue::query()
            ->withoutGlobalScopes()
            ->whereHas('customField', fn (Builder $query): Builder => $query->withoutGlobalScopes()->where('type', CustomFieldType::RICH_EDITOR->value))
            ->where('text_value', 'like', '%data-id=%')
            ->with(['customField' => fn (Relation $query): Relation => $query->withoutGlobalScopes()])
            ->get();

        foreach ($values as $value) {
            $html = (string) $value->text_value;

            if (preg_match_all(self::LEGACY_IMAGE, $html, $matches, PREG_SET_ORDER) === 0) {
                continue;
            }

            $entity = $value->entity;

            if (! $entity instanceof HasMedia) {
                $skipped[] = "Value {$value->getKey()}: record is missing, skipped.";

                continue;
            }

            foreach ($matches as $match) {
                $legacyPath = $match[1];

                if (! $public->exists($legacyPath)) {
                    $skipped[] = "Value {$value->getKey()}: {$legacyPath} is missing on the public disk, skipped.";

                    continue;
                }

                $migrated[] = "Value {$value->getKey()}: {$legacyPath}";

                if (! $write) {
                    continue;
                }

                $media = $entity->addMediaFromDisk($legacyPath, 'public')
                    ->preservingOriginal()
                    ->usingName(pathinfo($legacyPath, PATHINFO_FILENAME))
                    ->withCustomProperties([
                        'workspace_id' => $value->getAttribute('tenant_id'),
                        'source' => UploadSource::Panel->value,
                        'original_name' => basename($legacyPath),
                    ])
                    ->toMediaCollection(MediaCollection::forCustomField($value->customField->code));

                $tag = (string) preg_replace('/\ssrc="[^"]*"/i', '', $match[0]);
                $tag = str_replace("data-id=\"{$legacyPath}\"", "data-id=\"{$media->uuid}\"", $tag);
                $html = str_replace($match[0], '<img src="'.e($media->getUrl()).'"'.substr($tag, 4), $html);
            }

            if (! $write || $html === (string) $value->text_value) {
                continue;
            }

            $value->text_value = $html;

            activity()->disableLogging();

            try {
                $value->save();
            } finally {
                activity()->enableLogging();
            }
        }

        return ['migrated' => $migrated, 'skipped' => $skipped];
    }
}
