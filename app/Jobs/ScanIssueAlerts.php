<?php

namespace App\Jobs;

use App\Services\Alerting\IssueAlertScan;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One team's issue-event scan for one minute, off the scheduler's back.
 *
 * `alerts:evaluate` stays a cheap dispatcher and queues one of these per
 * subscribed team on the `alerts` queue, so the {@see $timeout} is a per-team
 * budget: a team whose reads time out costs only itself, never the teams
 * after it. Unique per team and minute, so a slow worker never runs two scans
 * of one team for one tick. One attempt only — the next tick overlaps this
 * one by design ({@see IssueAlertScan::LOOKBACK_SECONDS}).
 */
class ScanIssueAlerts implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Inside the queue's `retry_after` (90 s); one team is at most two reads.
     */
    public int $timeout = 50;

    public int $tries = 1;

    /**
     * A lost job must not hold its unique lock past the next couple of ticks.
     */
    public int $uniqueFor = 120;

    /**
     * @param  int  $teamId  the team to scan
     * @param  int  $minute  unix seconds of the dispatching tick's minute
     */
    public function __construct(public readonly int $teamId, public readonly int $minute)
    {
        $this->onQueue('alerts');
    }

    /**
     * One scan per team per minute.
     */
    public function uniqueId(): string
    {
        return $this->teamId.':'.$this->minute;
    }

    /**
     * Scan the team, if it still takes issue events. Failures are reported inside.
     */
    public function handle(IssueAlertScan $scan): void
    {
        $scan->scanTeamId($this->teamId);
    }
}
