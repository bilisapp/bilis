<?php

namespace App\Console\Commands;

use App\Jobs\ProvisionTeamAlerts;
use App\Models\Team;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * The default-alert reconciler's tick (specs/alerting-defaults.md §3).
 *
 * Scheduled every fifteen minutes in `routes/console.php`. Dispatches one
 * queued {@see ProvisionTeamAlerts} per team; the job does the reading and
 * writing. Existing teams are provisioned by the first run after deploy,
 * exactly like new ones.
 */
class AlertsProvisionCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'alerts:provision';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install and update the default alert rules for every team';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (config('alerting.enabled') !== true || config('alerting.provisioning') !== true) {
            $this->components->info('Alert provisioning is disabled; nothing was dispatched.');

            return self::SUCCESS;
        }

        $dispatched = 0;

        Team::query()->select('id')->chunkById(200, function (Collection $teams) use (&$dispatched): void {
            /** @var Collection<int, Team> $teams */
            foreach ($teams as $team) {
                ProvisionTeamAlerts::dispatch($team->id);

                $dispatched++;
            }
        });

        $this->components->info(sprintf('Dispatched provisioning for %d team%s.', $dispatched, $dispatched === 1 ? '' : 's'));

        return self::SUCCESS;
    }
}
