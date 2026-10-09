<?php

namespace App\Services\Tools;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Throwable;

/**
 * One instant, read from whatever was pasted and written back in every unit.
 *
 * Held as integer nanoseconds since the epoch — what OTLP's `*UnixNano`
 * fields carry — so nanosecond precision survives every conversion. A
 * PHP int is 64-bit and OTLP's field is an unsigned fixed64, so the
 * range is 1970-01-01 up to 2262-04-11 and nothing before the epoch.
 *
 * A bare number's unit is inferred from its size: up to 11 digits is
 * seconds, 12–14 milliseconds, 15–17 microseconds, longer nanoseconds.
 * That is the same guess every log pipeline makes, and it is right for any
 * date between 1973 and 5138.
 */
final class TimestampConversion
{
    public const UNITS = [
        'auto' => 'Detect',
        's' => 'Seconds',
        'ms' => 'Milliseconds',
        'us' => 'Microseconds',
        'ns' => 'Nanoseconds',
    ];

    private const NANOS_PER_UNIT = [
        's' => 1_000_000_000,
        'ms' => 1_000_000,
        'us' => 1_000,
        'ns' => 1,
    ];

    private function __construct(
        public readonly string $input,
        public readonly ?int $nanoseconds,
        public readonly ?string $detectedUnit,
        public readonly ?string $error,
        public readonly DateTimeZone $timezone,
    ) {}

    /**
     * Convert what was typed. An empty input means "now".
     */
    public static function from(string $input, string $unit = 'auto', string $timezone = 'UTC', ?int $nowNanoseconds = null): self
    {
        $input = trim($input);
        $unit = array_key_exists($unit, self::UNITS) ? $unit : 'auto';
        $zone = self::zone($timezone);

        if ($input === '') {
            return new self('', $nowNanoseconds ?? self::now(), 'now', null, $zone);
        }

        $numeric = str_replace(['_', ',', ' '], '', $input);

        if (preg_match('/^\d+(\.\d+)?$/', $numeric) === 1) {
            return self::fromNumber($input, $numeric, $unit, $zone);
        }

        if (preg_match('/^-\d/', $numeric) === 1) {
            return new self($input, null, null, 'Negative timestamps are before 1970; OTLP time fields are unsigned and cannot hold them.', $zone);
        }

        return self::fromDate($input, $zone);
    }

    public function isValid(): bool
    {
        return $this->nanoseconds !== null;
    }

    public function seconds(): string
    {
        return (string) intdiv((int) $this->nanoseconds, 1_000_000_000);
    }

    public function milliseconds(): string
    {
        return (string) intdiv((int) $this->nanoseconds, 1_000_000);
    }

    public function microseconds(): string
    {
        return (string) intdiv((int) $this->nanoseconds, 1_000);
    }

    public function nanosecondsString(): string
    {
        return (string) $this->nanoseconds;
    }

    /**
     * ISO 8601 in UTC with all nine fraction digits.
     */
    public function iso(): string
    {
        return $this->dateTime(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s').'.'.$this->fraction().'Z';
    }

    /**
     * ISO 8601 in the chosen zone, with its offset.
     */
    public function zoned(): string
    {
        $moment = $this->dateTime($this->timezone);

        return $moment->format('Y-m-d\TH:i:s').'.'.$this->fraction().$moment->format('P');
    }

    public function rfc2822(): string
    {
        return $this->dateTime(new DateTimeZone('UTC'))->format(DATE_RFC2822);
    }

    /**
     * How far this instant is from now, in words.
     */
    public function relative(?CarbonImmutable $now = null): string
    {
        return $this->dateTime(new DateTimeZone('UTC'))->diffForHumans($now);
    }

    /**
     * The literal a ClickHouse `DateTime64(9, 'UTC')` column accepts.
     */
    public function clickHouse(): string
    {
        return $this->dateTime(new DateTimeZone('UTC'))->format('Y-m-d H:i:s').'.'.$this->fraction();
    }

    /**
     * The nine-digit sub-second part.
     */
    private function fraction(): string
    {
        return str_pad((string) ((int) $this->nanoseconds % 1_000_000_000), 9, '0', STR_PAD_LEFT);
    }

    private function dateTime(DateTimeZone $zone): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestampUTC($this->seconds())->setTimezone($zone);
    }

    private static function fromNumber(string $input, string $numeric, string $unit, DateTimeZone $zone): self
    {
        [$whole, $fraction] = array_pad(explode('.', $numeric, 2), 2, '');
        $whole = ltrim($whole, '0') ?: '0';

        if ($unit === 'auto') {
            $unit = match (true) {
                strlen($whole) <= 11 => 's',
                strlen($whole) <= 14 => 'ms',
                strlen($whole) <= 17 => 'us',
                default => 'ns',
            };
        }

        $perUnit = self::NANOS_PER_UNIT[$unit];
        $limit = intdiv(PHP_INT_MAX, $perUnit);

        if (strlen($whole) > 19 || (int) $whole > $limit || ($whole !== '0' && (string) (int) $whole !== $whole)) {
            return new self($input, null, $unit, 'That is past 2262-04-11, the last instant a 64-bit nanosecond timestamp can hold.', $zone);
        }

        // Digits beyond the unit's nanosecond resolution are dropped, never rounded up.
        $fractionDigits = strlen((string) $perUnit) - 1;
        $fractionNanos = (int) str_pad(substr($fraction, 0, $fractionDigits), $fractionDigits, '0');

        $nanoseconds = (int) $whole * $perUnit;

        if ($fractionNanos > PHP_INT_MAX - $nanoseconds) {
            return new self($input, null, $unit, 'That is past 2262-04-11, the last instant a 64-bit nanosecond timestamp can hold.', $zone);
        }

        return new self($input, $nanoseconds + $fractionNanos, $unit, null, $zone);
    }

    private static function fromDate(string $input, DateTimeZone $zone): self
    {
        try {
            // A date written without an offset is read in the chosen zone.
            $moment = CarbonImmutable::parse($input, $zone);
        } catch (Throwable) {
            return new self($input, null, null, 'That is neither a number nor a date we can read. Try 1700000000, 1700000000123 or 2026-10-09T14:30:00Z.', $zone);
        }

        $seconds = $moment->getTimestamp();

        if ($seconds < 0) {
            return new self($input, null, 'date', 'Dates before 1970 cannot be written as an OTLP timestamp, which is unsigned.', $zone);
        }

        if ($seconds > intdiv(PHP_INT_MAX, 1_000_000_000)) {
            return new self($input, null, 'date', 'That is past 2262-04-11, the last instant a 64-bit nanosecond timestamp can hold.', $zone);
        }

        $nanoseconds = $seconds * 1_000_000_000 + (int) $moment->format('u') * 1_000 + self::subMicroDigits($input);

        return new self($input, $nanoseconds, 'date', null, $zone);
    }

    /**
     * The 7th–9th fraction digits a date string carried, which PHP's
     * microsecond DateTime cannot hold.
     */
    private static function subMicroDigits(string $input): int
    {
        if (preg_match('/[T ]\d{2}:\d{2}:\d{2}\.(\d{7,9})/', $input, $match) !== 1) {
            return 0;
        }

        return (int) str_pad(substr($match[1], 6, 3), 3, '0');
    }

    /**
     * Now, as nanoseconds since the epoch, to microsecond accuracy.
     */
    private static function now(): int
    {
        return (int) CarbonImmutable::now()->format('Uu') * 1_000;
    }

    private static function zone(string $timezone): DateTimeZone
    {
        return in_array($timezone, DateTimeZone::listIdentifiers(), true)
            ? new DateTimeZone($timezone)
            : new DateTimeZone('UTC');
    }
}
