<?php

namespace App\Services\Alerting;

/**
 * Prometheus' rule-group clock: evaluations land on slots aligned to the
 * rule's interval on the unix epoch, so every rule with the same interval
 * reads the same windows and a restart never shifts them. Pure: no clock of
 * its own, unix seconds in and out.
 */
final class EvaluationClock
{
    /**
     * The aligned slot at or before a moment.
     */
    public static function align(int $timestamp, int $intervalSeconds): int
    {
        $interval = max(1, $intervalSeconds);

        return $timestamp - ((($timestamp % $interval) + $interval) % $interval);
    }

    /**
     * The first aligned slot strictly after a moment — where a rule's next
     * evaluation goes, so a missed stretch evaluates once rather than replaying.
     */
    public static function nextSlotAfter(int $timestamp, int $intervalSeconds): int
    {
        return self::align($timestamp, $intervalSeconds) + max(1, $intervalSeconds);
    }

    /**
     * The timestamp an evaluation reads up to: now minus the ingest lag,
     * aligned down to the interval. The window is `[at − window, at)`.
     */
    public static function evaluatedAt(int $now, int $lagSeconds, int $intervalSeconds): int
    {
        return self::align($now - max(0, $lagSeconds), $intervalSeconds);
    }
}
