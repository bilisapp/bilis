<?php

namespace App\Console\Commands;

use App\Models\FixJob;
use App\Services\Autofix\FixVerificationService;
use Illuminate\Console\Command;

/**
 * Checks whether merged fixes actually stopped the errors they were written for.
 *
 * Scheduled hourly in `routes/console.php`. Like the scan, doing nothing is the
 * normal outcome: a job is only ruled on once its deploy window has passed, and
 * once ruled on it is never looked at again.
 */
class AutofixVerifyCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'autofix:verify';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check merged autofix pull requests against the production logs';

    /**
     * Execute the console command.
     */
    public function handle(FixVerificationService $verification): int
    {
        if (config('autofix.enabled') !== true) {
            $this->components->info('Autofix is disabled; nothing was verified.');

            return self::SUCCESS;
        }

        $handled = $verification->verify();

        if ($handled === []) {
            $this->components->info('No merged fix was ready for a verdict.');

            return self::SUCCESS;
        }

        foreach ($handled as $job) {
            $this->components->twoColumnDetail($this->label($job), $this->outcome($job));
        }

        $this->components->info(sprintf('Ruled on %d merged fix%s.', count($handled), count($handled) === 1 ? '' : 'es'));

        return self::SUCCESS;
    }

    /**
     * A short human label for one ruled-on job.
     */
    private function label(FixJob $job): string
    {
        $context = $job->error_context ?? [];
        $exception = $context['exception'] ?? '';

        return is_string($exception) && $exception !== '' ? $exception : $job->uuid;
    }

    /**
     * The verdict the job now carries.
     */
    private function outcome(FixJob $job): string
    {
        $verification = $job->verification;
        $outcome = is_array($verification) ? ($verification['outcome'] ?? '') : '';

        return $outcome === FixVerificationService::OUTCOME_VERIFIED
            ? 'verified'
            : sprintf('still failing (%d)', is_array($verification) ? (int) ($verification['occurrences'] ?? 0) : 0);
    }
}
