<?php

declare(strict_types=1);

namespace App\Filament\CustomFields;

use App\Filament\CustomFields\Concerns\ResolvesFileMedia;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;
use Relaticle\CustomFields\Filament\Integration\Base\AbstractTableColumn;
use Relaticle\CustomFields\Filament\Integration\Concerns\Tables\ConfiguresColumnLabel;
use Relaticle\CustomFields\Models\Contracts\HasCustomFields;
use Relaticle\CustomFields\Models\CustomField;

final class FileColumn extends AbstractTableColumn
{
    use ConfiguresColumnLabel;
    use ResolvesFileMedia;

    public function make(CustomField $customField): TextColumn
    {
        $column = TextColumn::make($customField->getFieldName());

        $this->configureLabel($column, $customField);

        return $column
            ->getStateUsing(fn (HasCustomFields&Model $record): ?string => $this->label($this->resolveMedia($record, $customField)))
            ->url(fn (HasCustomFields&Model $record): ?string => $this->resolveMedia($record, $customField)?->getUrl())
            ->openUrlInNewTab();
    }
}
