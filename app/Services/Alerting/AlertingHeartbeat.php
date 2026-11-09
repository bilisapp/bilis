<?php

namespace App\Services\Alerting;

use Illuminate\Support\Facades\Cache;

/**
 * Proof that `alerts:evaluate` is still running: the unix time of its last
 * run, in the shared cache. Read by `GET /health/alerting` and by the
 * scheduler container's healthcheck (`alerts:heartbeat-check`).
 */
final class AlertingHeartbeat
{
    public const string KEY = 'alerting.heartbeat';

    /**
     * Older than this and the scheduler is considered stopped: three missed
     * minutes, so one slow run does not page anyone.
     */
    public const int MAX_AGE_SECONDS = 180;

    /**
     * Record a run.
     */
    public function beat(): void
    {
        Cache::forever(self::KEY, now()->getTimestamp());
    }

    /**
     * Seconds since the last run, or null when there has never been one.
     */
    public function age(): ?int
    {
        $beat = Cache::get(self::KEY);

        if (! is_numeric($beat)) {
            return null;
        }

        return max(0, now()->getTimestamp() - (int) $beat);
    }

    /**
     * Whether the last run is recent enough.
     */
    public function isFresh(): bool
    {
        $age = $this->age();

        return $age !== null && $age <= self::MAX_AGE_SECONDS;
    }
}
