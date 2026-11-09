<?php

namespace App\Enums;

/**
 * Where one alert instance stands, with Prometheus' three states.
 *
 * Only the edges into and out of `Firing` are notified; `Pending` is the `for`
 * wait and is never announced.
 */
enum AlertState: string
{
    case Inactive = 'inactive';
    case Pending = 'pending';
    case Firing = 'firing';

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
