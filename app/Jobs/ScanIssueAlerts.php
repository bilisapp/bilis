<?php

namespace App\Jobs;

use App\Services\Alerting\IssueAlertScan;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One minute's issue-event scan, off the scheduler's back.
 *
 * `alerts:evaluate` stays a cheap dispatcher; the ClickHouse reads happen
 * here, on the `alerts` queue. Unique per minute, so a slow worker never runs
 * two scans for one tick. One attempt only — the next tick overlaps this one
 * by design ({@see IssueAlertScan::LOOKBACK_SECONDS}).
 */
class ScanIssueAlerts implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Inside the queue's `retry_after` (90 s).
     */
    public int $timeout = 50;

    public int $tries = 1;

    /**
     * A lost job must not hold its unique lock past the next couple of ticks.
     */
    public int $uniqueFor = 120;

    /**
     * @param  int  $minute  unix seconds of the dispatching tick's minute
     */
    public function __construct(public readonly int $minute)
    {
        $this->onQueue('alerts');
    }

    /**
     * One scan per minute.
     */
    public function uniqueId(): string
    {
        return (string) $this->minute;
    }

    /**
     * Scan every subscribed team. Failures are reported per team inside.
     */
    public function handle(IssueAlertScan $scan): void
    {
        $scan->scan();
    }
}
