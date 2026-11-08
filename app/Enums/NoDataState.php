<?php

namespace App\Enums;

/**
 * What a known instance with no value this evaluation counts as (Grafana's
 * "no data" handling): the condition false, the condition true, or nothing at all.
 */
enum NoDataState: string
{
    case Inactive = 'inactive';
    case Firing = 'firing';
    case KeepLast = 'keep_last';

    /**
     * All enum values.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
