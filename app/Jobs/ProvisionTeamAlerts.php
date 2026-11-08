<?php

namespace App\Jobs;

use App\Models\Team;
use App\Services\Alerting\Templates\AlertProvisioner;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Make one team match the default-alert catalogue
 * ({@see AlertProvisioner}), off the request and the scheduler.
 *
 * Dispatched by `alerts:provision` for every team and by `CreateTeam` right
 * after a team exists. Unique per team, so a slow worker never runs two for
 * one team. One attempt only — the next scheduled run is the retry.
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
