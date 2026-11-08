<?php

namespace App\Services\Alerting\Sources;

use App\Models\AlertRule;
use App\Services\Alerting\Sources\Concerns\ReadsAlertValues;
use App\Services\Traces\TraceQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Alert rules over spans, read through {@see TraceQuery::alertValues()}.
 *
 * `query`: `{filters: {services?, span_name?, kind?}, aggregation, group_by?,
 * compare?: {offset_seconds}, min_events?}`.
 * `count` is spans, `error_ratio` the share with `StatusCode = 'Error'` (R10),
 * `p50`/`p95`/`p99` the span `Duration` in milliseconds. `compare` divides any
 * of them by the same reading one day or one week earlier, floored at 1;
 * `min_events` drops a series with fewer spans as no data.
 *
 * A span read is a scan of the window, so an evaluated window may be at most
 * {@see MAX_WINDOW_SECONDS}; a longer one is refused as unavailable rather
 * than read. The editor's preview is not bound by it.
 */
final class TraceAlertSource implements AlertSource
{
    use ReadsAlertValues;

    public const AGGREGATIONS = ['count', 'error_ratio', 'p50', 'p95', 'p99'];

    public const GROUP_BY = ['service.name'];

    /**
     * The span kinds as the exporter stores them (SCHEMA.md R10).
     */
    public const KINDS = ['Unspecified', 'Internal', 'Server', 'Client', 'Producer', 'Consumer'];

    public const MAX_WINDOW_SECONDS = 3600;

    public function __construct(private readonly TraceQuery $traces) {}

    public function evaluate(AlertRule $rule, array $projectIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $query = $this->validatedQuery($rule);

        if ($to->getTimestamp() - $from->getTimestamp() > self::MAX_WINDOW_SECONDS) {
            throw new AlertSourceUnavailable(sprintf('A trace rule reads at most a %d-second window.', self::MAX_WINDOW_SECONDS));
        }

        $labelKey = $this->labelKey($query);

        return $this->instances($this->values($query, $projectIds, $from, $to, null), $labelKey, $this->minEvents($query));
    }

    public function preview(AlertRule $rule, array $projectIds, CarbonImmutable $from, CarbonImmutable $to, int $stepSeconds): array
    {
        $query = $this->validatedQuery($rule);
        $step = $this->previewStep($from, $to, $stepSeconds);

        return $this->previewLines(
            $this->values($query, $projectIds, $from, $to, $step),
            $this->labelKey($query),
            $from,
            $to,
            $step,
            $query['aggregation'] === 'count' ? 0.0 : null,
        );
    }

    public function rules(): array
    {
        return [
            'query' => ['required', 'array'],
            'query.filters' => ['sometimes', 'array'],
            'query.filters.services' => ['sometimes', 'array', 'max:20'],
            'query.filters.services.*' => ['required', 'string', 'max:255'],
            'query.filters.span_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'query.filters.kind' => ['sometimes', 'nullable', 'string', Rule::in(self::KINDS)],
            'query.aggregation' => ['required', 'string', Rule::in(self::AGGREGATIONS)],
            'query.group_by' => ['sometimes', 'nullable', 'string', Rule::in(self::GROUP_BY)],
            ...$this->baselineRules(),
        ];
    }

    /**
     * The reading over `[$from, $to)`, divided by its baseline when the rule
     * compares.
     *
     * @param  array<string, mixed>  $query
     * @param  list<string>  $projectIds
     * @return list<array{group: string, bucket: int|null, value: float|null, events: int}>
     *
     * @throws AlertSourceUnavailable
     */
    private function values(array $query, array $projectIds, CarbonImmutable $from, CarbonImmutable $to, ?int $step): array
    {
        return $this->comparedWithBaseline(
            $query,
            $this->window($query, $projectIds, $from, $to, $step, null),
            $this->labelKey($query),
            fn (int $offset, ?array $groups): array => $this->window($query, $projectIds, $from->subSeconds($offset), $to->subSeconds($offset), $step, $groups),
        );
    }

    /**
     * One read of `[$from, $to)` through {@see TraceQuery::alertValues()}.
     *
     * @param  array<string, mixed>  $query
     * @param  list<string>  $projectIds
     * @param  list<string>|null  $services  read only these services (a baseline's groups) instead of the rule's filter
     * @return list<array{group: string, bucket: int|null, value: float|null, events: int}>
     *
     * @throws AlertSourceUnavailable
     */
    private function window(array $query, array $projectIds, CarbonImmutable $from, CarbonImmutable $to, ?int $step, ?array $services): array
    {
        /** @var array{services?: list<string>, span_name?: string|null, kind?: string|null} $filters */
        $filters = $query['filters'] ?? [];
        $spanName = trim((string) ($filters['span_name'] ?? ''));

        return $this->read(fn (): ?array => $this->traces->alertValues(
            $projectIds,
            Carbon::instance($from),
            Carbon::instance($to),
            $services ?? array_values(array_unique($filters['services'] ?? [])),
            $spanName === '' ? null : $spanName,
            $filters['kind'] ?? null,
            $this->aggregation($query),
            $this->labelKey($query) !== null,
            $this->maxInstances(),
            $step,
        ));
    }

    /**
     * @param  array<string, mixed>  $query
     * @return 'count'|'error_ratio'|'p50'|'p95'|'p99'
     */
    private function aggregation(array $query): string
    {
        return match ($query['aggregation'] ?? null) {
            'count' => 'count',
            'error_ratio' => 'error_ratio',
            'p50' => 'p50',
            'p95' => 'p95',
            'p99' => 'p99',
            default => throw new AlertSourceUnavailable('The rule\'s query has no trace aggregation.'),
        };
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function labelKey(array $query): ?string
    {
        return is_string($query['group_by'] ?? null) ? $query['group_by'] : null;
    }
}
