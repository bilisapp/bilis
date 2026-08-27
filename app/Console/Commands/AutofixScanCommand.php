<?php

namespace App\Console\Commands;

use App\Models\FixJob;
use App\Services\Autofix\FixTriggerService;
use Illuminate\Console\Command;

/**
 * Looks for production errors worth an automated fix attempt.
 *
 * Scheduled every five minutes in `routes/console.php`. Doing nothing is the
 * normal outcome: the trigger's thresholds, budgets and cooldowns all say no
 * far more often than they say yes.
 */
class AutofixScanCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'autofix:scan';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scan recent errors and raise autofix jobs for the ones that qualify';

    /**
     * Execute the console command.
     */
    public function handle(FixTriggerService $trigger): int
    {
        if (config('autofix.enabled') !== true) {
            $this->components->info('Autofix is disabled; nothing was scanned.');

            return self::SUCCESS;
        }

        $created = $trigger->scan();

        if ($created === []) {
            $this->components->info('No errors qualified for a fix attempt.');

            return self::SUCCESS;
        }

        foreach ($created as $job) {
            $this->components->twoColumnDetail(
                $this->label($job),
                substr($job->fingerprint, 0, 12),
            );
        }

        $this->components->info(sprintf('Raised %d fix job%s.', count($created), count($created) === 1 ? '' : 's'));

        return self::SUCCESS;
    }

    /**
     * A short human label for one raised job.
     */
    private function label(FixJob $job): string
    {
        $context = $job->error_context;
        $exception = $context['exception'] ?? '';

        return is_string($exception) && $exception !== '' ? $exception : $job->uuid;
    }
}
