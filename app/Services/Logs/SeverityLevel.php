<?php

namespace App\Services\Logs;

/**
 * The OpenTelemetry severity buckets, each covering four severity numbers.
 */
enum SeverityLevel: string
{
    case Trace = 'trace';
    case Debug = 'debug';
    case Info = 'info';
    case Warn = 'warn';
    case Error = 'error';
    case Fatal = 'fatal';

    /**
     * The lowest severity number that belongs to this bucket.
     */
    public function minimumSeverityNumber(): int
    {
        return match ($this) {
            self::Trace => 1,
            self::Debug => 5,
            self::Info => 9,
            self::Warn => 13,
            self::Error => 17,
            self::Fatal => 21,
        };
    }

    /**
     * The highest severity number that belongs to this bucket.
     */
    public function maximumSeverityNumber(): int
    {
        return $this->minimumSeverityNumber() + 3;
    }

    /**
     * All bucket values, ordered from least to most severe.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $level): string => $level->value, self::cases());
    }
}
