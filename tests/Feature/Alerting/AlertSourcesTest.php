<?php

use App\Models\AlertRule;
use App\Services\Alerting\Sources\AlertSource;
use App\Services\Alerting\Sources\AlertSourceUnavailable;
use App\Services\Alerting\Sources\LogAlertSource;
use App\Services\Alerting\Sources\MetricAlertSource;
use App\Services\Alerting\Sources\TraceAlertSource;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

/*
 * The three alert sources without a ClickHouse: their `query` validation, and
 * the contract that a failed read throws rather than returning [] — an empty
 * list is "no data" to the state machine, which can fire or resolve on it.
 * The SQL itself is proven in AlertSourcesLiveTest.
 */

/**
 * @return array<string, array{0: class-string<AlertSource>, 1: array<string, mixed>}>
 */
function validAlertQueries(): array
{
    return [
        'logs count' => [LogAlertSource::class, ['filters' => ['services' => ['checkout'], 'severities' => ['error', 'fatal'], 'search' => 'timeout'], 'aggregation' => 'count']],
        'logs ratio by service' => [LogAlertSource::class, ['aggregation' => 'ratio', 'group_by' => 'service.name']],
        'logs rate, no filters key' => [LogAlertSource::class, ['aggregation' => 'rate', 'group_by' => null]],
        'traces p95' => [TraceAlertSource::class, ['filters' => ['services' => ['api'], 'span_name' => 'GET /pay', 'kind' => 'Server'], 'aggregation' => 'p95']],
        'traces error ratio by service' => [TraceAlertSource::class, ['aggregation' => 'error_ratio', 'group_by' => 'service.name']],
        'metrics gauge' => [MetricAlertSource::class, ['metric' => 'system.cpu.utilization', 'aggregation' => 'max', 'group_by' => 'host.name']],
        'metrics with a dotted filter key' => [MetricAlertSource::class, ['metric' => 'http.server.request.duration', 'filters' => ['http.route' => '/pay', 'host.name' => 'web-1'], 'aggregation' => 'p99']],
    ];
}

/**
 * @return array<string, array{0: class-string<AlertSource>, 1: array<string, mixed>, 2: string}>
 */
function invalidAlertQueries(): array
{
    return [
        'logs unknown aggregation' => [LogAlertSource::class, ['aggregation' => 'p95'], 'query.aggregation'],
        'logs missing aggregation' => [LogAlertSource::class, ['filters' => []], 'query.aggregation'],
        'logs unknown severity' => [LogAlertSource::class, ['aggregation' => 'count', 'filters' => ['severities' => ['loud']]], 'query.filters.severities.0'],
        'logs services not a list' => [LogAlertSource::class, ['aggregation' => 'count', 'filters' => ['services' => 'checkout']], 'query.filters.services'],
        'logs group by an attribute' => [LogAlertSource::class, ['aggregation' => 'count', 'group_by' => 'http.route'], 'query.group_by'],
        'traces proto enum kind' => [TraceAlertSource::class, ['aggregation' => 'count', 'filters' => ['kind' => 'SPAN_KIND_SERVER']], 'query.filters.kind'],
        'traces unknown aggregation' => [TraceAlertSource::class, ['aggregation' => 'avg'], 'query.aggregation'],
        'metrics without a metric' => [MetricAlertSource::class, ['aggregation' => 'avg'], 'query.metric'],
        'metrics unknown aggregation' => [MetricAlertSource::class, ['metric' => 'm', 'aggregation' => 'sum'], 'query.aggregation'],
        'metrics too many filters' => [MetricAlertSource::class, ['metric' => 'm', 'aggregation' => 'avg', 'filters' => ['a' => '1', 'b' => '2', 'c' => '3', 'd' => '4', 'e' => '5', 'f' => '6']], 'query.filters'],
        'metrics non-string filter value' => [MetricAlertSource::class, ['metric' => 'm', 'aggregation' => 'avg', 'filters' => ['pool' => ['heap']]], 'query.filters.pool'],
    ];
}

/**
 * A rule for the given source, never saved.
 *
 * @param  array<string, mixed>  $query
 */
function alertRuleFor(string $source, array $query): AlertRule
{
    return AlertRule::factory()->make(['source' => $source, 'query' => $query]);
}

/**
 * @return array<string, array{0: class-string<AlertSource>, 1: string, 2: array<string, mixed>}>
 */
function alertSourcesWithAQuery(): array
{
    return [
        'logs' => [LogAlertSource::class, 'logs', ['aggregation' => 'count']],
        'traces' => [TraceAlertSource::class, 'traces', ['aggregation' => 'p95']],
        'metrics' => [MetricAlertSource::class, 'metrics', ['metric' => 'system.cpu.utilization', 'aggregation' => 'avg']],
    ];
}

it('registers the three sources', function () {
    expect(config('alerting.sources'))->toBe([
        'logs' => LogAlertSource::class,
        'traces' => TraceAlertSource::class,
        'metrics' => MetricAlertSource::class,
    ]);

    foreach (config('alerting.sources') as $class) {
        expect(app($class))->toBeInstanceOf(AlertSource::class);
    }
});

it('accepts a well-formed query', function (string $source, array $query) {
    $validator = Validator::make(['query' => $query], app($source)->rules());

    expect($validator->errors()->all())->toBe([]);
})->with(validAlertQueries());

it('keeps a dotted attribute key whole', function () {
    $validated = Validator::make(
        ['query' => ['metric' => 'm', 'aggregation' => 'avg', 'filters' => ['host.name' => 'web-1']]],
        app(MetricAlertSource::class)->rules(),
    )->validated();

    expect($validated['query']['filters'])->toBe(['host.name' => 'web-1']);
});

it('refuses a malformed query', function (string $source, array $query, string $field) {
    $validator = Validator::make(['query' => $query], app($source)->rules());

    expect($validator->errors()->keys())->toContain($field);
})->with(invalidAlertQueries());

it('makes every factory state evaluable by its source', function (string $state, string $source) {
    $rule = $state === 'default' ? AlertRule::factory()->make() : AlertRule::factory()->{$state}()->make();

    expect($rule->source)->toBe($source)
        ->and(Validator::make(['query' => $rule->query], app(config("alerting.sources.{$source}"))->rules())->errors()->all())->toBe([]);
})->with([
    ['default', 'logs'],
    ['traces', 'traces'],
    ['metrics', 'metrics'],
]);

it('throws instead of returning nothing when ClickHouse fails', function (string $class, string $source, array $query, int $status) {
    config(['clickhouse.host' => '127.0.0.1', 'clickhouse.port' => 8123]);
    Http::fake(['127.0.0.1:8123/*' => Http::response('Code: 62. DB::Exception: Syntax error', $status)]);

    $to = CarbonImmutable::parse('2026-11-08 10:00:00', 'UTC');
    $rule = alertRuleFor($source, $query);

    expect(fn () => app($class)->evaluate($rule, ['1'], $to->subMinutes(5), $to))->toThrow(AlertSourceUnavailable::class)
        ->and(fn () => app($class)->preview($rule, ['1'], $to->subDay(), $to, 600))->toThrow(AlertSourceUnavailable::class);
})->with(alertSourcesWithAQuery())->with([
    'a statement error' => 500,
    'an overloaded server' => 503,
]);

it('throws when ClickHouse cannot be reached', function (string $class, string $source, array $query) {
    config(['clickhouse.host' => '127.0.0.1', 'clickhouse.port' => 8123]);
    Http::fake(['127.0.0.1:8123/*' => Http::failedConnection()]);

    $to = CarbonImmutable::parse('2026-11-08 10:00:00', 'UTC');

    expect(fn () => app($class)->evaluate(alertRuleFor($source, $query), ['1'], $to->subMinutes(5), $to))
        ->toThrow(AlertSourceUnavailable::class);
})->with(alertSourcesWithAQuery());

it('throws on a malformed stored query without reading', function (string $class, string $source) {
    fakeClickHouseQuietly();

    $to = CarbonImmutable::parse('2026-11-08 10:00:00', 'UTC');

    expect(fn () => app($class)->evaluate(alertRuleFor($source, ['aggregation' => 'nonsense']), ['1'], $to->subMinutes(5), $to))
        ->toThrow(AlertSourceUnavailable::class, 'malformed');

    Http::assertNothingSent();
})->with([
    [LogAlertSource::class, 'logs'],
    [TraceAlertSource::class, 'traces'],
    [MetricAlertSource::class, 'metrics'],
]);

it('refuses a trace window longer than an hour', function () {
    fakeClickHouseQuietly();

    $to = CarbonImmutable::parse('2026-11-08 10:00:00', 'UTC');
    $rule = alertRuleFor('traces', ['aggregation' => 'count']);

    expect(fn () => app(TraceAlertSource::class)->evaluate($rule, ['1'], $to->subSeconds(3601), $to))
        ->toThrow(AlertSourceUnavailable::class);

    Http::assertNothingSent();
});

it('binds every user value as a parameter and reads the window closed-open', function () {
    config(['clickhouse.host' => '127.0.0.1', 'clickhouse.port' => 8123]);
    Http::fake(['127.0.0.1:8123/*' => Http::response('{"Grp":"","Bucket":0,"Value":"3"}')]);

    $to = CarbonImmutable::parse('2026-11-08 10:00:00', 'UTC');
    $rule = alertRuleFor('logs', ['filters' => ['services' => ["a'b"], 'search' => 'Timeout'], 'aggregation' => 'count']);

    expect(app(LogAlertSource::class)->evaluate($rule, ['7'], $to->subMinutes(5), $to))
        ->toBe([['labels' => [], 'value' => 3.0]]);

    Http::assertSent(function (Request $request): bool {
        $sql = clickHouseStatement($request);
        $query = clickHouseQuery($request);

        return str_contains($sql, 'Timestamp < {to:DateTime64(9)}')
            && str_contains($sql, 'hasAnyTokens(lower(Body), [lower({search:String})])')
            && ! str_contains($sql, 'Timeout')
            && ! str_contains($sql, "a'b")
            && $query['param_search'] === 'Timeout'
            && $query['param_projectIds'] === "['7']";
    });
});
