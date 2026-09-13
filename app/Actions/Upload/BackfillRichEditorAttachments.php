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
    private const string IMAGE = '/<img\b[^>]*>/i';

    private const string LEGACY_ID = '/\bdata-id="(?![0-9a-f]{8}-[0-9a-f]{4}-)([A-Za-z0-9][A-Za-z0-9._-]*)"/i';

    private const string PUBLIC_DISK_SRC = '#\bsrc="[^"]*/storage/([A-Za-z0-9][A-Za-z0-9._-]*)"#i';

    /** @return array{migrated: list<string>, skipped: list<string>} */
    public function execute(bool $write): array
    {
        $migrated = [];
        $skipped = [];
        $public = Storage::disk('public');

        $values = CustomFieldValue::query()
            ->withoutGlobalScopes()
            ->whereHas('customField', fn (Builder $query): Builder => $query->withoutGlobalScopes()->where('type', CustomFieldType::RICH_EDITOR->value))
            ->where('text_value', 'like', '%<img%')
            ->with(['entity', 'customField' => fn (Relation $query): Relation => $query->withoutGlobalScopes()])
            ->get();

        foreach ($values as $value) {
            $html = (string) $value->text_value;

            $legacy = [];

            preg_match_all(self::IMAGE, $html, $tags);

            foreach ($tags[0] as $tag) {
                $legacyPath = $this->legacyPath($tag);

                if ($legacyPath !== null) {
                    $legacy[$tag] = $legacyPath;
                }
            }

            if ($legacy === []) {
                continue;
            }

            $entity = $value->entity;

            if (! $entity instanceof HasMedia) {
                $skipped[] = "Value {$value->getKey()}: record is missing, skipped.";

                continue;
            }

            foreach ($legacy as $tag => $legacyPath) {
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

                $rewritten = (string) preg_replace(['/\ssrc="[^"]*"/i', '/\sdata-id="[^"]*"/i'], '', $tag);
                $html = str_replace($tag, '<img src="'.e($media->getUrl()).'" data-id="'.$media->uuid.'"'.substr($rewritten, 4), $html);
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

    private function legacyPath(string $tag): ?string
    {
        if (str_contains($tag, 'data-id=')) {
            return preg_match(self::LEGACY_ID, $tag, $match) === 1 ? $match[1] : null;
        }

        return preg_match(self::PUBLIC_DISK_SRC, $tag, $match) === 1 ? $match[1] : null;
    }
}
