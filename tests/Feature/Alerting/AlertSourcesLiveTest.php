<?php

use App\Models\AlertRule;
use App\Services\Alerting\Sources\AlertSourceUnavailable;
use App\Services\Alerting\Sources\LogAlertSource;
use App\Services\Alerting\Sources\MetricAlertSource;
use App\Services\Alerting\Sources\TraceAlertSource;
use App\Services\ClickHouse\ClickHouseClient;
use App\Services\ClickHouse\ClickHouseException;
use App\Services\Ingest\MetricWriter;
use App\Services\Ingest\OtlpMetricsMapper;
use App\Services\Ingest\SpanWriter;
use App\Services\Metrics\MetricQuery;
use Carbon\CarbonImmutable;

/*
 * The three alert sources against a real server. A fake accepts any SQL; this
 * proves the statements: the ProjectId predicate (a second project's rows sit
 * in the same window and are never counted), the closed-open window (a row
 * exactly at `to` is out, one exactly at `from` is in), R5's case-folded body
 * search, R10's `StatusCode = 'Error'`, and a cumulative counter read as a rate.
 *
 * Skipped unless a server is reachable. Rows go under throwaway project ids
 * and are deleted afterwards.
 */
beforeEach(function () {
    $client = app(ClickHouseClient::class);

    try {
        $client->select('SELECT 1 FROM otel_metrics_gauge LIMIT 1');
    } catch (ClickHouseException) {
        $this->markTestSkipped('No ClickHouse reachable; set CLICKHOUSE_* to run the alert source test.');
    }

    $this->client = $client;
    $this->projectId = '7'.random_int(100000, 999999);
    $this->otherProjectId = '6'.random_int(100000, 999999);

    // Whole minutes, a few minutes back: inside every TTL and every bucket aligned.
    $this->to = CarbonImmutable::createFromTimestampUTC(intdiv(time(), 60) * 60 - 120);
});

afterEach(function () {
    if (! isset($this->client)) {
        return;
    }

    $tables = ['otel_logs', 'otel_traces', 'trace_summary', 'trace_index', ...array_values(MetricQuery::TABLES)];

    foreach ($tables as $table) {
        $this->client->execute(sprintf(
            "ALTER TABLE %s DELETE WHERE ProjectId IN ('%s', '%s')",
            $table,
            $this->projectId,
            $this->otherProjectId,
        ));
    }
});

/**
 * Evaluate a rule of the given source over `[from, to)` for the test's own project.
 *
 * @param  array<string, mixed>  $query
 * @return list<array{labels: array<string, string>, value: float}>
 */
function alertLiveEvaluate(object $test, string $source, array $query, CarbonImmutable $from): array
{
    $rule = AlertRule::factory()->make(['source' => $source, 'query' => $query]);

    return app(config("alerting.sources.{$source}"))->evaluate($rule, [$test->projectId], $from, $test->to);
}

/**
 * Instances as `label value => value`, rounded, for order-free comparison.
 *
 * @param  list<array{labels: array<string, string>, value: float}>  $instances
 * @return array<string, float>
 */
function alertLiveByLabel(array $instances, string $key): array
{
    $values = [];

    foreach ($instances as $instance) {
        $values[$instance['labels'][$key]] = round($instance['value'], 4);
    }

    ksort($values);

    return $values;
}

/**
 * @return array<string, mixed>
 */
function alertLiveLog(string $projectId, CarbonImmutable $at, string $service, int $severity, string $body): array
{
    return [
        'ProjectId' => $projectId,
        'Timestamp' => $at->format('Y-m-d H:i:s.u'),
        'ServiceName' => $service,
        'SeverityText' => match (true) {
            $severity >= 17 => 'ERROR',
            $severity >= 13 => 'WARN',
            default => 'INFO',
        },
        'SeverityNumber' => $severity,
        'Body' => $body,
        'ResourceAttributes' => (object) [],
        'ScopeAttributes' => (object) [],
        'LogAttributes' => (object) [],
    ];
}

/**
 * @return array<string, mixed>
 */
function alertLiveSpan(string $projectId, CarbonImmutable $at, string $service, string $name, string $kind, int $durationMs, string $status): array
{
    return [
        'Timestamp' => $at->format('Y-m-d H:i:s.u'),
        'TraceId' => bin2hex(random_bytes(16)),
        'SpanId' => bin2hex(random_bytes(8)),
        'ParentSpanId' => '',
        'TraceState' => '',
        'SpanName' => $name,
        'SpanKind' => $kind,
        'ServiceName' => $service,
        'ResourceAttributes' => [],
        'ScopeName' => '',
        'ScopeVersion' => '',
        'SpanAttributes' => [],
        'Duration' => $durationMs * 1_000_000,
        'StatusCode' => $status,
        'StatusMessage' => '',
        'Events.Timestamp' => [],
        'Events.Name' => [],
        'Events.Attributes' => [],
        'Links.TraceId' => [],
        'Links.SpanId' => [],
        'Links.TraceState' => [],
        'Links.Attributes' => [],
        'ProjectId' => $projectId,
    ];
}

describe('logs', function () {
    beforeEach(function () {
        $from = $this->to->subMinutes(5);
        $this->from = $from;

        $this->client->insert('otel_logs', [
            alertLiveLog($this->projectId, $from, 'checkout', 17, 'Payment TIMEOUT upstream'),
            alertLiveLog($this->projectId, $from->addMinute(), 'checkout', 9, 'ok'),
            alertLiveLog($this->projectId, $from->addMinutes(2), 'checkout', 9, 'ok'),
            alertLiveLog($this->projectId, $from->addMinutes(3), 'api', 17, 'db timeout'),
            alertLiveLog($this->projectId, $from->addMinutes(4), 'api', 13, 'slow query'),
            // Outside [from, to): a microsecond before, and exactly at the end.
            alertLiveLog($this->projectId, $from->subMicrosecond(), 'checkout', 17, 'timeout before'),
            alertLiveLog($this->projectId, $this->to, 'checkout', 17, 'timeout at the end'),
            // Another project's errors, in the same window.
            alertLiveLog($this->otherProjectId, $from->addMinute(), 'checkout', 17, 'timeout elsewhere'),
            alertLiveLog($this->otherProjectId, $from->addMinutes(2), 'checkout', 17, 'timeout elsewhere'),
        ]);
    });

    it('counts, rates and ratios inside [from, to) for its own projects only', function () {
        $evaluate = fn (array $query): array => alertLiveEvaluate($this, 'logs', $query, $this->from);

        expect($evaluate(['aggregation' => 'count']))->toBe([['labels' => [], 'value' => 5.0]])
            ->and($evaluate(['aggregation' => 'count', 'filters' => ['severities' => ['error']]]))->toBe([['labels' => [], 'value' => 2.0]])
            ->and($evaluate(['aggregation' => 'rate']))->toBe([['labels' => [], 'value' => 1.0]])
            ->and($evaluate(['aggregation' => 'ratio', 'filters' => ['severities' => ['error']]]))->toBe([['labels' => [], 'value' => 0.4]])
            // The services filter narrows the denominator too; severity only the numerator.
            ->and(round($evaluate(['aggregation' => 'ratio', 'filters' => ['services' => ['checkout'], 'severities' => ['error']]])[0]['value'], 4))->toBe(0.3333);
    });

    it('groups by service with the service as the label', function () {
        $evaluate = fn (array $query): array => alertLiveEvaluate($this, 'logs', [...$query, 'group_by' => 'service.name'], $this->from);

        expect(alertLiveByLabel($evaluate(['aggregation' => 'count']), 'service.name'))->toBe(['api' => 2.0, 'checkout' => 3.0])
            ->and(alertLiveByLabel($evaluate(['aggregation' => 'ratio', 'filters' => ['severities' => ['error', 'fatal']]]), 'service.name'))->toBe(['api' => 0.5, 'checkout' => 0.3333]);
    });

    it('keeps the top max_instances groups by value', function () {
        config(['alerting.max_instances' => 1]);

        expect(alertLiveEvaluate($this, 'logs', ['aggregation' => 'count', 'group_by' => 'service.name'], $this->from))
            ->toBe([['labels' => ['service.name' => 'checkout'], 'value' => 3.0]]);
    });

    it('searches the body case-insensitively, by token and by substring', function () {
        $count = fn (string $search): float => alertLiveEvaluate($this, 'logs', ['aggregation' => 'count', 'filters' => ['search' => $search]], $this->from)[0]['value'];

        expect($count('timeout'))->toBe(2.0)
            ->and($count('TimeOut'))->toBe(2.0)
            ->and($count('DB TIME'))->toBe(1.0)
            ->and($count('nothing-matches-this'))->toBe(0.0);
    });

    it('reads an empty window as zero, not as no data', function () {
        $rule = AlertRule::factory()->make(['source' => 'logs', 'query' => ['aggregation' => 'count']]);
        $source = app(LogAlertSource::class);
        $from = $this->from->subHour();

        expect($source->evaluate($rule, [$this->projectId], $from, $from->addMinutes(5)))
            ->toBe([['labels' => [], 'value' => 0.0]]);
    });

    it('previews the same reading per bucket', function () {
        $rule = AlertRule::factory()->make(['source' => 'logs', 'query' => ['aggregation' => 'count', 'group_by' => 'service.name']]);
        $lines = app(LogAlertSource::class)->preview($rule, [$this->projectId], $this->from, $this->to, 60);

        $byService = [];

        foreach ($lines as $line) {
            $byService[$line['labels']['service.name']] = array_column($line['points'], 1, 0);
        }

        $start = $this->from->getTimestamp();

        expect(array_keys($byService))->toEqualCanonicalizing(['api', 'checkout'])
            ->and($byService['checkout'])->toBe([$start => 1.0, $start + 60 => 1.0, $start + 120 => 1.0, $start + 180 => 0.0, $start + 240 => 0.0])
            ->and($byService['api'])->toBe([$start => 0.0, $start + 60 => 0.0, $start + 120 => 0.0, $start + 180 => 1.0, $start + 240 => 1.0]);
    });
});

describe('traces', function () {
    beforeEach(function () {
        $from = $this->to->subMinutes(5);
        $this->from = $from;

        $this->client->insert('otel_traces', SpanWriter::normalise([
            alertLiveSpan($this->projectId, $from, 'api', 'GET /pay', 'Server', 100, 'Error'),
            alertLiveSpan($this->projectId, $from->addMinute(), 'api', 'GET /pay', 'Server', 200, 'Unset'),
            alertLiveSpan($this->projectId, $from->addMinutes(2), 'api', 'SELECT', 'Client', 300, 'Ok'),
            alertLiveSpan($this->projectId, $from->addMinutes(3), 'web', 'GET /', 'Server', 400, 'Error'),
            alertLiveSpan($this->projectId, $this->to, 'api', 'GET /pay', 'Server', 10_000, 'Error'),
            alertLiveSpan($this->otherProjectId, $from->addMinute(), 'web', 'GET /', 'Server', 5_000, 'Error'),
            alertLiveSpan($this->otherProjectId, $from->addMinutes(2), 'web', 'GET /', 'Server', 5_000, 'Error'),
        ]));
    });

    it('counts spans, error ratio and percentiles in milliseconds', function () {
        $evaluate = fn (array $query): array => alertLiveEvaluate($this, 'traces', $query, $this->from);

        expect($evaluate(['aggregation' => 'count']))->toBe([['labels' => [], 'value' => 4.0]])
            ->and($evaluate(['aggregation' => 'error_ratio']))->toBe([['labels' => [], 'value' => 0.5]])
            ->and($evaluate(['aggregation' => 'count', 'filters' => ['span_name' => 'GET /pay']]))->toBe([['labels' => [], 'value' => 2.0]])
            ->and($evaluate(['aggregation' => 'p50', 'filters' => ['services' => ['api'], 'kind' => 'Server']]))->toBe([['labels' => [], 'value' => 150.0]])
            ->and($evaluate(['aggregation' => 'p99'])[0]['value'])->toEqualWithDelta(397.0, 1.0);
    });

    it('groups by service', function () {
        $evaluate = fn (array $query): array => alertLiveEvaluate($this, 'traces', [...$query, 'group_by' => 'service.name'], $this->from);

        expect(alertLiveByLabel($evaluate(['aggregation' => 'error_ratio']), 'service.name'))->toBe(['api' => 0.3333, 'web' => 1.0])
            ->and(alertLiveByLabel($evaluate(['aggregation' => 'p95']), 'service.name'))->toBe(['api' => 290.0, 'web' => 400.0]);
    });

    it('has no percentile or ratio for a window without spans', function () {
        $from = $this->from->subMinutes(30);
        $to = $from->addMinutes(5);
        $rule = fn (string $aggregation): AlertRule => AlertRule::factory()->make(['source' => 'traces', 'query' => ['aggregation' => $aggregation]]);
        $source = app(TraceAlertSource::class);

        expect($source->evaluate($rule('p95'), [$this->projectId], $from, $to))->toBe([])
            ->and($source->evaluate($rule('error_ratio'), [$this->projectId], $from, $to))->toBe([])
            ->and($source->evaluate($rule('count'), [$this->projectId], $from, $to))->toBe([['labels' => [], 'value' => 0.0]]);
    });
});

describe('metrics', function () {
    beforeEach(function () {
        $this->from = $this->to->subMinutes(10);

        $kv = fn (string $key, string $value): array => ['key' => $key, 'value' => ['stringValue' => $value]];
        $ns = fn (int $seconds): string => (string) ($seconds * 1_000_000_000);
        $from = $this->from->getTimestamp();
        $to = $this->to->getTimestamp();

        // Minutes -3 .. +10 around `from`: before it, inside the window, and exactly `to`.
        $minutes = range(-3, 10);
        $gauge = fn (callable $value): array => ['dataPoints' => array_map(
            fn (int $k): array => ['timeUnixNano' => $ns($from + $k * 60), 'asDouble' => $value($k, $from + $k * 60)],
            $minutes,
        )];

        $resource = fn (string $host, array $metrics): array => [
            'resource' => ['attributes' => [$kv('service.name', 'agent'), $kv('host.name', $host)]],
            'scopeMetrics' => [['metrics' => $metrics]],
        ];

        $edges = fn (float $inside): Closure => fn (int $k, int $at): float => $at === $to ? 99.0 : ($k < 0 ? 50.0 : $inside);

        $export = ['resourceMetrics' => [
            $resource('web-1', [
                ['name' => 'system.cpu.utilization', 'gauge' => $gauge(fn (int $k, int $at): float => $edges(0.5)($k, $at))],
                // 3 a second since long before the read; cumulative, as the SDK sends it.
                ['name' => 'http.server.requests', 'sum' => ['aggregationTemporality' => 2, 'isMonotonic' => true, 'dataPoints' => array_map(
                    fn (int $k): array => ['startTimeUnixNano' => $ns($from - 3600), 'timeUnixNano' => $ns($from + $k * 60), 'asInt' => (string) ((3600 + $k * 60) * 3)],
                    $minutes,
                )]],
                // Each minute adds 10 observations in (0, 0.1] and 10 in (0.1, 0.5].
                ['name' => 'http.server.request.duration', 'unit' => 's', 'histogram' => ['aggregationTemporality' => 2, 'dataPoints' => array_map(
                    fn (int $k): array => [
                        'startTimeUnixNano' => $ns($from - 3600), 'timeUnixNano' => $ns($from + $k * 60),
                        'count' => (string) (20 * ($k + 10)), 'sum' => 4.0 * ($k + 10),
                        'bucketCounts' => [(string) (10 * ($k + 10)), (string) (10 * ($k + 10)), '0'], 'explicitBounds' => [0.1, 0.5],
                    ],
                    $minutes,
                )]],
            ]),
            // 0.1 at `from`, rising 0.1 a minute to 1.0 at the last minute inside the window.
            $resource('web-2', [['name' => 'system.cpu.utilization', 'gauge' => $gauge(fn (int $k, int $at): float => $edges(round(0.1 * ($k + 1), 1))($k, $at))]]),
        ]];

        $writer = app(MetricWriter::class);
        $writer->write((new OtlpMetricsMapper)->map($export, $this->projectId)->rows);

        // Another project: same metric, same host, far higher.
        $other = ['resourceMetrics' => [$resource('web-1', [['name' => 'system.cpu.utilization', 'gauge' => $gauge(fn (): float => 1000.0)]])]];
        $writer->write((new OtlpMetricsMapper)->map($other, $this->otherProjectId)->rows);
    });

    it('reduces each host\'s gauge over [from, to) for its own projects only', function () {
        $byHost = fn (string $aggregation): array => alertLiveByLabel(
            alertLiveEvaluate($this, 'metrics', ['metric' => 'system.cpu.utilization', 'aggregation' => $aggregation, 'group_by' => 'host.name'], $this->from),
            'host.name',
        );

        // web-2's 0.1 sits exactly at `from`; 50 before it and 99 at `to` never count.
        expect($byHost('avg'))->toBe(['web-1' => 0.5, 'web-2' => 0.55])
            ->and($byHost('max'))->toBe(['web-1' => 0.5, 'web-2' => 1.0])
            ->and($byHost('min'))->toBe(['web-1' => 0.5, 'web-2' => 0.1])
            ->and($byHost('last'))->toBe(['web-1' => 0.5, 'web-2' => 1.0]);
    });

    it('filters on a resource attribute and reads ungrouped', function () {
        $evaluate = fn (array $query): array => alertLiveEvaluate($this, 'metrics', ['metric' => 'system.cpu.utilization', ...$query], $this->from);

        expect($evaluate(['aggregation' => 'max', 'filters' => ['host.name' => 'web-1']]))->toBe([['labels' => [], 'value' => 0.5]])
            ->and($evaluate(['aggregation' => 'max']))->toBe([['labels' => [], 'value' => 1.0]]);
    });

    it('reads a cumulative counter as a rate', function () {
        $instances = alertLiveEvaluate($this, 'metrics', ['metric' => 'http.server.requests', 'aggregation' => 'avg'], $this->from);

        expect($instances)->toHaveCount(1)
            ->and($instances[0]['labels'])->toBe([])
            ->and($instances[0]['value'])->toEqualWithDelta(3.0, 0.0001);
    });

    it('reads a histogram as a percentile and refuses a mismatched aggregation', function () {
        $instances = alertLiveEvaluate($this, 'metrics', ['metric' => 'http.server.request.duration', 'aggregation' => 'p50'], $this->from);

        expect($instances)->toHaveCount(1)
            ->and($instances[0]['value'])->toEqualWithDelta(0.1, 0.0001)
            ->and(fn () => alertLiveEvaluate($this, 'metrics', ['metric' => 'http.server.request.duration', 'aggregation' => 'avg'], $this->from))
            ->toThrow(AlertSourceUnavailable::class)
            ->and(fn () => alertLiveEvaluate($this, 'metrics', ['metric' => 'system.cpu.utilization', 'aggregation' => 'p95'], $this->from))
            ->toThrow(AlertSourceUnavailable::class);
    });

    it('answers no data for a metric that was never reported', function () {
        expect(alertLiveEvaluate($this, 'metrics', ['metric' => 'no.such.metric', 'aggregation' => 'avg'], $this->from))->toBe([]);
    });

    it('previews the bucketed line inside [from, to)', function () {
        $rule = AlertRule::factory()->make(['source' => 'metrics', 'query' => ['metric' => 'system.cpu.utilization', 'aggregation' => 'max', 'group_by' => 'host.name']]);
        $lines = app(MetricAlertSource::class)->preview($rule, [$this->projectId], $this->from, $this->to, 60);

        $web2 = collect($lines)->firstWhere('labels', ['host.name' => 'web-2']);

        expect($lines)->toHaveCount(2)
            ->and(array_column($web2['points'], 0))->toBe(range($this->from->getTimestamp(), $this->to->getTimestamp() - 60, 60))
            ->and(array_map(fn (array $point): float => round((float) $point[1], 1), $web2['points']))->toBe([0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7, 0.8, 0.9, 1.0]);
    });
});

describe('baseline comparison and minimum volume (logs)', function () {
    beforeEach(function () {
        $from = $this->to->subMinutes(5);
        $baselineFrom = $from->subDay();
        $baselineTo = $this->to->subDay();
        $this->from = $from;

        $this->client->insert('otel_logs', [
            // The current window: checkout 6, api 2, web 1.
            alertLiveLog($this->projectId, $from, 'checkout', 17, 'boom'),
            alertLiveLog($this->projectId, $from, 'checkout', 17, 'boom'),
            alertLiveLog($this->projectId, $from->addMinute(), 'checkout', 17, 'boom'),
            alertLiveLog($this->projectId, $from->addMinute(), 'checkout', 17, 'boom'),
            alertLiveLog($this->projectId, $from->addMinutes(2), 'checkout', 17, 'boom'),
            alertLiveLog($this->projectId, $from->addMinutes(2), 'checkout', 17, 'boom'),
            alertLiveLog($this->projectId, $from->addMinutes(3), 'api', 17, 'boom'),
            alertLiveLog($this->projectId, $from->addMinutes(4), 'api', 17, 'boom'),
            alertLiveLog($this->projectId, $from->addMinutes(4), 'web', 17, 'boom'),
            alertLiveLog($this->projectId, $this->to, 'checkout', 17, 'at the end: out'),
            // The baseline, a day earlier: checkout 2 (both at its `from`), no api, no web.
            alertLiveLog($this->projectId, $baselineFrom, 'checkout', 17, 'then'),
            alertLiveLog($this->projectId, $baselineFrom, 'checkout', 17, 'then'),
            // Outside the baseline window: a microsecond before it, and exactly at its end.
            alertLiveLog($this->projectId, $baselineFrom->subMicrosecond(), 'checkout', 17, 'before: out'),
            alertLiveLog($this->projectId, $baselineTo, 'api', 17, 'at the end: out'),
            // Another project's baseline is never read.
            alertLiveLog($this->otherProjectId, $baselineFrom->addMinute(), 'api', 17, 'elsewhere'),
            alertLiveLog($this->otherProjectId, $baselineFrom->addMinute(), 'checkout', 17, 'elsewhere'),
        ]);
    });

    it('divides the window by the same window a day earlier, ungrouped', function () {
        $compare = ['offset_seconds' => 86400];

        expect(alertLiveEvaluate($this, 'logs', ['aggregation' => 'count', 'compare' => $compare], $this->from))
            ->toBe([['labels' => [], 'value' => 4.5]])
            ->and(alertLiveEvaluate($this, 'logs', ['aggregation' => 'rate', 'compare' => $compare], $this->from))
            ->toBe([['labels' => [], 'value' => 4.5]]);
    });

    it('compares each group with its own baseline, floored at 1 when it had none', function () {
        expect(alertLiveByLabel(alertLiveEvaluate($this, 'logs', [
            'aggregation' => 'count',
            'group_by' => 'service.name',
            'compare' => ['offset_seconds' => 86400],
        ], $this->from), 'service.name'))->toBe(['api' => 2.0, 'checkout' => 3.0, 'web' => 1.0]);
    });

    it('drops a series below min_events as no data, compared or not', function () {
        expect(alertLiveByLabel(alertLiveEvaluate($this, 'logs', [
            'aggregation' => 'count',
            'group_by' => 'service.name',
            'min_events' => 2,
        ], $this->from), 'service.name'))->toBe(['api' => 2.0, 'checkout' => 6.0])
            // The guard reads the current window's count, not the ratio.
            ->and(alertLiveByLabel(alertLiveEvaluate($this, 'logs', [
                'aggregation' => 'count',
                'group_by' => 'service.name',
                'compare' => ['offset_seconds' => 86400],
                'min_events' => 3,
            ], $this->from), 'service.name'))->toBe(['checkout' => 3.0])
            ->and(alertLiveEvaluate($this, 'logs', ['aggregation' => 'count', 'min_events' => 10], $this->from))->toBe([]);
    });

    it('previews each bucket against the bucket one offset back', function () {
        $rule = AlertRule::factory()->make(['source' => 'logs', 'query' => [
            'aggregation' => 'count',
            'group_by' => 'service.name',
            'compare' => ['offset_seconds' => 86400],
        ]]);

        $lines = app(LogAlertSource::class)->preview($rule, [$this->projectId], $this->from, $this->to, 60);
        $byService = [];

        foreach ($lines as $line) {
            $byService[$line['labels']['service.name']] = array_values(array_column($line['points'], 1));
        }

        expect($byService['checkout'])->toBe([1.0, 2.0, 2.0, 0.0, 0.0])
            ->and($byService['api'])->toBe([0.0, 0.0, 0.0, 1.0, 1.0]);
    });
});

describe('baseline comparison and minimum volume (traces)', function () {
    beforeEach(function () {
        $from = $this->to->subMinutes(5);
        $baselineFrom = $from->subWeek();
        $this->from = $from;

        $this->client->insert('otel_traces', SpanWriter::normalise([
            // Now: api 4 spans at 1 s, half failing; web 1 failing span at 300 ms.
            alertLiveSpan($this->projectId, $from, 'api', 'GET /pay', 'Server', 1000, 'Error'),
            alertLiveSpan($this->projectId, $from->addMinute(), 'api', 'GET /pay', 'Server', 1000, 'Error'),
            alertLiveSpan($this->projectId, $from->addMinutes(2), 'api', 'GET /pay', 'Server', 1000, 'Unset'),
            alertLiveSpan($this->projectId, $from->addMinutes(3), 'api', 'GET /pay', 'Server', 1000, 'Unset'),
            alertLiveSpan($this->projectId, $from->addMinutes(4), 'web', 'GET /', 'Server', 300, 'Error'),
            // A week earlier: api 4 spans at 500 ms, one failing; no web.
            alertLiveSpan($this->projectId, $baselineFrom, 'api', 'GET /pay', 'Server', 500, 'Error'),
            alertLiveSpan($this->projectId, $baselineFrom->addMinute(), 'api', 'GET /pay', 'Server', 500, 'Unset'),
            alertLiveSpan($this->projectId, $baselineFrom->addMinutes(2), 'api', 'GET /pay', 'Server', 500, 'Unset'),
            alertLiveSpan($this->projectId, $baselineFrom->addMinutes(3), 'api', 'GET /pay', 'Server', 500, 'Unset'),
            // Outside the baseline window: exactly at its end.
            alertLiveSpan($this->projectId, $this->to->subWeek(), 'api', 'GET /pay', 'Server', 60_000, 'Error'),
        ]));
    });

    it('compares count, error ratio and p95 per service with the same window last week', function () {
        $evaluate = fn (string $aggregation): array => alertLiveByLabel(alertLiveEvaluate($this, 'traces', [
            'aggregation' => $aggregation,
            'group_by' => 'service.name',
            'compare' => ['offset_seconds' => 604800],
        ], $this->from), 'service.name');

        // web had no spans last week: a count is floored at 1, but a ratio or
        // a percentile has no "normal" to be a multiple of, so web has no data.
        expect($evaluate('error_ratio'))->toBe(['api' => 2.0])
            ->and($evaluate('p95'))->toBe(['api' => 2.0])
            ->and($evaluate('count'))->toBe(['api' => 1.0, 'web' => 1.0]);
    });

    it('compares ungrouped and drops a series below min_events', function () {
        expect(alertLiveEvaluate($this, 'traces', ['aggregation' => 'count', 'compare' => ['offset_seconds' => 604800]], $this->from))
            ->toBe([['labels' => [], 'value' => 1.25]])
            // A share never exceeds 1, so it is divided by the baseline share, not floored.
            ->and(alertLiveEvaluate($this, 'traces', ['aggregation' => 'error_ratio', 'filters' => ['services' => ['api']], 'compare' => ['offset_seconds' => 604800]], $this->from))
            ->toBe([['labels' => [], 'value' => 2.0]])
            ->and(alertLiveByLabel(alertLiveEvaluate($this, 'traces', [
                'aggregation' => 'error_ratio',
                'group_by' => 'service.name',
                'min_events' => 2,
            ], $this->from), 'service.name'))->toBe(['api' => 0.5]);
    });
});
