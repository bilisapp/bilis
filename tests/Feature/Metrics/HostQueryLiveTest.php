<?php

use App\Services\ClickHouse\ClickHouseClient;
use App\Services\ClickHouse\ClickHouseException;
use App\Services\Ingest\MetricWriter;
use App\Services\Ingest\OtlpMetricsMapper;
use App\Services\Metrics\HostCharts;
use App\Services\Metrics\MetricFilters;
use App\Services\Metrics\MetricQuery;
use Illuminate\Support\Carbon;

/*
 * The Hosts tab's SQL against a real server: the host rows (CPU from counter
 * deltas, memory and disk ratios, load, containers) and the curated charts,
 * which filter and group on resource attributes (`host.name`,
 * `container.name`) through MetricQuery::attribute()'s fallback.
 *
 * Two hosts shaped like the host-metrics and docker_stats receivers, with
 * answers worked out by hand. Skipped unless a server is reachable, like
 * MetricQueryLiveTest; rows go under a throwaway ProjectId.
 */
beforeEach(function () {
    $client = app(ClickHouseClient::class);

    try {
        $client->select('SELECT 1 FROM otel_metrics_sum LIMIT 1');
    } catch (ClickHouseException) {
        $this->markTestSkipped('No ClickHouse with the metrics tables reachable; set CLICKHOUSE_* to run the host query test.');
    }

    $this->client = $client;
    $this->projectId = '9'.random_int(100000, 999999);
    $this->to = Carbon::createFromTimestampUTC(intdiv(time(), 60) * 60);
    $this->from = $this->to->clone()->subHour();

    $mapped = (new OtlpMetricsMapper)->map(hostMetricsExport($this->from->getTimestamp()), $this->projectId);
    app(MetricWriter::class)->write($mapped->rows);

    for ($attempt = 0; $attempt < 50; $attempt++) {
        $rows = $client->select('SELECT count() AS c FROM otel_metrics_gauge WHERE ProjectId = {p:String}', ['p' => $this->projectId]);

        if ((int) $rows[0]['c'] >= 61 * 5) {
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
 * An hour of host metrics, one point a minute:
 *
 * - web-1: two CPUs, each 15 s user + 45 s idle a minute (25 % busy, and
 *   `system.cpu.utilization` reported too); 3 GiB used of 4; `/` 80 % full and
 *   `/data` 10 %; load 1.5; containers `app` (0.5 cores) and `db` (0.25).
 * - db-1: one CPU, 30 s user + 30 s idle (50 %), no utilization metric, so its
 *   CPU chart falls back to the counter; 1 GiB used of 2; load 0.2.
 * - an app's own gauge with no `host.name`, which is not a host.
 *
 * @return array<string, mixed>
 */
function hostMetricsExport(int $start): array
{
    $kv = fn (string $key, string $value): array => ['key' => $key, 'value' => ['stringValue' => $value]];
    $ns = fn (int $seconds): string => (string) ($seconds * 1_000_000_000);
    $minutes = range(0, 60);
    $boot = $start - 3600;
    $resource = fn (array $attributes): array => ['attributes' => array_map(fn (string $key) => $kv($key, $attributes[$key]), array_keys($attributes))];

    $cumulative = fn (array $attributes, callable $value): array => array_map(fn (int $m): array => [
        'attributes' => array_map(fn (string $key) => $kv($key, $attributes[$key]), array_keys($attributes)),
        'startTimeUnixNano' => $ns($boot),
        'timeUnixNano' => $ns($start + $m * 60),
        'asDouble' => $value($m),
    ], $minutes);

    $gauge = fn (array $attributes, float $value): array => array_map(fn (int $m): array => [
        'attributes' => array_map(fn (string $key) => $kv($key, $attributes[$key]), array_keys($attributes)),
        'timeUnixNano' => $ns($start + $m * 60),
        'asDouble' => $value,
    ], $minutes);

    $sum = fn (string $name, string $unit, bool $monotonic, array $points): array => [
        'name' => $name, 'unit' => $unit, 'sum' => ['aggregationTemporality' => 2, 'isMonotonic' => $monotonic, 'dataPoints' => $points],
    ];

    $cpuTime = fn (array $cpus, float $user, float $idle): array => array_merge(...array_map(fn (string $cpu): array => [
        ...$cumulative(['cpu' => $cpu, 'state' => 'user'], fn (int $m): float => 1000 + $user * $m),
        ...$cumulative(['cpu' => $cpu, 'state' => 'idle'], fn (int $m): float => 5000 + $idle * $m),
    ], $cpus));

    $gib = 1024 ** 3;

    return ['resourceMetrics' => [
        [
            'resource' => $resource(['service.name' => 'host', 'host.name' => 'web-1']),
            'scopeMetrics' => [['metrics' => [
                $sum('system.cpu.time', 's', true, $cpuTime(['cpu0', 'cpu1'], 15, 45)),
                ['name' => 'system.cpu.utilization', 'unit' => '1', 'gauge' => ['dataPoints' => [
                    ...$gauge(['cpu' => 'cpu0', 'state' => 'user'], 0.25), ...$gauge(['cpu' => 'cpu0', 'state' => 'idle'], 0.75),
                    ...$gauge(['cpu' => 'cpu1', 'state' => 'user'], 0.25), ...$gauge(['cpu' => 'cpu1', 'state' => 'idle'], 0.75),
                ]]],
                $sum('system.memory.usage', 'By', false, [
                    ...$cumulative(['state' => 'used'], fn (): float => 3 * $gib),
                    ...$cumulative(['state' => 'free'], fn (): float => 1 * $gib),
                ]),
                $sum('system.filesystem.usage', 'By', false, [
                    ...$cumulative(['device' => '/dev/sda1', 'mountpoint' => '/', 'state' => 'used'], fn (): float => 80),
                    ...$cumulative(['device' => '/dev/sda1', 'mountpoint' => '/', 'state' => 'free'], fn (): float => 20),
                    ...$cumulative(['device' => '/dev/sda1', 'mountpoint' => '/', 'state' => 'reserved'], fn (): float => 5),
                    ...$cumulative(['device' => '/dev/sdb1', 'mountpoint' => '/data', 'state' => 'used'], fn (): float => 10),
                    ...$cumulative(['device' => '/dev/sdb1', 'mountpoint' => '/data', 'state' => 'free'], fn (): float => 90),
                ]),
                ['name' => 'system.cpu.load_average.1m', 'unit' => '{thread}', 'gauge' => ['dataPoints' => $gauge([], 1.5)]],
            ]]],
        ],
        ...array_map(fn (string $container, float $cores): array => [
            'resource' => $resource(['service.name' => 'docker', 'host.name' => 'web-1', 'container.name' => $container]),
            'scopeMetrics' => [['metrics' => [
                $sum('container.cpu.usage.total', 'ns', true, $cumulative([], fn (int $m): float => $cores * 60e9 * $m)),
            ]]],
        ], ['app', 'db'], [0.5, 0.25]),
        [
            'resource' => $resource(['service.name' => 'host', 'host.name' => 'db-1']),
            'scopeMetrics' => [['metrics' => [
                $sum('system.cpu.time', 's', true, $cpuTime(['cpu0'], 30, 30)),
                $sum('system.memory.usage', 'By', false, [
                    ...$cumulative(['state' => 'used'], fn (): float => 1 * $gib),
                    ...$cumulative(['state' => 'free'], fn (): float => 1 * $gib),
                ]),
                ['name' => 'system.cpu.load_average.1m', 'unit' => '{thread}', 'gauge' => ['dataPoints' => $gauge([], 0.2)]],
            ]]],
        ],
        [
            'resource' => $resource(['service.name' => 'checkout']),
            'scopeMetrics' => [['metrics' => [
                ['name' => 'queue.depth', 'unit' => '{job}', 'gauge' => ['dataPoints' => $gauge([], 3)]],
            ]]],
        ],
    ]];
}

/**
 * The distinct non-null values of one line of a chart.
 *
 * @param  array<string, mixed>  $result
 * @return list<float>
 */
function hostLine(array $result, string $label): array
{
    foreach ($result['series'] as $series) {
        if ($series['label'] === $label) {
            return array_values(array_unique(array_filter($series['points'], fn (?float $point): bool => $point !== null)));
        }
    }

    return [];
}

it('lists every host with how it is doing now', function () {
    $query = app(MetricQuery::class);
    $window = new MetricFilters(from: $this->from, to: $this->to);
    $ids = [$this->projectId];

    $result = $query->hosts($ids, $window);

    expect($result['unavailable'])->toBeFalse()
        ->and(array_column($result['hosts'], 'name'))->toBe(['db-1', 'web-1']);

    [$db, $web] = $result['hosts'];

    expect($web)->toMatchArray([
        'cpu' => 0.25, 'memory' => 0.75, 'disk' => 0.8, 'diskMount' => '/', 'load' => 1.5, 'containers' => 2,
    ])->and($web['lastSeen'])->toBe($this->to->toIso8601String());

    expect($db)->toMatchArray([
        'cpu' => 0.5, 'memory' => 0.5, 'disk' => null, 'diskMount' => null, 'load' => 0.2, 'containers' => 0,
    ]);

    expect($query->hasHosts($ids))->toBeTrue()
        ->and($query->hasHosts(['no-such-project']))->toBeFalse();
});

it('charts one host through resource attributes, falling back per host', function () {
    $query = app(MetricQuery::class);
    $window = new MetricFilters(from: $this->from, to: $this->to);
    $ids = [$this->projectId];

    $web = collect(HostCharts::charts($query, $ids, $window, 'web-1', withContainers: true)['charts'])->keyBy('id');

    expect($web->keys()->all())->toBe(['cpu', 'load', 'memory', 'disk-space', 'container-cpu'])
        ->and($web['cpu']['metric'])->toBe('system.cpu.utilization')
        ->and($web['cpu']['series']['unit'])->toBe('%')
        ->and(hostLine($web['cpu']['series'], 'user'))->toBe([25.0])
        ->and(hostLine($web['cpu']['series'], 'idle'))->toBe([75.0])
        ->and(hostLine($web['load']['series'], 'system.cpu.load_average.1m'))->toBe([1.5])
        ->and(hostLine($web['memory']['series'], 'used'))->toBe([(float) 3 * 1024 ** 3])
        ->and(hostLine($web['disk-space']['series'], '/'))->toBe([80.0])
        ->and(hostLine($web['disk-space']['series'], '/data'))->toBe([10.0])
        ->and($web['container-cpu']['series']['unit'])->toBe('{core}')
        ->and($web['container-cpu']['series']['kind'])->toBe('value')
        ->and(hostLine($web['container-cpu']['series'], 'app'))->toBe([0.5])
        ->and(hostLine($web['container-cpu']['series'], 'db'))->toBe([0.25]);

    // The utilization gauge exists — on web-1 — so db-1's CPU chart falls
    // through to the counter, as cores busy, rather than drawing empty.
    $db = collect(HostCharts::charts($query, $ids, $window, 'db-1', withContainers: false)['charts'])->keyBy('id');

    expect($db['cpu']['metric'])->toBe('system.cpu.time')
        ->and($db['cpu']['series']['unit'])->toBe('{core}')
        ->and(hostLine($db['cpu']['series'], 'user'))->toBe([0.5])
        ->and(hostLine($db['load']['series'], 'system.cpu.load_average.1m'))->toBe([0.2])
        ->and($db->has('container-cpu'))->toBeFalse();

    // The explorer offers resource keys beside the point's own.
    $keys = array_column($query->attributes($ids, new MetricFilters(metric: 'container.cpu.usage.total', from: $this->from, to: $this->to))['attributes'], 'key');

    expect($keys)->toContain('host.name', 'container.name')
        ->not->toContain('service.name');
});
