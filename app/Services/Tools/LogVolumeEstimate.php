<?php

namespace App\Services\Tools;

/**
 * The arithmetic behind the public log volume and cost calculator.
 *
 * Pure: it takes a rate, an event size and a retention and says what that
 * is in bytes, what a per-GB-plus-per-event SaaS bill comes to, and how much
 * disk the same data needs once compressed on your own box. Every input is
 * clamped rather than rejected, because a calculator that answers 422 to a
 * half-typed number is worse than one that answers the nearest sane value.
 *
 * Gigabytes are decimal (10^9 bytes) — what vendors bill — and a month is
 * thirty days.
 */
final class LogVolumeEstimate
{
    public const DAYS_PER_MONTH = 30;

    private const BYTES_PER_GB = 1_000_000_000;

    /**
     * The defaults the page opens with: a modest service, and the published
     * list price of the best-known per-GB vendor (see the view for the source).
     */
    public const DEFAULTS = [
        'eps' => 200.0,
        'size' => 500,
        'retention' => 15,
        'ingest' => 0.10,
        'index' => 1.70,
        'ratio' => 10.0,
    ];

    public function __construct(
        public readonly float $eventsPerSecond,
        public readonly int $averageEventBytes,
        public readonly int $retentionDays,
        public readonly float $ingestPricePerGb,
        public readonly float $indexPricePerMillion,
        public readonly float $compressionRatio,
    ) {}

    /**
     * Build an estimate from a query string, clamping every value into range.
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromInput(array $input): self
    {
        return new self(
            eventsPerSecond: self::number($input, 'eps', 0, 10_000_000),
            averageEventBytes: (int) round(self::number($input, 'size', 1, 1_000_000)),
            retentionDays: (int) round(self::number($input, 'retention', 1, 3650)),
            ingestPricePerGb: self::number($input, 'ingest', 0, 100),
            indexPricePerMillion: self::number($input, 'index', 0, 100),
            compressionRatio: self::number($input, 'ratio', 1, 100),
        );
    }

    public function eventsPerDay(): float
    {
        return $this->eventsPerSecond * 86_400;
    }

    public function eventsPerMonth(): float
    {
        return $this->eventsPerDay() * self::DAYS_PER_MONTH;
    }

    public function gigabytesPerDay(): float
    {
        return $this->eventsPerDay() * $this->averageEventBytes / self::BYTES_PER_GB;
    }

    public function gigabytesPerMonth(): float
    {
        return $this->gigabytesPerDay() * self::DAYS_PER_MONTH;
    }

    /**
     * Uncompressed bytes held at any one time once retention is full.
     */
    public function retainedGigabytes(): float
    {
        return $this->gigabytesPerDay() * $this->retentionDays;
    }

    /**
     * The same retained data on disk, after columnar compression.
     */
    public function diskGigabytes(): float
    {
        return $this->retainedGigabytes() / $this->compressionRatio;
    }

    public function ingestCostPerMonth(): float
    {
        return $this->gigabytesPerMonth() * $this->ingestPricePerGb;
    }

    public function indexCostPerMonth(): float
    {
        return $this->eventsPerMonth() / 1_000_000 * $this->indexPricePerMillion;
    }

    public function costPerMonth(): float
    {
        return $this->ingestCostPerMonth() + $this->indexCostPerMonth();
    }

    public function costPerYear(): float
    {
        return $this->costPerMonth() * 12;
    }

    /**
     * The inputs as a query string array, for shareable links.
     *
     * @return array<string, float|int>
     */
    public function toQuery(): array
    {
        return [
            'eps' => $this->eventsPerSecond,
            'size' => $this->averageEventBytes,
            'retention' => $this->retentionDays,
            'ingest' => $this->ingestPricePerGb,
            'index' => $this->indexPricePerMillion,
            'ratio' => $this->compressionRatio,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private static function number(array $input, string $key, float $minimum, float $maximum): float
    {
        $value = $input[$key] ?? null;

        if (is_string($value)) {
            $value = str_replace([',', '_', ' '], '', $value);
        }

        if (! is_numeric($value)) {
            return (float) self::DEFAULTS[$key];
        }

        return max($minimum, min($maximum, (float) $value));
    }
}
