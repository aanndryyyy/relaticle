<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

use Illuminate\Database\Query\Builder;
use Relaticle\Chat\Enums\MessageOrigin;

final readonly class TypedMessages
{
    public static function apply(Builder $query, ?string $alias = null): Builder
    {
        $prefix = $alias === null ? '' : "{$alias}.";

        return $query
            ->where("{$prefix}role", 'user')
            ->where("{$prefix}origin", MessageOrigin::Typed->value);
    }

    public static function exceptSynthetic(Builder $query, ?string $alias = null): Builder
    {
        $prefix = $alias === null ? '' : "{$alias}.";

        return $query->where(function (Builder $visible) use ($prefix): void {
            $visible
                ->where("{$prefix}role", '<>', 'user')
                ->orWhere("{$prefix}origin", MessageOrigin::Typed->value);
        });
    }
}
