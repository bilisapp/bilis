<?php

namespace App\Services\Ingest;

/**
 * Translation between severity numbers and severity texts, following the
 * OpenTelemetry logs data model.
 *
 * @see https://opentelemetry.io/docs/specs/otel/logs/data-model/#field-severitynumber
 */
class LogSeverity
{
    /**
     * The canonical severity name for each range of severity numbers.
     *
     * @var array<int, string>
     */
    private const RANGES = [
        1 => 'TRACE',
        5 => 'DEBUG',
        9 => 'INFO',
        13 => 'WARN',
        17 => 'ERROR',
        21 => 'FATAL',
    ];

    /**
     * Common aliases for the canonical severity names.
     *
     * @var array<string, int>
     */
    private const ALIASES = [
        'trace' => 1,
        'trace2' => 2,
        'trace3' => 3,
        'trace4' => 4,
        'verbose' => 1,
        'debug' => 5,
        'debug2' => 6,
        'debug3' => 7,
        'debug4' => 8,
        'info' => 9,
        'information' => 9,
        'informational' => 9,
        'notice' => 10,
        'log' => 9,
        'warn' => 13,
        'warning' => 13,
        'error' => 17,
        'err' => 17,
        'severe' => 17,
        'critical' => 18,
        'crit' => 18,
        'alert' => 19,
        'fatal' => 21,
        'emergency' => 21,
        'emerg' => 21,
        'panic' => 21,
    ];

    /**
     * Resolve a severity number and text pair from whatever the payload had.
     *
     * @return array{int, string}
     */
    public static function resolve(?int $number, ?string $text): array
    {
        $text = $text === null ? null : trim($text);
        $text = ($text === null || $text === '') ? null : $text;

        if ($number !== null && $number >= 1 && $number <= 24) {
            return [$number, $text ?? self::textForNumber($number)];
        }

        if ($text !== null) {
            $resolved = self::numberForText($text);

            if ($resolved !== null) {
                return [$resolved, $text];
            }
        }

        return [0, $text ?? ''];
    }

    /**
     * The canonical severity text for a severity number.
     */
    public static function textForNumber(int $number): string
    {
        $name = 'UNSPECIFIED';
        $base = 0;

        foreach (self::RANGES as $start => $candidate) {
            if ($number >= $start) {
                $name = $candidate;
                $base = $start;
            }
        }

        if ($base === 0) {
            return '';
        }

        $offset = $number - $base;

        return $offset === 0 ? $name : $name.($offset + 1);
    }

    /**
     * The severity number for a level name, if it is a known one.
     */
    public static function numberForText(string $text): ?int
    {
        return self::ALIASES[strtolower(trim($text))] ?? null;
    }
}
