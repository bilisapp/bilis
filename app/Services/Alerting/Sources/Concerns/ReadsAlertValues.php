<?php

namespace App\Services\Alerting\Sources\Concerns;

use App\Enums\AlertCondition;
use App\Models\AlertRule;
use App\Services\Alerting\Sources\AlertSource;
use App\Services\Alerting\Sources\AlertSourceUnavailable;
use App\Services\ClickHouse\ClickHouseException;
use App\Services\Support\TimeBuckets;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * What every {@see AlertSource} needs around
 * its query service: the rule's `query` re-validated, every failed read turned
 * into {@see AlertSourceUnavailable} (never an empty result), and readings
 * shaped into instances or preview lines.
 */
trait ReadsAlertValues
{
    /**
     * The baseline offsets a rule may compare with: a day and a week back.
     */
    public const array COMPARE_OFFSETS = [86_400, 604_800];

    /**
     * The rule's `query`, validated against the source's own rules.
     *
     * A rule is validated when it is saved; this guards against one edited
     * outside the form or saved under older rules. A malformed query cannot be
     * answered, which the contract spells as unavailable.
     *
     * @return array<string, mixed>
     *
     * @throws AlertSourceUnavailable
     */
    protected function validatedQuery(AlertRule $rule): array
    {
        $validator = Validator::make(['query' => $rule->query], $this->rules());

        if ($validator->fails()) {
            throw new AlertSourceUnavailable('The rule\'s query is malformed: '.$validator->errors()->first());
        }

        /** @var array<string, mixed> $query */
        $query = $validator->validated()['query'] ?? [];

        return $query;
    }

    /**
     * Run a read, turning both failure modes into {@see AlertSourceUnavailable}.
     *
     * The query services answer an overloaded ClickHouse with null so the
     * viewer still renders, and rethrow everything else. Either way an alert
     * source has no answer, and an empty list would read as "no data".
     *
     * @template T of array
     *
     * @param  Closure(): (T|null)  $read
     * @return T
     *
     * @throws AlertSourceUnavailable
     */
    protected function read(Closure $read): array
    {
        try {
            $result = $read();
        } catch (ClickHouseException $exception) {
            throw new AlertSourceUnavailable('ClickHouse could not answer: '.$exception->getMessage(), previous: $exception);
        }

        if ($result === null) {
            throw new AlertSourceUnavailable('ClickHouse is overloaded; the window was not read.');
        }

        return $result;
    }

    /**
     * The most series one rule keeps.
     */
    protected function maxInstances(): int
    {
        return max(1, (int) config('alerting.max_instances', 100));
    }

    /**
     * Whether the rule's most extreme series are its lowest (`<`, `<=`): the
     * ones a capped read must keep.
     */
    protected function lowestFirst(AlertRule $rule): bool
    {
        return in_array($rule->condition, [AlertCondition::LessThan, AlertCondition::LessThanOrEqual], true);
    }

    /**
     * A grouped read of one more row than {@see maxInstances()}, already
     * ordered most extreme first, as at most `max_instances` rows and whether
     * any were left out.
     *
     * @template T
     *
     * @param  list<T>  $rows
     * @return array{0: list<T>, 1: bool}
     */
    protected function withinCap(array $rows): array
    {
        $limit = $this->maxInstances();

        return [array_slice($rows, 0, $limit), count($rows) > $limit];
    }

    /**
     * The preview's bucket width: the requested step, widened when the window
     * would otherwise need more buckets than any chart draws.
     */
    protected function previewStep(CarbonImmutable $from, CarbonImmutable $to, int $stepSeconds): int
    {
        return max(1, $stepSeconds, TimeBuckets::interval($from, $to, TimeBuckets::MAX_BUCKETS));
    }

    /**
     * The `query.compare` and `query.min_events` rules shared by the log and
     * trace sources (specs/alerting-defaults.md, "Rule engine additions").
     *
     * @param  list<string>  $uncomparable  aggregations a baseline comparison is refused for
     * @return array<string, mixed>
     */
    protected function baselineRules(array $uncomparable = []): array
    {
        $compare = ['sometimes', 'nullable', 'array'];

        if ($uncomparable !== []) {
            $compare[] = 'prohibited_if:query.aggregation,'.implode(',', $uncomparable);
        }

        return [
            'query.compare' => $compare,
            'query.compare.offset_seconds' => ['required_with:query.compare', 'integer', Rule::in(self::COMPARE_OFFSETS)],
            'query.min_events' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000000000'],
        ];
    }

    /**
     * How far back the baseline window is, or null when the rule does not
     * compare.
     *
     * @param  array<string, mixed>  $query
     */
    protected function compareOffset(array $query): ?int
    {
        $offset = is_array($query['compare'] ?? null) ? ($query['compare']['offset_seconds'] ?? null) : null;

        return is_numeric($offset) ? (int) $offset : null;
    }

    /**
     * The fewest events a series needs in the window to count as data.
     *
     * @param  array<string, mixed>  $query
     */
    protected function minEvents(array $query): ?int
    {
        return is_numeric($query['min_events'] ?? null) ? (int) $query['min_events'] : null;
    }

    /**
     * The reading divided by the same reading `compare.offset_seconds`
     * earlier, per group and (for the preview) per bucket against the bucket
     * one offset back:
     *
     * - `count` / `rate` — `current / max(baseline, 1)` in events, so a group
     *   or bucket absent from the baseline divides by 1, and a rate compares
     *   exactly as its count does (a per-minute baseline below 1 would
     *   otherwise be floored to a different number than its count);
     * - a ratio or a percentile — `current / baseline`. With no baseline
     *   reading (no events then, a NaN, or 0) there is no "normal" to be a
     *   multiple of, and the series has no data this evaluation: floored at
     *   1, a share would never exceed 1× and a new service's 300 ms would read
     *   as 300× normal.
     *
     * The baseline is one more read through the same query-service method,
     * restricted to the current reading's groups so a group outside the
     * baseline's own top N is not mistaken for a new one.
     *
     * @param  array<string, mixed>  $query
     * @param  list<array{group: string, bucket: int|null, value: float|null, events: int}>  $current
     * @param  Closure(int, list<string>|null): list<array{group: string, bucket: int|null, value: float|null, events: int}>  $readBaseline  given the offset and the groups to read (null: ungrouped)
     * @return list<array{group: string, bucket: int|null, value: float|null, events: int}>
     *
     * @throws AlertSourceUnavailable
     */
    protected function comparedWithBaseline(array $query, array $current, ?string $labelKey, Closure $readBaseline): array
    {
        $offset = $this->compareOffset($query);

        if ($offset === null) {
            return $current;
        }

        $groups = null;

        if ($labelKey !== null) {
            $groups = array_values(array_unique(array_map(fn (array $row): string => $row['group'], $current)));

            if ($groups === []) {
                return $current;
            }
        }

        $counts = in_array($query['aggregation'] ?? null, ['count', 'rate'], true);
        $baselines = [];

        foreach ($readBaseline($offset, $groups) as $row) {
            $baselines[$row['group']."\0".($row['bucket'] === null ? '' : $row['bucket'] + $offset)] = $row;
        }

        return array_map(function (array $row) use ($baselines, $counts): array {
            $baseline = $baselines[$row['group']."\0".($row['bucket'] ?? '')] ?? null;

            if ($counts) {
                return [...$row, 'value' => (float) $row['events'] / max($baseline['events'] ?? 0, 1.0)];
            }

            $normal = $baseline['value'] ?? null;

            return [...$row, 'value' => $row['value'] === null || $normal === null || is_nan($normal) || $normal <= 0.0
                ? null
                : $row['value'] / $normal];
        }, $current);
    }

    /**
     * One instance per reading that has a value and, when the rule sets
     * `min_events`, at least that many events in the window — a quieter
     * series counts as no data.
     *
     * @param  list<array{group: string, bucket: int|null, value: float|null, events: int}>  $rows
     * @return list<array{labels: array<string, string>, value: float}>
     */
    protected function instances(array $rows, ?string $labelKey, ?int $minEvents = null): array
    {
        $instances = [];

        foreach ($rows as $row) {
            if ($row['value'] === null || is_nan($row['value'])) {
                continue;
            }

            if ($minEvents !== null && $row['events'] < $minEvents) {
                continue;
            }

            $instances[] = ['labels' => $this->labels($row['group'], $labelKey), 'value' => $row['value']];
        }

        return $instances;
    }

    /**
     * Bucketed readings as preview lines, one point per bucket of `[$from, $to)`.
     *
     * A bucket with no row reads `$missing`: 0 for a count, null (a gap) for a
     * ratio or a percentile, which nothing can be said about.
     *
     * @param  list<array{group: string, bucket: int|null, value: float|null, events: int}>  $rows
     * @return list<array{labels: array<string, string>, points: list<array{0: int, 1: float|null}>}>
     */
    protected function previewLines(array $rows, ?string $labelKey, CarbonImmutable $from, CarbonImmutable $to, int $step, ?float $missing): array
    {
        $starts = array_values(array_filter(
            TimeBuckets::starts($from, $to, $step),
            fn (int $start): bool => $start < $to->getTimestamp(),
        ));

        $values = [];

        foreach ($rows as $row) {
            $value = $row['value'] === null || is_nan($row['value']) ? null : $row['value'];
            $values[$row['group']][(int) $row['bucket']] = $value;
        }

        if ($values === [] && $labelKey === null && $missing !== null) {
            $values[''] = [];
        }

        $lines = [];

        foreach ($values as $group => $points) {
            $lines[] = [
                'labels' => $this->labels((string) $group, $labelKey),
                'points' => array_map(
                    fn (int $start): array => [$start, array_key_exists($start, $points) ? $points[$start] : $missing],
                    $starts,
                ),
            ];
        }

        return $lines;
    }

    /**
     * @return array<string, string>
     */
    protected function labels(string $group, ?string $labelKey): array
    {
        return $labelKey === null ? [] : [$labelKey => $group];
    }
}
