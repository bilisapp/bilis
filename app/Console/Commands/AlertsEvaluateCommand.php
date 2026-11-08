<?php

namespace App\Console\Commands;

use App\Jobs\EvaluateAlertRule;
use App\Jobs\ScanIssueAlerts;
use App\Models\AlertRule;
use App\Services\Alerting\AlertingHeartbeat;
use App\Services\Alerting\EvaluationClock;
use App\Services\Alerting\IssueAlertScan;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * The alerting loop's tick (specs/alerting.md, "Evaluation").
 *
 * Scheduled every minute in `routes/console.php`. Dispatches one queued
 * {@see EvaluateAlertRule} per due rule and moves the rule's
 * `next_evaluation_at` to the next aligned slot strictly after now, so a
 * scheduler that was down for ten minutes evaluates each rule once, not ten
 * times. It also dispatches one {@see ScanIssueAlerts} per minute (new and
 * regressed issues) while any team has a channel flagged `issue_events`.
 * Then it writes the heartbeat `/health/alerting` reads.
 */
class AlertsEvaluateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'alerts:evaluate';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatch an evaluation for every alert rule that is due';

    /**
     * Execute the console command.
     */
    public function handle(AlertingHeartbeat $heartbeat, IssueAlertScan $issueScan): int
    {
        if (config('alerting.enabled') !== true) {
            $this->components->info('Alerting is disabled; nothing was evaluated.');

            return self::SUCCESS;
        }

        $now = now();
        $lag = (int) config('alerting.lag', 60);
        $dispatched = 0;

        /*
         * chunkById pages by id, so the rows are walked in id order rather
         * than by `next_evaluation_at`; every due rule is dispatched in this
         * run either way.
         */
        AlertRule::query()
            ->where('enabled', true)
            ->where(fn (Builder $query) => $query
                ->whereNull('next_evaluation_at')
                ->orWhere('next_evaluation_at', '<=', $now))
            ->chunkById(200, function (Collection $rules) use ($now, $lag, &$dispatched): void {
                /** @var Collection<int, AlertRule> $rules */
                foreach ($rules as $rule) {
                    $interval = max(60, $rule->interval_seconds);

                    $rule->forceFill([
                        'next_evaluation_at' => $now->copy()->setTimestamp(EvaluationClock::nextSlotAfter($now->getTimestamp(), $interval)),
                    ])->save();

                    EvaluateAlertRule::dispatch($rule->id, EvaluationClock::evaluatedAt($now->getTimestamp(), $lag, $interval));

                    $dispatched++;
                }
            });

        $this->dispatchIssueScan($issueScan, $now->getTimestamp());

        $heartbeat->beat();

        $this->components->info(sprintf('Dispatched %d alert rule evaluation%s.', $dispatched, $dispatched === 1 ? '' : 's'));

        return self::SUCCESS;
    }

    /**
     * Queue this minute's issue scan, only when someone would hear about it.
     * Never fails the tick: rule evaluation and the heartbeat matter more.
     */
    private function dispatchIssueScan(IssueAlertScan $issueScan, int $now): void
    {
        try {
            if ($issueScan->hasSubscribers()) {
                ScanIssueAlerts::dispatch($now - ($now % 60));
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
