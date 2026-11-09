<?php

namespace App\Services\Alerting\Sources;

use App\Models\AlertRule;
use App\Services\Alerting\Sources\Concerns\ReadsAlertValues;
use App\Services\ClickHouse\ClickHouseException;
use App\Services\Metrics\MetricFilters;
use App\Services\Metrics\MetricQuery;
use App\Services\Metrics\MetricSeriesBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Alert rules over one metric, read through {@see MetricQuery::series()} —
 * the explorer's own reading, so a gauge is its value, a counter its rate
 * (per-series deltas, R15) and a histogram its percentiles, exactly as charted.
 *
 * `query`: `{metric, filters?: {attribute: value}, aggregation, group_by?}`.
 * The bucketed line of each group is then reduced over the window:
 *
 * - `avg`/`max`/`min` — also how a gauge's series are combined per bucket;
 * - `last` — the newest bucket with a value;
 * - `p50`/`p95`/`p99` — histograms and summaries only: that percentile per
 *   bucket, averaged over the window.
 *
 * A percentile on a gauge or counter, or `avg` on a histogram, cannot be
 * answered and is refused as unavailable. Every group of the read is reduced,
 * then the `alerting.max_instances` most extreme for the rule's condition are
 * kept. The explorer's 500-series read cap (R16) still applies before
 * grouping; a read that hit it is marked truncated, so a series it dropped is
 * not taken for one without data.
 */
final class MetricAlertSource implements AlertSource
{
    use ReadsAlertValues;

    public const AGGREGATIONS = ['avg', 'max', 'min', 'last', 'p50', 'p95', 'p99'];

    public function __construct(private readonly MetricQuery $metrics) {}

    public function evaluate(AlertRule $rule, array $projectIds, CarbonImmutable $from, CarbonImmutable $to): AlertReading
    {
        $query = $this->validatedQuery($rule);
        $aggregation = (string) $query['aggregation'];
        $instances = [];

        // Every group the read returned, so the cap below ranks by value, not
        // by how many points a group happened to have.
        [$lines, $dropped] = $this->lines($query, $projectIds, $from, $to, null, MetricQuery::SERIES_LIMIT);

        foreach ($lines as $line) {
            $values = array_values(array_filter($line['points'], fn (array $point): bool => $point[1] !== null));

            if ($values === []) {
                continue;
            }

            $values = array_map(fn (array $point): float => (float) $point[1], $values);

            $instances[] = ['labels' => $line['labels'], 'value' => match ($aggregation) {
                'max' => max($values),
                'min' => min($values),
                'last' => $values[count($values) - 1],
                default => array_sum($values) / count($values),
            }];
        }

        $lowestFirst = $this->lowestFirst($rule);

        usort($instances, fn (array $a, array $b): int => $lowestFirst
            ? $a['value'] <=> $b['value']
            : $b['value'] <=> $a['value']);

        [$kept, $truncated] = $this->withinCap($instances);

        return new AlertReading($kept, $truncated || $dropped);
    }

    public function preview(AlertRule $rule, array $projectIds, CarbonImmutable $from, CarbonImmutable $to, int $stepSeconds): array
    {
        $query = $this->validatedQuery($rule);

        return $this->lines($query, $projectIds, $from, $to, $this->previewStep($from, $to, $stepSeconds), $this->maxInstances())[0];
    }

    public function rules(): array
    {
        return [
            'query' => ['required', 'array'],
            'query.metric' => ['required', 'string', 'max:255'],
            'query.filters' => ['sometimes', 'array', 'max:'.MetricFilters::MAX_WHERE],
            'query.filters.*' => ['required', 'string', 'max:255'],
            'query.aggregation' => ['required', 'string', Rule::in(self::AGGREGATIONS)],
            'query.group_by' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * The metric's line per group over `[$from, $to)`, as the explorer reads
     * it, and whether the read left any series out — the explorer's own
     * series cap (R16), or groups past `$maxGroups`.
     *
     * @param  array<string, mixed>  $query
     * @param  list<string>  $projectIds
     * @param  int|null  $interval  the bucket width; null lets the explorer choose
     * @param  int  $maxGroups  the heaviest groups kept, for the preview's chart
     * @return array{0: list<array{labels: array<string, string>, points: list<array{0: int, 1: float|null}>}>, 1: bool}
     *
     * @throws AlertSourceUnavailable
     */
    private function lines(array $query, array $projectIds, CarbonImmutable $from, CarbonImmutable $to, ?int $interval, int $maxGroups): array
    {
        $aggregation = (string) $query['aggregation'];
        $percentile = array_key_exists($aggregation, MetricSeriesBuilder::PERCENTILES);
        $groupBy = is_string($query['group_by'] ?? null) && trim($query['group_by']) !== '' ? trim($query['group_by']) : null;

        /** @var array<string, string> $where */
        $where = $query['filters'] ?? [];

        $filters = new MetricFilters(
            metric: (string) $query['metric'],
            where: $where,
            groupBy: $groupBy,
            aggregation: in_array($aggregation, ['avg', 'min', 'max'], true) ? $aggregation : 'avg',
            from: Carbon::instance($from),
            to: Carbon::instance($to),
            interval: $interval,
            maxGroups: $maxGroups,
            exclusiveTo: true,
        );

        try {
            $result = $this->metrics->series($projectIds, $filters);
        } catch (ClickHouseException $exception) {
            throw new AlertSourceUnavailable('ClickHouse could not answer: '.$exception->getMessage(), previous: $exception);
        }

        if ($result['unavailable']) {
            throw new AlertSourceUnavailable('ClickHouse is overloaded; the window was not read.');
        }

        $dropped = $result['droppedSeries'] > 0 || $result['truncatedGroups'] > 0;

        if ($result['kind'] === null) {
            return [[], $dropped];
        }

        $distribution = in_array($result['kind'], ['distribution', 'summary'], true);

        if ($distribution !== $percentile) {
            throw new AlertSourceUnavailable($distribution
                ? sprintf('%s is a %s: alert on p50, p95 or p99.', $result['metric'], $result['type'])
                : sprintf('%s is a %s: a percentile needs a histogram.', $result['metric'], $result['type']));
        }

        $starts = array_map(
            fn (string $bucket): int => CarbonImmutable::parse($bucket, 'UTC')->getTimestamp(),
            $result['buckets'],
        );

        $lines = [];

        foreach ($result['series'] as $series) {
            if ($distribution && $series['stat'] !== $aggregation) {
                continue;
            }

            $points = [];

            foreach ($series['points'] as $index => $value) {
                if (isset($starts[$index]) && $starts[$index] < $to->getTimestamp()) {
                    $points[] = [$starts[$index], $value];
                }
            }

            $lines[] = [
                'labels' => $groupBy === null ? [] : [$groupBy => (string) $series['group']],
                'points' => $points,
            ];
        }

        return [$lines, $dropped];
    }
}
