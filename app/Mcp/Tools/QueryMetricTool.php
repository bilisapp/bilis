<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesScope;
use App\Services\Metrics\MetricFilters;
use App\Services\Metrics\MetricQuery;
use App\Services\Metrics\MetricSeriesBuilder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;

/**
 * One metric over a window, as the metric explorer would chart it — through
 * `MetricQuery::series()` and nothing else — but shaped for a reader that pays
 * per token: values rounded to four significant digits, and every series
 * carrying its own min/max/avg/last so most questions are answered without
 * reading a single point.
 *
 * `series()` already cuts the window into about sixty buckets, so there is no
 * further downsampling here.
 */
#[Name('query-metric')]
#[Title('Query metric')]
#[Description('Read one metric over a time window as a time series, the way the Bilis metric explorer charts it: counters as a per-second rate, gauges as their level, histograms as p50/p95/p99. Split it with "group_by" (an attribute key) and narrow it with "where" (attribute = value). Every series carries min/max/avg/last, so read those first. Call list-metrics first for the exact metric name and its attribute keys. Answers "is something saturated, climbing, or slower than usual?".')]
class QueryMetricTool extends Tool
{
    use ResolvesScope;

    /**
     * Significant digits kept on every returned value.
     */
    private const SIGNIFICANT_DIGITS = 4;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request, MetricQuery $metrics): Response
    {
        $scope = $this->resolveScope($request);

        if ($scope instanceof Response) {
            return $scope;
        }

        $request->validate([
            'metric' => ['required', 'string', 'max:255'],
            'service' => ['sometimes', 'string', 'max:255'],
            'where' => ['sometimes', 'array', 'max:'.MetricFilters::MAX_WHERE],
            'where.*' => ['string', 'max:255'],
            'group_by' => ['sometimes', 'string', 'max:255'],
            'agg' => ['sometimes', 'string', 'in:'.implode(',', MetricFilters::AGGREGATIONS)],
        ], [
            'where.max' => 'At most '.MetricFilters::MAX_WHERE.' attribute filters may be given in "where".',
            'agg.in' => '"agg" must be one of: '.implode(', ', MetricFilters::AGGREGATIONS).'.',
        ]);

        [$from, $to] = ListMetricsTool::clampWindow(...$this->window($request, MetricFilters::DEFAULT_RANGE_MINUTES));

        $metric = trim((string) $request->get('metric'));
        $agg = $this->argument($request, 'agg') ?? 'avg';

        $filters = new MetricFilters(
            service: $this->argument($request, 'service'),
            metric: $metric,
            where: $this->where($request->get('where')),
            groupBy: $this->argument($request, 'group_by'),
            aggregation: $agg,
            from: $from,
            to: $to,
        );

        $catalog = $metrics->catalog($scope->projectIds, $filters);

        if ($catalog['unavailable']) {
            return Response::json([
                'team' => $scope->team->slug,
                'metric' => $metric,
                'unavailable' => true,
            ]);
        }

        $entry = null;

        foreach ($catalog['metrics'] as $candidate) {
            if ($candidate['name'] === $metric) {
                $entry = $candidate;

                break;
            }
        }

        if ($entry === null) {
            return Response::error(
                "No metric named '{$metric}' was reported by these projects in or around this window. Call list-metrics to see the exact names that were."
            );
        }

        $result = $metrics->series($scope->projectIds, $filters);

        $kind = $result['kind'] ?? null;
        $unit = (string) ($result['unit'] ?? $entry['unit']);

        /** @var list<string> $buckets */
        $buckets = $result['buckets'] ?? [];

        /** @var list<array{label: string, group: string|null, stat: string, points: list<float|null>}> $series */
        $series = $result['series'] ?? [];

        $notes = array_values(array_filter([
            ($result['truncatedGroups'] ?? 0) > 0
                ? "{$result['truncatedGroups']} more groups were left out; only the ".MetricSeriesBuilder::MAX_GROUPS.' heaviest are returned. Narrow with "where" to see others.'
                : null,
            ($result['droppedSeries'] ?? 0) > 0
                ? "{$result['droppedSeries']} series were left out: only the busiest ".MetricQuery::SERIES_LIMIT.' are read, and histogram series with a different bucket layout are dropped.'
                : null,
            ($result['approximate'] ?? false) ? 'Values are approximate: summary quantiles cannot be merged exactly across series, so they are averaged.' : null,
            $series === [] ? 'The metric exists but had no data points in this window (or none matching the filters).' : null,
        ]));

        return Response::json([
            'team' => $scope->team->slug,
            'metric' => $entry['name'],
            'type' => $entry['type'],
            'readsAs' => ListMetricsTool::readsAs($entry['type'], $entry['monotonic'], $entry['temporality']),
            'kind' => $kind,
            'unit' => $kind === 'rate' ? ($unit === '' ? '/s' : "{$unit}/s") : $unit,
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'intervalSeconds' => $result['intervalSeconds'] ?? 0,
            'groupBy' => $filters->groupBy,
            'agg' => $entry['type'] === 'gauge' || ($entry['type'] === 'sum' && $entry['temporality'] === 2 && ! $entry['monotonic']) ? $agg : null,
            'buckets' => array_map(
                fn (string $bucket): string => Carbon::createFromFormat('Y-m-d H:i:s', $bucket, 'UTC')?->format('Y-m-d\TH:i:s\Z') ?? $bucket,
                $buckets,
            ),
            'series' => array_map(fn (array $line): array => [
                'label' => $line['label'],
                'stat' => $line['stat'],
                'summary' => $this->summarise($line['points']),
                'values' => array_map(fn (?float $value): ?float => $value === null ? null : self::round($value), $line['points']),
            ], $series),
            'notes' => $notes,
            'unavailable' => (bool) ($result['unavailable'] ?? false),
        ]);
    }

    /**
     * Min, max, average and the last non-empty value of one series.
     *
     * @param  list<float|null>  $points
     * @return array{min: float|null, max: float|null, avg: float|null, last: float|null, points: int}
     */
    private function summarise(array $points): array
    {
        $values = array_values(array_filter($points, fn (?float $value): bool => $value !== null));

        if ($values === []) {
            return ['min' => null, 'max' => null, 'avg' => null, 'last' => null, 'points' => 0];
        }

        return [
            'min' => self::round(min($values)),
            'max' => self::round(max($values)),
            'avg' => self::round(array_sum($values) / count($values)),
            'last' => self::round($values[count($values) - 1]),
            'points' => count($values),
        ];
    }

    /**
     * Round to a fixed number of significant digits.
     */
    private static function round(float $value): float
    {
        if ($value == 0.0 || ! is_finite($value)) {
            return $value;
        }

        $magnitude = (int) floor(log10(abs($value)));

        return round($value, self::SIGNIFICANT_DIGITS - 1 - $magnitude);
    }

    /**
     * Attribute filters with blank keys and values dropped.
     *
     * @return array<string, string>
     */
    private function where(mixed $where): array
    {
        if (! is_array($where)) {
            return [];
        }

        $filters = [];

        foreach ($where as $key => $value) {
            $key = trim((string) $key);
            $value = is_string($value) ? trim($value) : '';

            if ($key !== '' && $value !== '') {
                $filters[$key] = $value;
            }
        }

        return $filters;
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'metric' => $schema->string()->required()->description('Exact metric name, from list-metrics.'),
            'team' => $schema->string()->description('Team slug. Omit to use the current team.'),
            'project' => $schema->string()->description('Project slug. Omit to read every project in the team.'),
            'service' => $schema->string()->description('Only data points reported by this service.'),
            'where' => $schema->object()->description('Attribute equality filters, e.g. {"http.route": "/checkout"}. At most '.MetricFilters::MAX_WHERE.'. Keys come from list-metrics with "metric".'),
            'group_by' => $schema->string()->description('An attribute key to split the metric by, one series per value (the ten heaviest).'),
            'agg' => $schema->string()->enum(MetricFilters::AGGREGATIONS)->description('How a gauge\'s (or up-down counter\'s) series are combined into one line: avg (default), min, max or sum. Ignored for counters and distributions.'),
            'from' => $schema->string()->description('Start of the window, ISO-8601. Defaults to an hour before "to"; at most 30 days before it.'),
            'to' => $schema->string()->description('End of the window, ISO-8601. Defaults to now.'),
        ];
    }
}
