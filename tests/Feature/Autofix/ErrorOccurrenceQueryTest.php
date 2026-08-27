<?php

use App\Services\Logs\LogQuery;
use App\Services\Logs\SeverityLevel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * The statement the last ClickHouse request carried.
 */
function occurrenceStatement(Request $request): string
{
    return (string) $request->body();
}

/**
 * The bound parameters of the last ClickHouse request.
 *
 * @return array<string, string>
 */
function occurrenceParameters(Request $request): array
{
    $query = [];
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    /** @var array<string, string> $query */
    return $query;
}

beforeEach(function () {
    config([
        'clickhouse.host' => '127.0.0.1',
        'clickhouse.port' => 8123,
        'clickhouse.database' => 'bilis',
    ]);
});

test('the error occurrence query follows the sort key contract', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    app(LogQuery::class)->errorOccurrences(
        '7',
        Carbon::parse('2026-08-27 09:00:00'),
        Carbon::parse('2026-08-27 10:00:00'),
    );

    Http::assertSent(function (Request $request) {
        $sql = occurrenceStatement($request);
        $params = occurrenceParameters($request);

        expect($sql)->toBe(
            'SELECT ProjectId, Timestamp, TraceId, SpanId, SeverityText, SeverityNumber, ServiceName, Body, ScopeName, ScopeVersion, ResourceAttributes, LogAttributes '
            .'FROM otel_logs WHERE ProjectId IN {projectIds:Array(String)} AND Timestamp >= {from:DateTime64(9)} AND Timestamp <= {to:DateTime64(9)} '
            .'AND SeverityNumber >= {severityFloor:UInt8} ORDER BY Timestamp DESC LIMIT {rowLimit:UInt32}'
        );

        expect($sql)->not->toContain('toStartOf');

        expect($params['param_projectIds'])->toBe("['7']");
        expect($params['param_from'])->toBe('2026-08-27 09:00:00.000000');
        expect($params['param_to'])->toBe('2026-08-27 10:00:00.000000');
        expect($params['param_severityFloor'])->toBe((string) SeverityLevel::Error->minimumSeverityNumber());
        expect($params['param_rowLimit'])->toBe('2000');

        return true;
    });
});

test('the error occurrence query binds every value as a parameter', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    app(LogQuery::class)->errorOccurrences(
        "1'; DROP TABLE otel_logs; --",
        Carbon::parse('2026-08-27 09:00:00'),
        Carbon::parse('2026-08-27 10:00:00'),
    );

    Http::assertSent(function (Request $request) {
        expect(occurrenceStatement($request))->not->toContain('DROP TABLE');

        return true;
    });
});

test('the error occurrence query caps the row limit it is handed', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    app(LogQuery::class)->errorOccurrences('7', Carbon::now()->subDay(), Carbon::now(), 500_000);

    Http::assertSent(function (Request $request) {
        expect(occurrenceParameters($request)['param_rowLimit'])->toBe('5000');

        return true;
    });
});

test('the error occurrence query short circuits without a project id', function () {
    Http::fake();

    $result = app(LogQuery::class)->errorOccurrences('', Carbon::now()->subHour(), Carbon::now());

    expect($result)->toBe(['rows' => [], 'unavailable' => false]);

    Http::assertNothingSent();
});

test('the error occurrence query reports an overloaded clickhouse as unavailable', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('too many parts', 503)]);

    $result = app(LogQuery::class)->errorOccurrences('7', Carbon::now()->subHour(), Carbon::now());

    expect($result['unavailable'])->toBeTrue()
        ->and($result['rows'])->toBe([]);
});

test('the error occurrence query returns mapped rows', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response(json_encode([
        'ProjectId' => '7',
        'Timestamp' => '2026-08-27 09:59:00.000000000',
        'TraceId' => '',
        'SpanId' => '',
        'SeverityText' => 'ERROR',
        'SeverityNumber' => 17,
        'ServiceName' => 'checkout',
        'Body' => 'RuntimeException: boom',
        'ScopeName' => '',
        'ScopeVersion' => '',
        'ResourceAttributes' => [],
        'LogAttributes' => ['exception.type' => 'RuntimeException'],
    ])."\n")]);

    $result = app(LogQuery::class)->errorOccurrences('7', Carbon::now()->subHour(), Carbon::now());

    expect($result['unavailable'])->toBeFalse()
        ->and($result['rows'])->toHaveCount(1)
        ->and($result['rows'][0]['serviceName'])->toBe('checkout');
});
