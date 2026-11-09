<?php

namespace App\Services\Alerting\Sources;

use App\Models\AlertRule;
use Carbon\CarbonImmutable;

/**
 * Something a rule can be evaluated against: logs, traces, metrics, or any
 * future entity. Register implementations in `config('alerting.sources')`.
 *
 * Implementations build SQL only through their entity's existing query
 * service (so the ProjectId predicate and SCHEMA.md rules are inherited), use
 * `{name:Type}` parameters only, and never return more than
 * `config('alerting.max_instances')` series. When a rule has more, the source
 * keeps the most extreme for the rule's condition (highest for `>`/`>=`,
 * lowest for `<`/`<=`) and marks the reading `truncated`, so the series it
 * left out are not mistaken for series without data.
 *
 * A failed or timed-out read MUST throw {@see AlertSourceUnavailable} — never
 * return an empty result, which the state machine would read as "no data"
 * and could fire or resolve on.
 */
interface AlertSource
{
    /**
     * One value per series that had data in `[$from, $to)`.
     *
     * @param  list<string>  $projectIds  already validated against the rule's team
     *
     * @throws AlertSourceUnavailable
     */
    public function evaluate(AlertRule $rule, array $projectIds, CarbonImmutable $from, CarbonImmutable $to): AlertReading;

    /**
     * The same reading bucketed over `[$from, $to)`, for the rule editor's preview chart.
     *
     * @param  list<string>  $projectIds
     * @return list<array{labels: array<string, string>, points: list<array{0: int, 1: float|null}>}> unix seconds => value
     *
     * @throws AlertSourceUnavailable
     */
    public function preview(AlertRule $rule, array $projectIds, CarbonImmutable $from, CarbonImmutable $to, int $stepSeconds): array;

    /**
     * Laravel validation rules for the rule's `query` JSON, keyed `query.*`.
     *
     * @return array<string, mixed>
     */
    public function rules(): array;
}
