<?php

use App\Services\ClickHouse\ClickHouseClient;
use App\Services\ClickHouse\ClickHouseException;
use App\Services\Ingest\MetricWriter;
use App\Services\Ingest\OtlpMetricsMapper;
use App\Services\Metrics\MetricFilters;
use App\Services\Metrics\MetricQuery;
use Illuminate\Support\Carbon;

/*
 * The explorer's SQL against a real server: every shape `MetricQuery::series`
 * reads, over an hour of synthetic points whose answers are known by hand.
 * The unit tests cover the arithmetic; this covers the statements — argMax per
 * series, sumForEach over bucket arrays, ARRAY JOIN over the quantile columns,
 * the series cap — which a faked HTTP call accepts whatever they say.
 *
 * Skipped unless a server is reachable, like LiveIngestRoundTripTest. Rows go
 * under a throwaway ProjectId and are deleted afterwards.
 */
beforeEach(function () {
    $client = app(ClickHouseClient::class);

    try {
        $client->select('SELECT 1 FROM otel_metrics_sum LIMIT 1');
    } catch (ClickHouseException) {
        $this->markTestSkipped('No ClickHouse with the metrics tables reachable; set CLICKHOUSE_* to run the explorer query test.');
    }

    $this->client = $client;
    $this->projectId = '8'.random_int(100000, 999999);
    $this->to = Carbon::createFromTimestampUTC(intdiv(time(), 60) * 60);
    $this->from = $this->to->clone()->subHour();

    $mapped = (new OtlpMetricsMapper)->map(syntheticMetricsExport($this->from->getTimestamp()), $this->projectId);
    app(MetricWriter::class)->write($mapped->rows);

    // Async inserts: wait for the last table to fill.
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $rows = $client->select('SELECT count() AS c FROM otel_metrics_summary WHERE ProjectId = {p:String}', ['p' => $this->projectId]);

        if ((int) $rows[0]['c'] >= 61) {
            break;
        }

        usleep(100_000);
    }
});

afterEach(function () {
    if (! isset($this->client)) {
        return;
    }

    foreach (MetricQuery::TABLES as $table) {
        $this->client->execute(sprintf("ALTER TABLE %s DELETE WHERE ProjectId = '%s'", $table, $this->projectId));
    }
});

/**
 * One hour of points, one a minute, for every shape the explorer reads.
 *
 * @return array<string, mixed>
 */
function syntheticMetricsExport(int $start): array
{
    $kv = fn (string $key, string $value): array => ['key' => $key, 'value' => ['stringValue' => $value]];
    $ns = fn (int $seconds): string => (string) ($seconds * 1_000_000_000);
    $minutes = range(0, 60);

    $counter = [];

    foreach (['/checkout' => 3, '/cart' => 1] as $route => $perSecond) {
        foreach ($minutes as $m) {
            $time = $start + $m * 60;
            // /cart's process restarts a second before minute 30.
            $began = $route === '/cart' && $m >= 30 ? $start + 1799 : $start - 600;
            $counter[] = ['attributes' => [$kv('http.route', $route)], 'startTimeUnixNano' => $ns($began), 'timeUnixNano' => $ns($time), 'asInt' => (string) (($time - $began) * $perSecond)];
        }
    }

    $cumulative = fn (callable $point): array => array_map(fn (int $m): array => [
        'startTimeUnixNano' => $ns($start - 600),
        'timeUnixNano' => $ns($start + $m * 60),
        ...$point($m + 1),
    ], $minutes);

    return ['resourceMetrics' => [[
        'resource' => ['attributes' => [$kv('service.name', 'checkout')]],
        'scopeMetrics' => [['metrics' => [
            ['name' => 'http.server.requests', 'unit' => '{request}', 'sum' => ['aggregationTemporality' => 2, 'isMonotonic' => true, 'dataPoints' => $counter]],
            ['name' => 'process.memory.usage', 'unit' => 'By', 'gauge' => ['dataPoints' => array_merge(...array_map(fn (int $m): array => [
                ['attributes' => [$kv('pool', 'heap')], 'timeUnixNano' => $ns($start + $m * 60), 'asDouble' => 100 + $m],
                ['attributes' => [$kv('pool', 'stack')], 'timeUnixNano' => $ns($start + $m * 60), 'asDouble' => 10 + $m],
            ], $minutes))]],
            ['name' => 'jobs.processed', 'unit' => '{job}', 'sum' => ['aggregationTemporality' => 1, 'isMonotonic' => true, 'dataPoints' => array_map(
                fn (int $m): array => ['timeUnixNano' => $ns($start + $m * 60), 'asInt' => '120'],
                $minutes,
            )]],
            // Each minute adds 10 observations in (0, 0.1] and 10 in (0.1, 0.5].
            ['name' => 'http.server.request.duration', 'unit' => 's', 'histogram' => ['aggregationTemporality' => 2, 'dataPoints' => $cumulative(fn (int $k): array => [
                'count' => (string) (20 * $k), 'sum' => 4.0 * $k, 'bucketCounts' => [(string) (10 * $k), (string) (10 * $k), '0', '0'], 'explicitBounds' => [0.1, 0.5, 1],
            ])]],
            // Scale 0: each minute adds 4 in (1, 2] and 4 in (4, 8].
            ['name' => 'payload.size', 'unit' => 'By', 'exponentialHistogram' => ['aggregationTemporality' => 2, 'dataPoints' => $cumulative(fn (int $k): array => [
                'count' => (string) (8 * $k), 'scale' => 0, 'positive' => ['offset' => 0, 'bucketCounts' => [(string) (4 * $k), '0', (string) (4 * $k)]],
            ])]],
            ['name' => 'rpc.latency', 'unit' => 'ms', 'summary' => ['dataPoints' => array_map(fn (int $m): array => [
                'timeUnixNano' => $ns($start + $m * 60), 'count' => '100', 'sum' => 50.0,
                'quantileValues' => [['quantile' => 0.5, 'value' => 40], ['quantile' => 0.99, 'value' => 200]],
            ], $minutes)]],
        ]]],
    ]]];
}

/**
 * The distinct non-null values of one line.
 *
 * @param  array<string, mixed>  $result
 * @return list<float>
 */
function liveLine(array $result, string $label): array
{
    foreach ($result['series'] as $series) {
        if ($series['label'] === $label) {
            return array_values(array_unique(array_filter($series['points'], fn (?float $point): bool => $point !== null)));
        }
    }

    return [];
}

it('charts every metric shape with the values worked out by hand', function () {
    $query = app(MetricQuery::class);
    $filters = fn (string $metric, ?string $groupBy = null, string $aggregation = 'avg'): MetricFilters => new MetricFilters(
        metric: $metric, groupBy: $groupBy, aggregation: $aggregation, from: $this->from, to: $this->to,
    );
    $ids = [$this->projectId];

    expect(array_column($query->catalog($ids, $filters('x'))['metrics'], 'type', 'name'))->toBe([
        'http.server.request.duration' => 'histogram',
        'http.server.requests' => 'sum',
        'jobs.processed' => 'sum',
        'payload.size' => 'exponential_histogram',
        'process.memory.usage' => 'gauge',
        'rpc.latency' => 'summary',
    ]);

    $counter = $query->series($ids, $filters('http.server.requests', 'http.route'));

    // Steady rates, and the restart reads as a small positive value, never negative.
    expect($counter['kind'])->toBe('rate')
        ->and(liveLine($counter, '/checkout'))->toBe([3.0])
        ->and(min(liveLine($counter, '/cart')))->toBeGreaterThan(0)
        ->and(max(liveLine($counter, '/cart')))->toBe(1.0);

    expect(liveLine($query->series($ids, $filters('process.memory.usage', 'pool', 'max')), 'heap'))->toHaveCount(61)
        ->and(liveLine($query->series($ids, $filters('jobs.processed')), 'jobs.processed'))->toBe([2.0]);

    $histogram = $query->series($ids, $filters('http.server.request.duration'));

    expect(liveLine($histogram, 'p50'))->toBe([0.1])
        ->and(liveLine($histogram, 'p95'))->toBe([0.46])
        ->and(liveLine($histogram, 'p99'))->toBe([0.492]);

    $exponential = $query->series($ids, $filters('payload.size'));

    expect(liveLine($exponential, 'p50'))->toBe([2.0])
        ->and(liveLine($exponential, 'p95'))->toBe([7.6]);

    $summary = $query->series($ids, $filters('rpc.latency'));

    expect($summary['approximate'])->toBeTrue()
        ->and(liveLine($summary, 'p99'))->toBe([200.0]);

    expect($query->attributes($ids, $filters('http.server.requests'))['attributes'][0]['key'])->toBe('http.route')
        ->and($query->hasAnyMetrics($ids))->toBeTrue()
        ->and($query->hasAnyMetrics(['no-such-project']))->toBeFalse();
});
