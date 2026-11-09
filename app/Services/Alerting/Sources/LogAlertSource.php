<?php

namespace App\Services\Alerting\Sources;

use App\Models\AlertRule;
use App\Services\Alerting\Sources\Concerns\ReadsAlertValues;
use App\Services\Logs\LogFilters;
use App\Services\Logs\LogQuery;
use App\Services\Logs\SeverityLevel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Alert rules over log lines, read through {@see LogQuery::alertValues()}.
 *
 * `query`: `{filters: {services?, severities?, search?}, aggregation, group_by?,
 * compare?: {offset_seconds}, min_events?}`.
 *
 * - `count` — matching lines in the window;
 * - `rate` — the same, per minute;
 * - `ratio` — matching lines over **every line of the same projects and
 *   services**: severity and search narrow the numerator only, so "errors
 *   from checkout" reads as a share of everything checkout logged.
 *
 * `compare` (count and rate) divides the reading by the same reading one day
 * or one week earlier, floored at 1; `min_events` drops a series with fewer
 * matching lines (for `ratio`, lines of the services) as no data.
 */
final class LogAlertSource implements AlertSource
{
    use ReadsAlertValues;

    public const AGGREGATIONS = ['count', 'rate', 'ratio'];

    public const GROUP_BY = ['service.name'];

    public function __construct(private readonly LogQuery $logs) {}

    public function evaluate(AlertRule $rule, array $projectIds, CarbonImmutable $from, CarbonImmutable $to): AlertReading
    {
        $query = $this->validatedQuery($rule);
        $labelKey = $this->labelKey($query);

        // One row past the cap, ordered most extreme first, says whether the
        // rule had more series than it keeps.
        [$rows, $truncated] = $this->withinCap(
            $this->values($query, $projectIds, $from, $to, null, $this->maxInstances() + 1, $this->lowestFirst($rule)),
        );

        return new AlertReading($this->instances($rows, $labelKey, $this->minEvents($query)), $truncated);
    }

    public function preview(AlertRule $rule, array $projectIds, CarbonImmutable $from, CarbonImmutable $to, int $stepSeconds): array
    {
        $query = $this->validatedQuery($rule);
        $step = $this->previewStep($from, $to, $stepSeconds);

        return $this->previewLines(
            $this->values($query, $projectIds, $from, $to, $step, $this->maxInstances()),
            $this->labelKey($query),
            $from,
            $to,
            $step,
            $query['aggregation'] === 'ratio' ? null : 0.0,
        );
    }

    public function rules(): array
    {
        return [
            'query' => ['required', 'array'],
            'query.filters' => ['sometimes', 'array'],
            'query.filters.services' => ['sometimes', 'array', 'max:20'],
            'query.filters.services.*' => ['required', 'string', 'max:255'],
            'query.filters.severities' => ['sometimes', 'array'],
            'query.filters.severities.*' => ['required', 'string', Rule::in(SeverityLevel::values())],
            'query.filters.search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'query.aggregation' => ['required', 'string', Rule::in(self::AGGREGATIONS)],
            'query.group_by' => ['sometimes', 'nullable', 'string', Rule::in(self::GROUP_BY)],
            ...$this->baselineRules(uncomparable: ['ratio']),
        ];
    }

    /**
     * The reading over `[$from, $to)`, divided by its baseline when the rule
     * compares. Grouped, at most `$limit` groups are read: the highest
     * readings, or the lowest with `$lowestFirst`.
     *
     * @param  array<string, mixed>  $query
     * @param  list<string>  $projectIds
     * @return list<array{group: string, bucket: int|null, value: float|null, events: int}>
     *
     * @throws AlertSourceUnavailable
     */
    private function values(array $query, array $projectIds, CarbonImmutable $from, CarbonImmutable $to, ?int $step, int $limit, bool $lowestFirst = false): array
    {
        return $this->comparedWithBaseline(
            $query,
            $this->window($query, $projectIds, $from, $to, $step, null, $limit, $lowestFirst),
            $this->labelKey($query),
            fn (int $offset, ?array $groups): array => $this->window($query, $projectIds, $from->subSeconds($offset), $to->subSeconds($offset), $step, $groups, $limit, $lowestFirst),
        );
    }

    /**
     * One read of `[$from, $to)` through {@see LogQuery::alertValues()}.
     *
     * @param  array<string, mixed>  $query
     * @param  list<string>  $projectIds
     * @param  list<string>|null  $services  read only these services (a baseline's groups) instead of the rule's filter
     * @return list<array{group: string, bucket: int|null, value: float|null, events: int}>
     *
     * @throws AlertSourceUnavailable
     */
    private function window(array $query, array $projectIds, CarbonImmutable $from, CarbonImmutable $to, ?int $step, ?array $services, int $limit, bool $lowestFirst): array
    {
        return $this->read(fn (): ?array => $this->logs->alertValues(
            $projectIds,
            $this->filters($query, $from, $to, $services),
            $this->aggregation($query),
            $this->labelKey($query) !== null,
            $limit,
            $step,
            $lowestFirst,
        ));
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  list<string>|null  $services  read only these services (a baseline's groups) instead of the rule's filter
     */
    private function filters(array $query, CarbonImmutable $from, CarbonImmutable $to, ?array $services = null): LogFilters
    {
        /** @var array{services?: list<string>, severities?: list<string>, search?: string|null} $filters */
        $filters = $query['filters'] ?? [];
        $search = trim((string) ($filters['search'] ?? ''));

        return new LogFilters(
            severities: array_values(array_map(SeverityLevel::from(...), array_unique($filters['severities'] ?? []))),
            search: $search === '' ? null : $search,
            from: Carbon::instance($from),
            to: Carbon::instance($to),
            services: $services ?? array_values(array_unique($filters['services'] ?? [])),
        );
    }

    /**
     * @param  array<string, mixed>  $query
     * @return 'count'|'rate'|'ratio'
     */
    private function aggregation(array $query): string
    {
        return match ($query['aggregation'] ?? null) {
            'count' => 'count',
            'rate' => 'rate',
            'ratio' => 'ratio',
            default => throw new AlertSourceUnavailable('The rule\'s query has no log aggregation.'),
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
