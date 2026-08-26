<?php

use App\Services\ClickHouse\ClickHouseClient;
use App\Services\ClickHouse\ClickHouseException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'clickhouse.scheme' => 'http',
        'clickhouse.host' => '127.0.0.1',
        'clickhouse.port' => 8123,
        'clickhouse.database' => 'bilis',
        'clickhouse.username' => 'bilis_user',
        'clickhouse.password' => 'secret',
        'clickhouse.timeout' => 10,
        'clickhouse.connect_timeout' => 3,
    ]);
});

test('select sends bound values as server side query parameters', function () {
    Http::fake([
        '127.0.0.1:8123/*' => Http::response(
            '{"ProjectId":7,"Body":"boom"}'."\n".'{"ProjectId":7,"Body":"bang"}'."\n",
        ),
    ]);

    $sql = 'SELECT ProjectId, Body FROM otel_logs WHERE ProjectId = {projectId:UInt64} AND Body ILIKE {needle:String}';

    $rows = app(ClickHouseClient::class)->select($sql, [
        'projectId' => 7,
        'needle' => "%'; DROP TABLE otel_logs; --%",
    ]);

    expect($rows)->toBe([
        ['ProjectId' => 7, 'Body' => 'boom'],
        ['ProjectId' => 7, 'Body' => 'bang'],
    ]);

    Http::assertSent(function (Request $request) use ($sql) {
        $query = [];
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $request->method() === 'POST'
            && $request->body() === $sql
            && ! str_contains($request->body(), 'DROP TABLE')
            && $query['database'] === 'bilis'
            && $query['default_format'] === 'JSONEachRow'
            && $query['param_projectId'] === '7'
            && $query['param_needle'] === "%'; DROP TABLE otel_logs; --%"
            && $request->hasHeader('X-ClickHouse-User', 'bilis_user')
            && $request->hasHeader('X-ClickHouse-Key', 'secret');
    });
});

test('select returns an empty array when clickhouse returns no rows', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    expect(app(ClickHouseClient::class)->select('SELECT 1'))->toBe([]);
});

test('select rejects invalid parameter names', function () {
    Http::fake();

    app(ClickHouseClient::class)->select('SELECT 1', ['bad name' => 1]);
})->throws(ClickHouseException::class, 'Invalid ClickHouse query parameter name');

test('insert posts newline delimited json with asynchronous insert settings', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    app(ClickHouseClient::class)->insert('otel_logs', [
        ['ProjectId' => 1, 'Body' => 'first'],
        ['ProjectId' => 1, 'Body' => 'second'],
    ]);

    Http::assertSent(function (Request $request) {
        $query = [];
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query['query'] === 'INSERT INTO otel_logs FORMAT JSONEachRow'
            && $query['async_insert'] === '1'
            && $query['wait_for_async_insert'] === '0'
            && $query['database'] === 'bilis'
            && $request->body() === '{"ProjectId":1,"Body":"first"}'."\n".'{"ProjectId":1,"Body":"second"}'."\n";
    });
});

test('insert does nothing when there are no rows', function () {
    Http::fake();

    app(ClickHouseClient::class)->insert('otel_logs', []);

    Http::assertNothingSent();
});

test('insert rejects an unsafe table name', function () {
    Http::fake();

    app(ClickHouseClient::class)->insert('otel_logs; DROP TABLE otel_logs', [['ProjectId' => 1]]);
})->throws(ClickHouseException::class, 'Invalid ClickHouse identifier');

test('a 503 response is reported as an overload', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('Service Unavailable', 503)]);

    try {
        app(ClickHouseClient::class)->select('SELECT 1');
    } catch (ClickHouseException $exception) {
        expect($exception->isOverload())->toBeTrue()
            ->and($exception->statusCode())->toBe(503);

        return;
    }

    $this->fail('Expected a ClickHouseException to be thrown.');
});

test('a 429 response is reported as an overload', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('Too Many Requests', 429)]);

    expect(fn () => app(ClickHouseClient::class)->select('SELECT 1'))
        ->toThrow(fn (ClickHouseException $exception) => expect($exception->isOverload())->toBeTrue());
});

test('a connection failure is reported as an overload', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::failedConnection()]);

    try {
        app(ClickHouseClient::class)->insert('otel_logs', [['ProjectId' => 1]]);
    } catch (ClickHouseException $exception) {
        expect($exception->isOverload())->toBeTrue()
            ->and($exception->connectionFailed())->toBeTrue();

        return;
    }

    $this->fail('Expected a ClickHouseException to be thrown.');
});

test('clickhouse overload error codes are detected', function (int $code) {
    Http::fake([
        '127.0.0.1:8123/*' => Http::response(
            sprintf('Code: %d. DB::Exception: overloaded', $code),
            500,
            ['X-ClickHouse-Exception-Code' => (string) $code],
        ),
    ]);

    try {
        app(ClickHouseClient::class)->insert('otel_logs', [['ProjectId' => 1]]);
    } catch (ClickHouseException $exception) {
        expect($exception->isOverload())->toBeTrue()
            ->and($exception->errorCode())->toBe($code);

        return;
    }

    $this->fail('Expected a ClickHouseException to be thrown.');
})->with([
    'too many simultaneous queries' => 202,
    'too many parts' => 252,
]);

test('a statement error is not reported as an overload', function () {
    Http::fake([
        '127.0.0.1:8123/*' => Http::response(
            'Code: 62. DB::Exception: Syntax error',
            500,
            ['X-ClickHouse-Exception-Code' => '62'],
        ),
    ]);

    try {
        app(ClickHouseClient::class)->execute('SELCT 1');
    } catch (ClickHouseException $exception) {
        expect($exception->isOverload())->toBeFalse()
            ->and($exception->errorCode())->toBe(62);

        return;
    }

    $this->fail('Expected a ClickHouseException to be thrown.');
});

test('every request pins the session timezone to UTC', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    $client = app(ClickHouseClient::class);
    $client->select('SELECT 1');
    $client->insert('otel_logs', [['Body' => 'tz probe']]);
    $client->execute('SELECT 1', withDatabase: false);

    Http::assertSentCount(3);
    Http::assertSent(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        // DateTime64 strings are parsed in the session timezone; without this
        // pin a server in another timezone silently skews stored epochs.
        return ($query['session_timezone'] ?? null) === 'UTC';
    });
});
