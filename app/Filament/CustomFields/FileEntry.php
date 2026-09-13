<?php

declare(strict_types=1);

namespace App\Filament\CustomFields;

use App\Filament\CustomFields\Concerns\ResolvesFileMedia;
use Filament\Infolists\Components\TextEntry;
use Illuminate\Database\Eloquent\Model;
use Relaticle\CustomFields\Filament\Integration\Base\AbstractInfolistEntry;
use Relaticle\CustomFields\Models\Contracts\HasCustomFields;
use Relaticle\CustomFields\Models\CustomField;

final class FileEntry extends AbstractInfolistEntry
{
    use ResolvesFileMedia;

    public function make(CustomField $customField): TextEntry
    {
        return TextEntry::make($customField->getFieldName())
            ->label($customField->name)
            ->state(fn (HasCustomFields&Model $record): ?string => $this->label($this->resolveMedia($record, $customField)))
            ->url(fn (HasCustomFields&Model $record): ?string => $this->resolveMedia($record, $customField)?->getUrl())
            ->openUrlInNewTab();
    }
}
