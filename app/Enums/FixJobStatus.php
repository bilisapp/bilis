<?php

namespace App\Enums;

/**
 * The lifecycle of a single autofix attempt.
 *
 * `pending → dispatched → running → validating → pr_opened` is the happy path;
 * `merged`, `rejected`, `failed`, `cancelled` and `timeout` are terminal.
 */
enum FixJobStatus: string
{
    case Pending = 'pending';
    case Dispatched = 'dispatched';
    case Running = 'running';
    case Validating = 'validating';
    case PrOpened = 'pr_opened';
    case Merged = 'merged';
    case Rejected = 'rejected';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Timeout = 'timeout';

    /**
     * The statuses a job may still move on from.
     *
     * @return list<self>
     */
    public static function active(): array
    {
        return [self::Pending, self::Dispatched, self::Running, self::Validating, self::PrOpened];
    }

    /**
     * All enum values, in lifecycle order.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status): string => $status->value, self::cases());
    }

    /**
     * Determine whether the job has come to rest.
     */
    public function isTerminal(): bool
    {
        return ! $this->isActive();
    }

    /**
     * Determine whether the job is still in flight, PR review included.
     */
    public function isActive(): bool
    {
        return in_array($this, self::active(), true);
    }

    /**
     * Get the display label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::PrOpened => 'PR opened',
            default => ucfirst($this->value),
        };
    }
}
