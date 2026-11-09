<?php

namespace App\Enums;

/**
 * How a rule compares its value to its threshold.
 */
enum AlertCondition: string
{
    case GreaterThan = '>';
    case GreaterThanOrEqual = '>=';
    case LessThan = '<';
    case LessThanOrEqual = '<=';

    /**
     * Whether the value crosses the threshold.
     */
    public function matches(float $value, float $threshold): bool
    {
        return match ($this) {
            self::GreaterThan => $value > $threshold,
            self::GreaterThanOrEqual => $value >= $threshold,
            self::LessThan => $value < $threshold,
            self::LessThanOrEqual => $value <= $threshold,
        };
    }

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
