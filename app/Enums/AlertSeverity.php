<?php

namespace App\Enums;

/**
 * How loud a rule says it is. Carried into notifications only; it changes
 * nothing about evaluation.
 */
enum AlertSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';

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
