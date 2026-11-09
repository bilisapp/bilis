<?php

namespace App\Console\Commands;

use App\Services\Alerting\AlertingHeartbeat;
use Illuminate\Console\Command;

/**
 * Exits non-zero when alerting is enabled and `alerts:evaluate` has not run
 * for {@see AlertingHeartbeat::MAX_AGE_SECONDS}. Run by the scheduler role in
 * `docker-healthcheck.sh`, which has no curl target of its own to probe.
 */
class AlertsHeartbeatCheckCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'alerts:heartbeat-check';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fail when alerting is enabled and the evaluation loop has stopped';

    /**
     * Execute the console command.
     */
    public function handle(AlertingHeartbeat $heartbeat): int
    {
        if (config('alerting.enabled') !== true) {
            $this->components->info('Alerting is disabled.');

            return self::SUCCESS;
        }

        $age = $heartbeat->age();

        if ($age === null) {
            $this->components->error('alerts:evaluate has never run.');

            return self::FAILURE;
        }

        if (! $heartbeat->isFresh()) {
            $this->components->error(sprintf('alerts:evaluate last ran %d seconds ago.', $age));

            return self::FAILURE;
        }

        $this->components->info(sprintf('alerts:evaluate ran %d seconds ago.', $age));

        return self::SUCCESS;
    }
}
