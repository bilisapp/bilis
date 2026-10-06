<?php

namespace App\Services\Metrics;

use App\Services\ClickHouse\ClickHouseClient;
use App\Services\ClickHouse\ClickHouseException;
use App\Services\Ingest\OtlpMetricsMapper;
use App\Services\Support\TimeBuckets;
use App\Services\Traces\TraceQuery;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Carbon;

/**
 * Reads the five `otel_metrics_*` tables for the metric explorer and the MCP
 * tools. The one place their SQL lives, as {@see TraceQuery}
 * is for spans.
 *
 * Every statement leads with `ProjectId IN {projectIds:Array(String)}` and a
 * `TimeUnix` range — the tables' sort key is `(ProjectId, MetricName,
 * ServiceName, TimeUnix)` — and binds every user value as a `{name:Type}`
 * parameter. Project ids come from the controller, already narrowed to the
 * current team; an empty list short-circuits without touching ClickHouse.
 *
 * An overloaded ClickHouse yields `unavailable: true` and empty data rather
 * than an exception, so the page still renders; any other ClickHouse error is
 * rethrown.
 *
 * @phpstan-import-type Result from MetricSeriesBuilder
 *
 * @phpstan-type CatalogEntry array{name: string, type: string, unit: string, description: string, services: list<string>, monotonic: bool, temporality: int, points: int}
 */
class MetricQuery
{
    /**
     * The table each metric type is stored in, keyed by the type's catalog name.
     */
    public const TABLES = [
        'gauge' => OtlpMetricsMapper::TABLE_GAUGE,
        'sum' => OtlpMetricsMapper::TABLE_SUM,
        'histogram' => OtlpMetricsMapper::TABLE_HISTOGRAM,
        'exponential_histogram' => OtlpMetricsMapper::TABLE_EXPONENTIAL_HISTOGRAM,
        'summary' => OtlpMetricsMapper::TABLE_SUMMARY,
    ];

    /**
     * How far back the catalog looks when the selected window is shorter, so a
     * metric reported every few minutes is still pickable in a 15-minute view.
     */
    private const CATALOG_LOOKBACK_HOURS = 24;

    private const CATALOG_LIMIT = 500;

    private const CATALOG_CACHE_SECONDS = 60;

    /**
     * How many attribute keys, and values per key, the pickers are offered.
     */
    private const ATTRIBUTE_KEY_LIMIT = 50;

    private const ATTRIBUTE_VALUE_LIMIT = 50;

    /**
     * How many buckets a chart aims for; widths come from {@see TimeBuckets}.
     */
    private const TARGET_BUCKETS = 60;

    /**
     * The narrowest bucket a metric chart uses. SDKs and the server agent
     * export every 60 s by default; a narrower bucket would mostly be empty,
     * leaving a line of dots with gaps between them.
     */
    public const MIN_INTERVAL_SECONDS = 60;

    /**
     * The most series one chart reads (SCHEMA.md R16).
     */
    public const SERIES_LIMIT = 500;

    /**
     * A ceiling on raw points read where they cannot be pre-aggregated in SQL
     * (delta exponential histograms, whose buckets only align after a rescale).
     */
    private const RAW_ROW_LIMIT = 200_000;

    /**
     * The host-metrics receiver metrics that mark a `host.name` as a host, by
     * table: always on in the scraper, whatever else is configured.
     */
    public const HOST_MARKERS = [
        'gauge' => 'system.cpu.load_average.1m',
        'sum' => 'system.memory.usage',
    ];

    /**
     * How much of the window's end a host row's "now" figures are read from:
     * long enough for several points at the agent's 60 s interval.
     */
    public const HOST_RECENT_MINUTES = 15;

    /**
     * The most hosts one page lists.
     */
    private const HOST_LIMIT = 200;

    public function __construct(
        private readonly ClickHouseClient $client,
        private readonly CacheRepository $cache,
        private readonly MetricSeriesBuilder $builder,
    ) {}

    /**
     * Whether the given projects have ever reported a data point, in any table.
     *
     * Deliberately unbounded in time, like `TraceQuery::hasAnyTraces()`: it
     * stops at the first row. An overloaded ClickHouse answers true, so a
     * hiccup never makes an established team look brand new.
     *
     * @param  list<string>  $projectIds
     */
    public function hasAnyMetrics(array $projectIds): bool
    {
        if ($projectIds === []) {
            return false;
        }

        $selects = array_map(
            fn (string $table): string => "SELECT 1 FROM {$table} WHERE ProjectId IN {projectIds:Array(String)}",
            array_values(self::TABLES),
        );

        $rows = $this->select(
            'SELECT 1 FROM ('.implode(' UNION ALL ', $selects).') LIMIT 1',
            ['projectIds' => self::arrayParameter($projectIds)],
        );

        return $rows === null || $rows !== [];
    }

    /**
     * Whether the given projects have ever reported host metrics: what decides
     * that a bare visit to the metrics page opens on its Hosts tab.
     *
     * Like {@see hasAnyMetrics()} it stops at the first row; unlike it, an
     * overloaded ClickHouse answers false, so a hiccup lands on the explorer
     * rather than on a Hosts tab it then cannot fill.
     *
     * @param  list<string>  $projectIds
     */
    public function hasHosts(array $projectIds): bool
    {
        if ($projectIds === []) {
            return false;
        }

        $rows = $this->select(
            'SELECT 1 FROM '.self::TABLES['sum'].' WHERE ProjectId IN {projectIds:Array(String)} AND MetricName = {metric:String} LIMIT 1',
            ['projectIds' => self::arrayParameter($projectIds), 'metric' => self::HOST_MARKERS['sum']],
        );

        return $rows !== null && $rows !== [];
    }

    /**
     * Every host that reported in the window, with how it is doing now.
     *
     * A host is a `host.name` that sent one of the host-metrics receiver's
     * {@see HOST_MARKERS}, whatever its service name, so a hand-written
     * Collector config is found as well as the Bilis agent. "Now" is the last
     * {@see HOST_RECENT_MINUTES} of the window, read off the scraper's default
     * metrics so an install without the utilization flags still fills every
     * column:
     *
     * - CPU: `system.cpu.time` deltas per series (R15: per cpu, state and
     *   start time, so a restart is a new series, never a negative step), then
     *   `1 - idle / total`;
     * - memory: the latest `system.memory.usage` per state, `used / all`;
     * - disk: the latest `system.filesystem.usage` per mount, `used / (used +
     *   free)` as df reports it, the fullest mount;
     * - load: the latest `system.cpu.load_average.1m`;
     * - containers: distinct `container.name` behind `container.cpu.usage.total`.
     *
     * A host that went quiet before the last few minutes keeps its row with
     * nulls and an old `lastSeen` — that is the finding, not an error.
     *
     * @param  list<string>  $projectIds
     * @return array{hosts: list<array{name: string, lastSeen: string, cpu: float|null, memory: float|null, disk: float|null, diskMount: string|null, load: float|null, containers: int}>, unavailable: bool}
     */
    public function hosts(array $projectIds, MetricFilters $filters): array
    {
        if ($projectIds === []) {
            return ['hosts' => [], 'unavailable' => false];
        }

        $recentFrom = $filters->from->clone()->max($filters->to->clone()->subMinutes(self::HOST_RECENT_MINUTES));

        $params = [
            'projectIds' => self::arrayParameter($projectIds),
            'from' => self::formatTime($filters->from),
            'to' => self::formatTime($filters->to),
            'recentFrom' => self::formatTime($recentFrom),
            'gaugeMarker' => self::HOST_MARKERS['gauge'],
            'sumMarker' => self::HOST_MARKERS['sum'],
            'hostLimit' => self::HOST_LIMIT,
        ];

        $gauge = self::TABLES['gauge'];
        $sum = self::TABLES['sum'];
        $window = "ProjectId IN {projectIds:Array(String)} AND TimeUnix >= {from:DateTime('UTC')} AND TimeUnix <= {to:DateTime('UTC')}";
        $recent = "ProjectId IN {projectIds:Array(String)} AND TimeUnix >= {recentFrom:DateTime('UTC')} AND TimeUnix <= {to:DateTime('UTC')}";
        $host = "ResourceAttributes['host.name']";

        $listed = $this->select(
            "SELECT Host, max(At) AS LastSeen FROM (
                SELECT {$host} AS Host, max(toUnixTimestamp(TimeUnix)) AS At FROM {$gauge}
                WHERE {$window} AND MetricName = {gaugeMarker:String} GROUP BY Host
                UNION ALL
                SELECT {$host} AS Host, max(toUnixTimestamp(TimeUnix)) AS At FROM {$sum}
                WHERE {$window} AND MetricName = {sumMarker:String} GROUP BY Host
            )
            GROUP BY Host
            ORDER BY Host ASC
            LIMIT {hostLimit:UInt32}",
            $params,
        );

        if ($listed === null) {
            return ['hosts' => [], 'unavailable' => true];
        }

        if ($listed === []) {
            return ['hosts' => [], 'unavailable' => false];
        }

        $stats = $this->select(
            "SELECT Host, 'cpu' AS Stat, 1 - sumIf(D, State = 'idle') / sum(D) AS V, '' AS Label FROM (
                SELECT {$host} AS Host, Attributes['state'] AS State,
                    greatest(argMax(Value, TimeUnix) - argMin(Value, TimeUnix), 0) AS D
                FROM {$sum} WHERE {$recent} AND MetricName = 'system.cpu.time'
                GROUP BY Host, State, Attributes['cpu'], StartTimeUnix
            ) GROUP BY Host HAVING sum(D) > 0
            UNION ALL
            SELECT Host, 'memory' AS Stat, sumIf(Latest, State = 'used') / sum(Latest) AS V, '' AS Label FROM (
                SELECT {$host} AS Host, Attributes['state'] AS State, argMax(Value, TimeUnix) AS Latest
                FROM {$sum} WHERE {$recent} AND MetricName = {sumMarker:String}
                GROUP BY Host, State
            ) GROUP BY Host HAVING sum(Latest) > 0
            UNION ALL
            SELECT Host, 'disk' AS Stat, max(U / (U + F)) AS V, argMax(Mount, U / (U + F)) AS Label FROM (
                SELECT Host, Mount, sumIf(Latest, State = 'used') AS U, sumIf(Latest, State = 'free') AS F FROM (
                    SELECT {$host} AS Host, Attributes['mountpoint'] AS Mount, Attributes['device'] AS Device,
                        Attributes['state'] AS State, argMax(Value, TimeUnix) AS Latest
                    FROM {$sum} WHERE {$recent} AND MetricName = 'system.filesystem.usage'
                    GROUP BY Host, Mount, Device, State
                ) GROUP BY Host, Mount, Device
            ) WHERE U + F > 0 GROUP BY Host
            UNION ALL
            SELECT {$host} AS Host, 'load' AS Stat, argMax(Value, TimeUnix) AS V, '' AS Label
            FROM {$gauge} WHERE {$recent} AND MetricName = {gaugeMarker:String}
            GROUP BY Host
            UNION ALL
            SELECT {$host} AS Host, 'containers' AS Stat, toFloat64(uniq(ResourceAttributes['container.name'])) AS V, '' AS Label
            FROM {$sum} WHERE {$recent} AND MetricName = 'container.cpu.usage.total'
            GROUP BY Host",
            $params,
        );

        if ($stats === null) {
            return ['hosts' => [], 'unavailable' => true];
        }

        $byHost = [];

        foreach ($stats as $row) {
            $byHost[(string) ($row['Host'] ?? '')][(string) ($row['Stat'] ?? '')] = [
                'value' => is_numeric($row['V'] ?? null) ? (float) $row['V'] : null,
                'label' => (string) ($row['Label'] ?? ''),
            ];
        }

        $hosts = [];

        foreach ($listed as $row) {
            $name = (string) ($row['Host'] ?? '');
            $stat = $byHost[$name] ?? [];
            $ratio = fn (string $key): ?float => isset($stat[$key]['value']) ? round(max(0.0, min(1.0, $stat[$key]['value'])), 4) : null;

            $hosts[] = [
                'name' => $name,
                'lastSeen' => Carbon::createFromTimestampUTC((int) ($row['LastSeen'] ?? 0))->toIso8601String(),
                'cpu' => $ratio('cpu'),
                'memory' => $ratio('memory'),
                'disk' => $ratio('disk'),
                'diskMount' => isset($stat['disk']) && $stat['disk']['label'] !== '' ? $stat['disk']['label'] : null,
                'load' => isset($stat['load']['value']) ? round($stat['load']['value'], 2) : null,
                'containers' => (int) ($stat['containers']['value'] ?? 0),
            ];
        }

        return ['hosts' => $hosts, 'unavailable' => false];
    }

    /**
     * The metrics the projects reported around the window, one entry per name.
     *
     * Looks back at least a day so a sparse metric is still offered in a short
     * window, and snaps both bounds to the minute so the cache key holds for a
     * minute. A name reported as two types (it happens across SDK upgrades)
     * appears once per type.
     *
     * @param  list<string>  $projectIds
     * @return array{metrics: list<CatalogEntry>, unavailable: bool}
     */
    public function catalog(array $projectIds, MetricFilters $filters): array
    {
        if ($projectIds === []) {
            return ['metrics' => [], 'unavailable' => false];
        }

        $now = Carbon::now();
        $from = $filters->from->clone()->min($filters->to->clone()->subHours(self::CATALOG_LOOKBACK_HOURS))->startOfMinute();
        $to = $filters->to->clone()->min($now->clone()->addMinute())->startOfMinute()->addMinute();

        $params = [
            'projectIds' => self::arrayParameter($projectIds),
            'from' => self::formatTime($from),
            'to' => self::formatTime($to),
            'rowLimit' => self::CATALOG_LIMIT,
        ];

        $selects = [];

        foreach (self::TABLES as $type => $table) {
            $monotonic = $type === 'sum' ? 'any(IsMonotonic)' : 'false';
            $temporality = in_array($type, ['sum', 'histogram', 'exponential_histogram'], true) ? 'any(AggregationTemporality)' : '0';

            $selects[] = "SELECT MetricName AS Name, '{$type}' AS Type, any(MetricUnit) AS Unit,
                    any(MetricDescription) AS Description, groupUniqArray(20)(ServiceName) AS Services,
                    {$monotonic} AS Monotonic, toInt32({$temporality}) AS Temporality, count() AS Points
                FROM {$table}
                WHERE ProjectId IN {projectIds:Array(String)}
                  AND TimeUnix >= {from:DateTime('UTC')} AND TimeUnix <= {to:DateTime('UTC')}
                GROUP BY MetricName";
        }

        $sql = 'SELECT * FROM ('.implode(' UNION ALL ', $selects).') ORDER BY Name ASC, Points DESC LIMIT {rowLimit:UInt32}';

        $key = 'metrics.catalog.'.sha1(implode(',', $projectIds).'|'.$params['from'].'|'.$params['to']);

        $cached = $this->cache->get($key);

        if (is_array($cached)) {
            /** @var array{metrics: list<CatalogEntry>, unavailable: bool} $cached */
            return $cached;
        }

        $rows = $this->select($sql, $params);

        if ($rows === null) {
            return ['metrics' => [], 'unavailable' => true];
        }

        $result = ['metrics' => array_map(self::catalogEntry(...), $rows), 'unavailable' => false];

        $this->cache->put($key, $result, self::CATALOG_CACHE_SECONDS);

        return $result;
    }

    /**
     * The selected metric's attribute keys, most common first, each with its
     * most common values — the options for the filter and group-by pickers.
     *
     * Resource attributes are offered too, as {@see attribute()} reads them,
     * less `service.name` (it has its own field) and the SDK's `telemetry.*`.
     *
     * @param  list<string>  $projectIds
     * @return array{attributes: list<array{key: string, values: list<string>}>, unavailable: bool}
     */
    public function attributes(array $projectIds, MetricFilters $filters): array
    {
        $entry = $filters->metric === null ? null : $this->entry($projectIds, $filters);

        if ($entry === null) {
            return ['attributes' => [], 'unavailable' => false];
        }

        [$conditions, $params] = $this->conditions($projectIds, $filters, withWhere: false);

        $table = self::TABLES[$entry['type']];

        $rows = $this->select(
            "SELECT Key, groupArray({valueLimit:UInt32})(Value) AS Values
             FROM (
                 SELECT Key, mapUpdate(mapFilter((k, v) -> k != 'service.name' AND NOT startsWith(k, 'telemetry.'), ResourceAttributes), Attributes)[Key] AS Value, count() AS Seen
                 FROM {$table}
                 ARRAY JOIN mapKeys(mapUpdate(mapFilter((k, v) -> k != 'service.name' AND NOT startsWith(k, 'telemetry.'), ResourceAttributes), Attributes)) AS Key
                 WHERE ".implode(' AND ', $conditions).'
                 GROUP BY Key, Value
                 ORDER BY Seen DESC
             )
             GROUP BY Key
             ORDER BY sum(1) DESC, Key ASC
             LIMIT {keyLimit:UInt32}',
            [...$params, 'valueLimit' => self::ATTRIBUTE_VALUE_LIMIT, 'keyLimit' => self::ATTRIBUTE_KEY_LIMIT],
        );

        if ($rows === null) {
            return ['attributes' => [], 'unavailable' => true];
        }

        $attributes = [];

        foreach ($rows as $row) {
            $values = is_array($row['Values'] ?? null) ? $row['Values'] : [];

            $attributes[] = [
                'key' => (string) ($row['Key'] ?? ''),
                'values' => array_values(array_map(strval(...), $values)),
            ];
        }

        return ['attributes' => $attributes, 'unavailable' => false];
    }

    /**
     * The chart: one metric over the window, as lines of bucketed values.
     *
     * The metric's catalog entry decides the table and the shape:
     *
     * - gauge, or a non-monotonic cumulative sum (an up-down counter): a level,
     *   combined across series by the filter's aggregation;
     * - delta sum: the sum per bucket, as a rate;
     * - cumulative monotonic sum: per-series deltas, as a rate (R15);
     * - histogram / exponential histogram: percentiles from merged buckets,
     *   differenced per series first when cumulative;
     * - summary: the stored quantiles, averaged across series.
     *
     * At most {@see SERIES_LIMIT} series are read (R16): the busiest, by point
     * count. The rest are reported as `droppedSeries`, never silently lost.
     *
     * @param  list<string>  $projectIds
     * @return Result
     */
    public function series(array $projectIds, MetricFilters $filters): array
    {
        if ($projectIds === [] || $filters->metric === null) {
            return $this->builder->empty($filters);
        }

        $entry = $this->entry($projectIds, $filters);

        if ($entry === null) {
            return $this->builder->empty($filters);
        }

        $interval = max(self::MIN_INTERVAL_SECONDS, TimeBuckets::interval($filters->from, $filters->to, self::TARGET_BUCKETS));
        $starts = TimeBuckets::starts($filters->from, $filters->to, $interval);
        $cumulative = $entry['temporality'] === 2;
        $level = $entry['type'] === 'gauge' || ($entry['type'] === 'sum' && $cumulative && ! $entry['monotonic']);

        // A cumulative read starts one bucket early, so the window's first
        // bucket has a previous point to take its delta from.
        $readFrom = ($starts[0] ?? $filters->from->getTimestamp()) - ($cumulative && ! $level ? $interval : 0);

        [$conditions, $params] = $this->conditions($projectIds, $filters, from: Carbon::createFromTimestampUTC($readFrom));

        $table = self::TABLES[$entry['type']];
        $group = $filters->groupBy === null ? "''" : self::attribute('groupBy');
        $params = [...$params, 'interval' => $interval, 'seriesLimit' => self::SERIES_LIMIT];

        if ($filters->groupBy !== null) {
            $params['groupBy'] = $filters->groupBy;
        }

        $where = implode(' AND ', $conditions);
        $seriesKey = 'toString(cityHash64(ServiceName, ResourceAttributes, Attributes))';
        $bucket = 'toUnixTimestamp(toStartOfInterval(TimeUnix, toIntervalSecond({interval:UInt32})))';

        // The busiest series only (R16), and how many there were in all.
        $capped = "{$where} AND {$seriesKey} IN (
            SELECT {$seriesKey} FROM {$table} WHERE {$where}
            GROUP BY {$seriesKey} ORDER BY count() DESC LIMIT {seriesLimit:UInt32}
        )";

        $totalRows = $this->select("SELECT uniq({$seriesKey}) AS Total FROM {$table} WHERE {$where}", $params);

        if ($totalRows === null) {
            return $this->builder->empty($filters, unavailable: true);
        }

        $context = [
            'metric' => $entry['name'],
            'type' => $entry['type'],
            'unit' => $entry['unit'],
            'grouped' => $filters->groupBy !== null,
            'interval' => $interval,
            'starts' => $starts,
            'readFrom' => $readFrom,
            'droppedSeries' => max(0, (int) ($totalRows[0]['Total'] ?? 0) - self::SERIES_LIMIT),
        ];

        $lastPoint = fn (string $columns): string => "SELECT {$seriesKey} AS S, {$group} AS Grp, {$bucket} AS Bucket, {$columns},
                argMax(toUnixTimestamp(StartTimeUnix), TimeUnix) AS Start,
                max(toUnixTimestamp(TimeUnix)) AS At
            FROM {$table} WHERE {$capped}
            GROUP BY S, Grp, Bucket
            ORDER BY S ASC, Bucket ASC";

        [$sql, $shape] = match (true) {
            $level => [
                "SELECT Bucket, Grp, {$this->outer($filters)}(V) AS V, count() AS W FROM (
                    SELECT {$bucket} AS Bucket, {$group} AS Grp, {$seriesKey} AS S,
                        ".($entry['type'] === 'gauge' ? $this->inner($filters) : 'argMax(Value, TimeUnix)').' AS V
                    FROM '.$table." WHERE {$capped}
                    GROUP BY Bucket, Grp, S
                ) GROUP BY Bucket, Grp",
                'level',
            ],
            $entry['type'] === 'sum' && ! $cumulative => [
                "SELECT {$bucket} AS Bucket, {$group} AS Grp, sum(Value) AS V, count() AS W
                 FROM {$table} WHERE {$capped} GROUP BY Bucket, Grp",
                'delta-rate',
            ],
            $entry['type'] === 'sum' => [
                $lastPoint('argMax(Value, TimeUnix) AS V'),
                'cumulative-rate',
            ],
            $entry['type'] === 'histogram' && ! $cumulative => [
                "SELECT {$bucket} AS Bucket, {$group} AS Grp, ExplicitBounds AS Bounds,
                    sumForEach(BucketCounts) AS Counts, sum(Count) AS C
                 FROM {$table} WHERE {$capped} AND length(BucketCounts) > 0
                 GROUP BY Bucket, Grp, Bounds",
                'explicit-delta',
            ],
            $entry['type'] === 'histogram' => [
                $lastPoint('argMax(ExplicitBounds, TimeUnix) AS Bounds, argMax(BucketCounts, TimeUnix) AS Counts, argMax(Count, TimeUnix) AS C'),
                'explicit-cumulative',
            ],
            $entry['type'] === 'exponential_histogram' && ! $cumulative => [
                "SELECT {$bucket} AS Bucket, {$group} AS Grp, Scale, ZeroCount, PositiveOffset, PositiveBucketCounts,
                    NegativeOffset, NegativeBucketCounts
                 FROM {$table} WHERE {$capped}
                 LIMIT {rowLimit:UInt32}",
                'exponential-delta',
            ],
            $entry['type'] === 'exponential_histogram' => [
                $lastPoint('argMax(Scale, TimeUnix) AS Scale, argMax(ZeroCount, TimeUnix) AS ZeroCount,
                    argMax(PositiveOffset, TimeUnix) AS PositiveOffset, argMax(PositiveBucketCounts, TimeUnix) AS PositiveBucketCounts,
                    argMax(NegativeOffset, TimeUnix) AS NegativeOffset, argMax(NegativeBucketCounts, TimeUnix) AS NegativeBucketCounts'),
                'exponential-cumulative',
            ],
            default => [
                "SELECT {$bucket} AS Bucket, {$group} AS Grp, Q, avg(QV) AS V, count() AS W
                 FROM {$table}
                 ARRAY JOIN ValueAtQuantiles.Quantile AS Q, ValueAtQuantiles.Value AS QV
                 WHERE {$capped}
                 GROUP BY Bucket, Grp, Q",
                'summary',
            ],
        };

        $rows = $this->select($sql, [...$params, 'rowLimit' => self::RAW_ROW_LIMIT]);

        if ($rows === null) {
            return $this->builder->empty($filters, unavailable: true);
        }

        return match ($shape) {
            'level' => $this->builder->levels($rows, $context, $filters->aggregation, perSecond: false),
            'delta-rate' => $this->builder->levels($rows, $context, 'rate', perSecond: true),
            'cumulative-rate' => $this->builder->cumulativeRates($rows, $context),
            'explicit-delta' => $this->builder->explicitDistributions($rows, $context, cumulative: false),
            'explicit-cumulative' => $this->builder->explicitDistributions($rows, $context, cumulative: true),
            'exponential-delta' => $this->builder->exponentialDistributions($rows, $context, cumulative: false),
            'exponential-cumulative' => $this->builder->exponentialDistributions($rows, $context, cumulative: true),
            default => $this->builder->summaries($rows, $context),
        };
    }

    /**
     * How one series' points within a bucket are combined, before series are.
     */
    private function inner(MetricFilters $filters): string
    {
        return match ($filters->aggregation) {
            'min' => 'min(Value)',
            'max' => 'max(Value)',
            default => 'avg(Value)',
        };
    }

    /**
     * How the series of one group are combined into its line.
     */
    private function outer(MetricFilters $filters): string
    {
        return match ($filters->aggregation) {
            'min' => 'min',
            'max' => 'max',
            'sum' => 'sum',
            default => 'avg',
        };
    }

    /**
     * The catalog entry for the selected metric, or null when it has no data.
     *
     * Read from the catalog (cached) rather than asked again: the explorer
     * needs the catalog anyway, and the type decides the table.
     *
     * @param  list<string>  $projectIds
     * @return CatalogEntry|null
     */
    public function entry(array $projectIds, MetricFilters $filters): ?array
    {
        foreach ($this->catalog($projectIds, $filters)['metrics'] as $entry) {
            if ($entry['name'] === $filters->metric) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * The base predicate every series and attribute read starts from.
     *
     * Project and window first (the sort key), then the metric, the service
     * and each attribute equality. Attribute keys and values are both bound —
     * see {@see attribute()} — so neither can reach SQL.
     *
     * @param  list<string>  $projectIds
     * @return array{0: list<string>, 1: array<string, scalar>}
     */
    public function conditions(array $projectIds, MetricFilters $filters, bool $withWhere = true, ?Carbon $from = null): array
    {
        $conditions = [
            'ProjectId IN {projectIds:Array(String)}',
            "TimeUnix >= {from:DateTime('UTC')}",
            "TimeUnix <= {to:DateTime('UTC')}",
            'MetricName = {metric:String}',
        ];

        $params = [
            'projectIds' => self::arrayParameter($projectIds),
            'from' => self::formatTime($from ?? $filters->from),
            'to' => self::formatTime($filters->to),
            'metric' => (string) $filters->metric,
        ];

        if ($filters->service !== null) {
            $conditions[] = 'ServiceName = {service:String}';
            $params['service'] = $filters->service;
        }

        if ($withWhere) {
            $index = 0;

            foreach ($filters->where as $key => $value) {
                $conditions[] = self::attribute("whereKey{$index}")." = {whereValue{$index}:String}";
                $params["whereKey{$index}"] = $key;
                $params["whereValue{$index}"] = $value;
                $index++;
            }
        }

        return [$conditions, $params];
    }

    /**
     * One attribute's value, its key bound as the named parameter.
     *
     * The data point's own attribute when it has one, else the resource's —
     * the precedence OTel gives a point over its resource. `host.name` and
     * `container.name` are resource attributes, so without the fallback no
     * host could be filtered on or grouped by.
     */
    public static function attribute(string $param): string
    {
        return "if(mapContains(Attributes, {{$param}:String}), Attributes[{{$param}:String}], ResourceAttributes[{{$param}:String}])";
    }

    /**
     * Run a SELECT, turning an overload into null and rethrowing anything else.
     *
     * @param  array<string, scalar>  $params
     * @return list<array<string, mixed>>|null
     */
    public function select(string $sql, array $params): ?array
    {
        try {
            return array_values($this->client->select($sql, $params));
        } catch (ClickHouseException $exception) {
            if (! $exception->isOverload()) {
                throw $exception;
            }

            report($exception);

            return null;
        }
    }

    /**
     * A PHP list rendered as a ClickHouse `Array(String)` parameter.
     *
     * @param  list<string>  $values
     */
    public static function arrayParameter(array $values): string
    {
        return '['.implode(',', array_map(
            fn (string $value): string => "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'",
            $values,
        )).']';
    }

    /**
     * A time formatted for a `DateTime('UTC')` parameter: whole seconds, UTC.
     */
    public static function formatTime(Carbon $time): string
    {
        return $time->clone()->utc()->format('Y-m-d H:i:s');
    }

    /**
     * @param  array<string, mixed>  $row
     * @return CatalogEntry
     */
    private static function catalogEntry(array $row): array
    {
        $services = is_array($row['Services'] ?? null) ? $row['Services'] : [];
        $services = array_values(array_filter(array_map(strval(...), $services), fn (string $service): bool => $service !== ''));
        sort($services);

        return [
            'name' => (string) ($row['Name'] ?? ''),
            'type' => (string) ($row['Type'] ?? 'gauge'),
            'unit' => (string) ($row['Unit'] ?? ''),
            'description' => (string) ($row['Description'] ?? ''),
            'services' => $services,
            'monotonic' => (bool) ($row['Monotonic'] ?? false),
            'temporality' => (int) ($row['Temporality'] ?? 0),
            'points' => (int) ($row['Points'] ?? 0),
        ];
    }
}
