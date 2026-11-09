<?php

namespace App\Jobs;

use App\Enums\AlertCondition;
use App\Enums\AlertEventType;
use App\Enums\AlertRuleHealth;
use App\Enums\AlertState;
use App\Enums\NoDataState;
use App\Models\AlertEvent;
use App\Models\AlertInstance;
use App\Models\AlertRule;
use App\Services\Alerting\AlertEvaluationSettings;
use App\Services\Alerting\AlertNotifier;
use App\Services\Alerting\AlertSources;
use App\Services\Alerting\AlertStateMachine;
use App\Services\Alerting\Labels;
use App\Services\Alerting\Sources\AlertReading;
use App\Services\Alerting\Sources\AlertSourceUnavailable;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * One evaluation of one rule at one aligned timestamp (specs/alerting.md,
 * "EvaluateAlertRule").
 *
 * Reads `[evaluatedAt − window, evaluatedAt)` through the rule's source, runs
 * {@see AlertStateMachine} for every series that had data and every known
 * series that did not (the no-data case), and writes instances and events in
 * one transaction. A source failure changes no instance: the rule's health
 * goes `err`, and the ok → err edge records one `rule_error` event.
 *
 * Events are keyed by `dedupe_key` and inserted or ignored, so running the
 * same evaluation twice records — and notifies — nothing twice. Only newly
 * recorded events reach {@see AlertNotifier}, after commit, and never for a
 * muted rule.
 *
 * One attempt only: the next tick is the retry, as in Prometheus, which never
 * replays a missed evaluation.
 */
class EvaluateAlertRule implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Inside the queue's `retry_after` (90 s), above ClickHouse's own timeout.
     */
    public int $timeout = 20;

    public int $tries = 1;

    /**
     * A lost job must not hold its unique lock forever.
     */
    public int $uniqueFor = 300;

    /**
     * An inactive series with no data for this long is forgotten.
     */
    private const int STALE_INSTANCE_SECONDS = 86_400;

    /**
     * @param  int  $evaluatedAt  unix seconds, aligned to the rule's interval
     */
    public function __construct(
        public readonly int $ruleId,
        public readonly int $evaluatedAt,
    ) {
        $this->onQueue('alerts');
    }

    /**
     * One job per rule per evaluation timestamp.
     */
    public function uniqueId(): string
    {
        return $this->ruleId.':'.$this->evaluatedAt;
    }

    /**
     * Evaluate the rule.
     */
    public function handle(AlertSources $sources, AlertNotifier $notifier): void
    {
        $rule = AlertRule::query()->find($this->ruleId);

        if ($rule === null || ! $rule->enabled) {
            return;
        }

        // A delayed job must never move a rule's instances back in time. This
        // early check only saves the read; the writes check again under a lock.
        if ($this->isOutdatedFor($rule)) {
            return;
        }

        $evaluatedAt = CarbonImmutable::createFromTimestampUTC($this->evaluatedAt);
        $projectIds = $this->projectIds($rule);

        if ($projectIds === []) {
            $this->recordError($rule, 'None of the rule\'s projects belong to its team any more.', $notifier);

            return;
        }

        try {
            $reading = $sources->for($rule->source)->evaluate(
                $rule,
                $projectIds,
                $evaluatedAt->subSeconds($rule->window_seconds),
                $evaluatedAt,
            );
        } catch (Throwable $exception) {
            report($exception);

            $this->recordError(
                $rule,
                $exception instanceof AlertSourceUnavailable ? $exception->getMessage() : 'The source failed unexpectedly.',
                $notifier,
            );

            return;
        }

        $this->recordEvaluation($rule, $reading, $notifier);
    }

    /**
     * The queue gave up on the job (timeout, or an error outside the source):
     * the rule's health says so, and its instances are left as they were.
     *
     * Not when a newer evaluation — or this one — has already recorded an
     * outcome: an old tick that timed out must not turn a healthy rule `err`,
     * rewind `last_evaluated_at` or send a `rule_error`.
     */
    public function failed(?Throwable $exception): void
    {
        $rule = AlertRule::query()->find($this->ruleId);

        if ($rule === null || $this->isOutdatedFor($rule, includingThisTick: true)) {
            return;
        }

        $this->recordError(
            $rule,
            'The evaluation did not finish: '.($exception?->getMessage() ?? 'unknown error'),
            app(AlertNotifier::class),
            includingThisTick: true,
        );
    }

    /**
     * Whether the rule has already been evaluated past this job's timestamp
     * (or, with `$includingThisTick`, at it).
     */
    private function isOutdatedFor(AlertRule $rule, bool $includingThisTick = false): bool
    {
        $last = $rule->last_evaluated_at?->getTimestamp();

        if ($last === null) {
            return false;
        }

        return $includingThisTick ? $last >= $this->evaluatedAt : $last > $this->evaluatedAt;
    }

    /**
     * The rule row, locked for the rest of the transaction, unless it is gone,
     * turned off, or already evaluated past this job — rechecked here because
     * two evaluations of one rule can both pass the early check in `handle()`.
     * The lock is `SELECT … FOR UPDATE` on PostgreSQL; SQLite serialises
     * writers on its own and Laravel compiles no lock clause there.
     */
    private function lockedRule(AlertRule $rule, bool $includingThisTick = false): ?AlertRule
    {
        $locked = AlertRule::query()->lockForUpdate()->find($rule->id);

        if ($locked === null || ! $locked->enabled || $this->isOutdatedFor($locked, $includingThisTick)) {
            return null;
        }

        return $locked;
    }

    /**
     * The rule's projects that still belong to its team, as ClickHouse
     * `ProjectId` strings. A project moved or deleted since the rule was saved
     * drops out here rather than being read. Null `project_ids` is every
     * project the team has now, so one created after the rule is read too.
     *
     * @return list<string>
     */
    private function projectIds(AlertRule $rule): array
    {
        $teamProjectIds = $rule->team()->firstOrFail()->projects()->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();

        if ($rule->project_ids === null) {
            return array_values($teamProjectIds);
        }

        $wanted = array_map(fn (mixed $id): string => (string) $id, $rule->project_ids);

        return array_values(array_intersect($teamProjectIds, $wanted));
    }

    /**
     * Apply one successful reading to the rule's instances, in one transaction.
     *
     * A truncated reading (the source kept only the most extreme series) says
     * nothing about the series it left out, so known instances missing from it
     * keep their state rather than taking the no-data path.
     */
    private function recordEvaluation(AlertRule $rule, AlertReading $reading, AlertNotifier $notifier): void
    {
        $evaluatedAt = CarbonImmutable::createFromTimestampUTC($this->evaluatedAt);

        $seen = [];
        foreach ($reading->series as $entry) {
            $seen[Labels::hash($entry['labels'])] = $entry;
        }

        DB::transaction(function () use ($rule, $reading, $seen, $evaluatedAt, $notifier): void {
            $rule = $this->lockedRule($rule);

            if ($rule === null) {
                return;
            }

            $settings = AlertEvaluationSettings::fromRule($rule);
            $kept = $this->capped($seen, $rule->condition);

            $rule->forceFill([
                'health' => AlertRuleHealth::Ok,
                'last_error' => null,
                'last_evaluated_at' => $evaluatedAt,
            ])->save();

            $existing = $rule->instances()->get()->keyBy('labels_hash');
            $eventIds = [];

            foreach ($kept as $hash => $entry) {
                $instance = $existing->get($hash) ?? new AlertInstance([
                    'alert_rule_id' => $rule->id,
                    'labels' => $entry['labels'],
                    'labels_hash' => $hash,
                    'state' => AlertState::Inactive,
                ]);

                $result = AlertStateMachine::next($instance->toState(), $entry['value'], $settings, $evaluatedAt);

                $instance->applyState($result->state)->fill([
                    'value' => $entry['value'],
                    'last_seen_with_data_at' => $evaluatedAt,
                ])->save();

                if ($result->transition !== null) {
                    $eventIds[] = $this->recordEvent($rule, $instance, $result->transition, $entry['value'], $evaluatedAt, ['no_data' => false]);
                }
            }

            // Known series absent from the reading: the no-data case. Series
            // that had data but fell outside the cap — ours, or the source's
            // when it says the reading was truncated — are left as they were.
            $absent = $reading->truncated
                ? new Collection
                : $existing->reject(fn (AlertInstance $instance): bool => isset($seen[$instance->labels_hash]));

            foreach ($absent as $instance) {
                $result = AlertStateMachine::next($instance->toState(), null, $settings, $evaluatedAt);

                $instance->applyState($result->state)->save();

                if ($result->transition !== null) {
                    $eventIds[] = $this->recordEvent($rule, $instance, $result->transition, null, $evaluatedAt, ['no_data' => true]);
                }
            }

            $this->forgetStaleInstances($rule, $evaluatedAt);
            $this->retireSilentSeries($rule, $absent, $evaluatedAt);

            $this->notifyAfterCommit($rule, $eventIds, $notifier);
        });
    }

    /**
     * Keep the `max_instances` most extreme series: the highest values for
     * `>` / `>=`, the lowest for `<` / `<=`.
     *
     * @param  array<string, array{labels: array<string, string>, value: float}>  $series
     * @return array<string, array{labels: array<string, string>, value: float}>
     */
    private function capped(array $series, AlertCondition $condition): array
    {
        $limit = max(1, (int) config('alerting.max_instances', 100));

        if (count($series) <= $limit) {
            return $series;
        }

        $descending = in_array($condition, [AlertCondition::GreaterThan, AlertCondition::GreaterThanOrEqual], true);

        uasort($series, fn (array $a, array $b): int => $descending
            ? $b['value'] <=> $a['value']
            : $a['value'] <=> $b['value']);

        return array_slice($series, 0, $limit, true);
    }

    /**
     * Mark the rule's health `err`, change no instance, and on the edge into
     * `err` record (and notify) one `rule_error` event.
     */
    private function recordError(AlertRule $rule, string $message, AlertNotifier $notifier, bool $includingThisTick = false): void
    {
        $evaluatedAt = CarbonImmutable::createFromTimestampUTC($this->evaluatedAt);

        DB::transaction(function () use ($rule, $message, $notifier, $evaluatedAt, $includingThisTick): void {
            $rule = $this->lockedRule($rule, $includingThisTick);

            if ($rule === null) {
                return;
            }

            $wasFailing = $rule->health === AlertRuleHealth::Err;

            $rule->forceFill([
                'health' => AlertRuleHealth::Err,
                'last_error' => Str::limit($message, 1000),
                'last_evaluated_at' => $evaluatedAt,
            ])->save();

            if ($wasFailing) {
                return;
            }

            $eventId = $this->recordEvent($rule, null, AlertEventType::RuleError, null, $evaluatedAt, ['error' => $message]);

            $this->notifyAfterCommit($rule, [$eventId], $notifier);
        });
    }

    /**
     * Insert an event unless one with the same dedupe key exists.
     *
     * @param  array<string, mixed>  $context
     * @return int|null the new event's id, or null when it was already recorded
     */
    private function recordEvent(
        AlertRule $rule,
        ?AlertInstance $instance,
        AlertEventType $type,
        ?float $value,
        CarbonImmutable $evaluatedAt,
        array $context,
    ): ?int {
        $labels = $instance === null ? [] : $instance->labels;
        $dedupeKey = sprintf('rule:%d:%s:%s:%d', $rule->id, Labels::hash($labels), $type->value, $evaluatedAt->getTimestamp());

        // Filled through the model so the casts encode the row, then inserted
        // with ON CONFLICT DO NOTHING so a retry neither duplicates nor aborts.
        $event = (new AlertEvent)->forceFill([
            'team_id' => $rule->team_id,
            'alert_rule_id' => $rule->id,
            'alert_instance_id' => $instance?->id,
            'type' => $type,
            'labels' => $labels,
            'value' => $value,
            'threshold' => $rule->threshold,
            // Frozen with the threshold, so the message never pairs this
            // event's threshold with a condition edited since.
            'condition' => $rule->condition,
            'severity' => $rule->severity,
            'occurred_at' => $evaluatedAt,
            'context' => [
                'labels' => $labels,
                'source' => $rule->source,
                'window' => [
                    'from' => $evaluatedAt->subSeconds($rule->window_seconds)->toIso8601ZuluString(),
                    'to' => $evaluatedAt->toIso8601ZuluString(),
                ],
                ...$context,
            ],
            'dedupe_key' => $dedupeKey,
            'created_at' => now(),
        ]);

        if (AlertEvent::query()->insertOrIgnore($event->getAttributes()) === 0) {
            return null;
        }

        $id = AlertEvent::query()->where('dedupe_key', $dedupeKey)->value('id');

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * Delete inactive series that have had no data for a day.
     */
    private function forgetStaleInstances(AlertRule $rule, CarbonImmutable $evaluatedAt): void
    {
        $cutoff = $evaluatedAt->subSeconds(self::STALE_INSTANCE_SECONDS);

        $rule->instances()
            ->where('state', AlertState::Inactive)
            ->where(fn ($query) => $query
                ->where('last_seen_with_data_at', '<', $cutoff)
                ->orWhere(fn ($query) => $query
                    ->whereNull('last_seen_with_data_at')
                    ->where('updated_at', '<', $cutoff)))
            ->delete();
    }

    /**
     * Delete, without an event, series that are firing only because they have
     * had no data for `alerting.retire_after` seconds under a rule whose
     * `no_data_state` is firing — a decommissioned host must not fire forever
     * (specs/alerting-defaults.md, "Retired series"). A series never seen with
     * data is measured from when it was created.
     *
     * @param  Collection<string, AlertInstance>  $absent  known series missing from this reading
     */
    private function retireSilentSeries(AlertRule $rule, Collection $absent, CarbonImmutable $evaluatedAt): void
    {
        if ($rule->no_data_state !== NoDataState::Firing) {
            return;
        }

        $cutoff = $evaluatedAt->subSeconds(max(1, (int) config('alerting.retire_after', 86_400)));

        $retired = $absent
            ->filter(fn (AlertInstance $instance): bool => $instance->state === AlertState::Firing
                && ($instance->last_seen_with_data_at ?? $instance->created_at)?->lessThan($cutoff) === true)
            ->modelKeys();

        if ($retired !== []) {
            AlertInstance::query()->whereKey($retired)->delete();
        }
    }

    /**
     * Hand newly recorded events to the notifier once the transaction has
     * committed — unless the rule is muted, which records and sends nothing.
     *
     * @param  array<int|null>  $eventIds
     */
    private function notifyAfterCommit(AlertRule $rule, array $eventIds, AlertNotifier $notifier): void
    {
        $eventIds = array_values(array_filter($eventIds));

        if ($eventIds === [] || $rule->isMutedAt(now())) {
            return;
        }

        DB::afterCommit(function () use ($eventIds, $notifier): void {
            AlertEvent::query()->whereKey($eventIds)->orderBy('id')->get()
                ->each(fn (AlertEvent $event) => $notifier->notify($event));
        });
    }
}
