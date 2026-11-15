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
     * What a series is made of in `[$from, $to)`: the few distinct things
     * (log messages, span names) that account for most of its reading, most
     * first, so a notification or the rule page can say what was caught
     * rather than only how much. `$labels` narrows to one series; `[]` reads
     * every series the rule's filters match.
     *
     * Advisory only: a source with nothing to say returns `[]`, and a caller
     * never lets a failed read here block an evaluation or a notification.
     *
     * @param  list<string>  $projectIds
     * @param  array<string, string>  $labels
     * @return list<AlertExample>
     *
     * @throws AlertSourceUnavailable
     */
    public function examples(AlertRule $rule, array $projectIds, array $labels, CarbonImmutable $from, CarbonImmutable $to, int $limit): array;

    /**
     * Laravel validation rules for the rule's `query` JSON, keyed `query.*`.
     *
     * @return array<string, mixed>
     */
    public function rules(): array;
}
