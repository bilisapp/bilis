<?php

use App\Logging\BilisHandler;
use App\Logging\BilisLogger;
use App\Services\Ingest\LogSeverity;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\NullHandler;
use Monolog\Handler\WhatFailureGroupHandler;
use Monolog\Level;
use Monolog\LogRecord;

const ENDPOINT = 'https://bilis.test/api/v1/ingest';

/**
 * A handler pointed at the fake ingest endpoint.
 */
function handler(int $maxBufferSize = 500, ?string $service = 'checkout'): BilisHandler
{
    return new BilisHandler(
        endpoint: ENDPOINT,
        apiKey: 'bilis_test_key',
        maxBufferSize: $maxBufferSize,
        service: $service,
    );
}

/**
 * A record with a fixed timestamp, so the wire format can be asserted exactly.
 *
 * @param  array<string, mixed>  $context
 * @param  array<string, mixed>  $extra
 */
function record(Level $level = Level::Info, string $message = 'Line', array $context = [], array $extra = []): LogRecord
{
    return new LogRecord(
        datetime: new DateTimeImmutable('2025-01-01T00:00:00.123456+00:00'),
        channel: 'bilis',
        level: $level,
        message: $message,
        context: $context,
        extra: $extra,
    );
}

test('a buffered batch is shipped as one request in the ingest shape', function () {
    Http::fake([ENDPOINT => Http::response(['accepted' => 3, 'skipped' => 0], 202)]);

    $handler = handler();

    $handler->handle(record(Level::Warning, 'Slow query', ['ms' => 900], ['request_id' => 'abc']));
    $handler->handle(record(Level::Error, 'Boom'));
    $handler->handle(record(Level::Debug, 'Trace me'));

    $handler->flush();

    Http::assertSentCount(1);

    Http::assertSent(function (Request $request) {
        $records = $request->data();

        expect($request->url())->toBe(ENDPOINT)
            ->and($request->hasHeader('Authorization', 'Bearer bilis_test_key'))->toBeTrue()
            ->and($records)->toHaveCount(3)
            ->and($records[0])->toBe([
                'message' => 'Slow query',
                // Monolog's level names are already severity aliases once lowercased.
                'level' => 'warning',
                'timestamp' => '2025-01-01T00:00:00.123456+00:00',
                'service' => 'checkout',
                // `extra` is merged into context behind an `extra.` prefix.
                'context' => ['ms' => 900, 'extra.request_id' => 'abc'],
            ])
            ->and($records[1]['level'])->toBe('error')
            ->and($records[2]['level'])->toBe('debug');

        return true;
    });
});

test('every monolog level name resolves to a bilis severity', function () {
    foreach (Level::cases() as $level) {
        expect(LogSeverity::numberForText(strtolower($level->getName())))->not->toBeNull();
    }
});

test('the buffer is flushed as soon as it is full', function () {
    Http::fake([ENDPOINT => Http::response('', 202)]);

    $handler = handler(maxBufferSize: 2);

    $handler->handle(record());
    Http::assertNothingSent();

    $handler->handle(record());
    Http::assertSentCount(1);

    Http::assertSent(fn (Request $request) => count($request->data()) === 2);
});

test('an empty buffer makes no http call at all', function () {
    Http::fake();

    $handler = handler();
    $handler->flush();
    $handler->close();

    Http::assertNothingSent();
});

test('a rejected batch is swallowed and dropped', function () {
    Http::fake([ENDPOINT => Http::response('nope', 500)]);

    $handler = handler();
    $handler->handle(record());

    $handler->flush();
    // The batch is gone, not requeued: a second flush has nothing to send.
    $handler->flush();

    Http::assertSentCount(1);
});

test('a connection failure never reaches the caller', function () {
    $attempts = 0;

    Http::fake(function () use (&$attempts) {
        $attempts++;

        throw new ConnectionException('Connection timed out');
    });

    $handler = handler();
    $handler->handle(record());

    expect(fn () => $handler->flush())->not->toThrow(Exception::class);

    // The batch was dropped, so the second flush does not even try again.
    $handler->flush();

    expect($attempts)->toBe(1);
});

test('closing the handler flushes what is buffered', function () {
    Http::fake([ENDPOINT => Http::response('', 202)]);

    $handler = handler();
    $handler->handle(record());

    $handler->close();

    Http::assertSentCount(1);

    // Closing again must not re-send the same batch.
    $handler->close();

    Http::assertSentCount(1);
});

test('the handler keeps working after a flush', function () {
    Http::fake([ENDPOINT => Http::response('', 202)]);

    $handler = handler();

    $handler->handle(record(message: 'First'));
    $handler->flush();

    $handler->handle(record(message: 'Second'));
    $handler->flush();

    Http::assertSentCount(2);

    Http::assertSent(fn (Request $request) => $request->data()[0]['message'] === 'Second');
});

test('the service name falls back to the application name', function () {
    Http::fake([ENDPOINT => Http::response('', 202)]);

    $handler = handler(service: null);
    $handler->handle(record());
    $handler->flush();

    Http::assertSent(fn (Request $request) => $request->data()[0]['service'] === config('app.name'));
});

test('the factory wraps the handler so a failure can never bubble', function () {
    $logger = (new BilisLogger)(['endpoint' => ENDPOINT, 'api_key' => 'bilis_test_key']);

    expect($logger->getHandlers()[0])->toBeInstanceOf(WhatFailureGroupHandler::class);
});

test('the channel is inert without an endpoint or an api key', function (array $config) {
    Http::fake();

    config(['logging.channels.bilis' => array_merge([
        'driver' => 'custom',
        'via' => BilisLogger::class,
        'level' => 'debug',
    ], $config)]);

    Log::channel('bilis')->error('Nowhere to go');
    Log::channel('bilis')->getLogger()->close();

    expect(Log::channel('bilis')->getLogger()->getHandlers()[0])->toBeInstanceOf(NullHandler::class);

    Http::assertNothingSent();
})->with([
    'nothing configured' => [[]],
    'no api key' => [['endpoint' => ENDPOINT]],
    'no endpoint' => [['api_key' => 'bilis_test_key']],
    'blank values' => [['endpoint' => ' ', 'api_key' => ' ']],
]);

test('logging to the configured channel reaches the ingest endpoint', function () {
    Http::fake([ENDPOINT => Http::response(['accepted' => 1, 'skipped' => 0], 202)]);

    config(['logging.channels.bilis' => [
        'driver' => 'custom',
        'via' => BilisLogger::class,
        'endpoint' => ENDPOINT,
        'api_key' => 'bilis_test_key',
        'level' => 'debug',
        'service' => 'bilis-app',
    ]]);

    Log::channel('bilis')->warning('Ingest is slow', ['queue' => 'default']);

    Http::assertNothingSent();

    Log::channel('bilis')->getLogger()->close();

    Http::assertSent(function (Request $request) {
        $records = $request->data();

        expect($records)->toHaveCount(1)
            ->and($records[0]['message'])->toBe('Ingest is slow')
            ->and($records[0]['level'])->toBe('warning')
            ->and($records[0]['service'])->toBe('bilis-app')
            ->and($records[0]['context'])->toBe(['queue' => 'default'])
            ->and($records[0]['timestamp'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}[+-]\d{2}:\d{2}$/');

        return true;
    });
});

test('the terminating hook ships the batch after the response', function () {
    Http::fake([ENDPOINT => Http::response('', 202)]);

    config(['logging.channels.bilis' => [
        'driver' => 'custom',
        'via' => BilisLogger::class,
        'endpoint' => ENDPOINT,
        'api_key' => 'bilis_test_key',
        'level' => 'debug',
    ]]);

    Log::channel('bilis')->info('Deferred until terminate');

    Http::assertNothingSent();

    $this->app->terminate();

    Http::assertSentCount(1);
});
