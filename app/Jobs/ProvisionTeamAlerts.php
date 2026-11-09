<?php

namespace App\Jobs;

use App\Models\Team;
use App\Services\Alerting\Templates\AlertProvisioner;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Make one team match the default-alert catalogue
 * ({@see AlertProvisioner}), off the request and the scheduler.
 *
 * Dispatched by `alerts:provision` for every team and by `CreateTeam` right
 * after a team exists. Unique per team while queued, so a backlog holds one
 * job per team, and never overlapping while running: the unique lock expires
 * ({@see $uniqueFor}) so a lost job cannot block the team forever, and a
 * second job dispatched after that expiry is dropped if the first is still
 * running. One attempt only — the next scheduled run is the retry.
 */
class ProvisionTeamAlerts implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Up to three ClickHouse detection reads; inside the queue's `retry_after` (90 s).
     */
    public int $timeout = 30;

    public int $tries = 1;

    /**
     * A lost job must not hold its lock past the next scheduled run.
     */
    public int $uniqueFor = 600;

    public function __construct(public readonly int $teamId)
    {
        $this->onQueue('alerts');
    }

    /**
     * One run per team at a time.
     */
    public function uniqueId(): string
    {
        return (string) $this->teamId;
    }

    /**
     * Never two runs for one team at once, even after the unique lock has
     * expired under a backlog. An overlapping job is dropped, not released:
     * the next scheduled run is the retry. The lock outlives a killed worker
     * by at most the job's timeout plus a margin.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('provision-team-alerts:'.$this->teamId))
                ->dontRelease()
                ->expireAfter($this->timeout + 30),
        ];
    }

    /**
     * Provision the team, unless alerting or provisioning is switched off or
     * the team is gone.
     */
    public function handle(AlertProvisioner $provisioner): void
    {
        if (config('alerting.enabled') !== true || config('alerting.provisioning') !== true) {
            return;
        }

        $team = Team::query()->find($this->teamId);

        if ($team !== null) {
            $provisioner->provision($team);
        }
    }
}
