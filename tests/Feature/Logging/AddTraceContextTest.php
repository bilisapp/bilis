<?php

use App\Logging\AddTraceContext;
use App\Logging\BilisHandler;
use Illuminate\Log\Logger as LaravelLogger;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\Context\Context;

/**
 * A Monolog logger with the tap applied, plus the handler that captured it.
 *
 * @return array{0: LaravelLogger, 1: TestHandler}
 */
function tappedLogger(): array
{
    $handler = new TestHandler;
    $logger = new LaravelLogger(new Logger('bilis', [$handler]));

    (new AddTraceContext)($logger);

    return [$logger, $handler];
}

test('a line written inside a span carries that span ids', function () {
    $context = SpanContext::create(
        '5b8efff798038103d269b633813fc60c',
        'eee19b7ec3c1b174',
        TraceFlags::SAMPLED,
    );

    $scope = Context::getCurrent()->withContextValue(Span::wrap($context))->activate();

    try {
        [$logger, $handler] = tappedLogger();
        $logger->info('Card declined');
    } finally {
        $scope->detach();
    }

    $records = $handler->getRecords();

    expect($records)->toHaveCount(1)
        ->and($records[0]->extra)->toMatchArray([
            'trace_id' => '5b8efff798038103d269b633813fc60c',
            'span_id' => 'eee19b7ec3c1b174',
        ]);
});

test('a line written outside a span carries no ids at all', function () {
    /*
     * The guard that matters. Outside a recorded span the current span is a
     * no-op whose ids are all zeroes, and an all-zero id is not a missing id:
     * it would be stored, indexed, and joined to every other line written
     * outside a span. This is also the state the whole application is in when
     * the OpenTelemetry SDK is disabled, which is the default.
     */
    [$logger, $handler] = tappedLogger();

    $logger->info('Scheduler tick');

    $records = $handler->getRecords();

    expect($records)->toHaveCount(1)
        ->and($records[0]->extra)->not->toHaveKey('trace_id')
        ->and($records[0]->extra)->not->toHaveKey('span_id');
});

test('the tap leaves existing extra data alone', function () {
    $context = SpanContext::create(
        '5b8efff798038103d269b633813fc60c',
        'eee19b7ec3c1b174',
        TraceFlags::SAMPLED,
    );

    $scope = Context::getCurrent()->withContextValue(Span::wrap($context))->activate();

    try {
        [$logger, $handler] = tappedLogger();
        $logger->getLogger()->pushProcessor(
            fn ($record) => $record->with(extra: [...$record->extra, 'deploy' => 'ovh-1'])
        );
        $logger->warning('Slow query');
    } finally {
        $scope->detach();
    }

    expect($handler->getRecords()[0]->extra)->toMatchArray([
        'deploy' => 'ovh-1',
        'trace_id' => '5b8efff798038103d269b633813fc60c',
    ]);
});

test('the ids the tap writes are the ones the handler promotes', function () {
    /*
     * The tap writes into `extra`; BilisHandler lifts `trace_id`/`span_id` out
     * of `extra` onto the top-level fields the ingest endpoint reads. Neither
     * half is useful alone, so this asserts they meet.
     */
    $context = SpanContext::create(
        '5b8efff798038103d269b633813fc60c',
        'eee19b7ec3c1b174',
        TraceFlags::SAMPLED,
    );

    $scope = Context::getCurrent()->withContextValue(Span::wrap($context))->activate();

    try {
        [$logger, $handler] = tappedLogger();
        $logger->log(Level::Error->toPsrLogLevel(), 'Gateway timeout');
    } finally {
        $scope->detach();
    }

    $record = $handler->getRecords()[0];

    $mapped = (new ReflectionMethod(BilisHandler::class, 'map'))
        ->invoke(new BilisHandler('https://bilis.test/api/v1/ingest', 'bilis_k'), $record);

    expect($mapped)->toMatchArray([
        'trace_id' => '5b8efff798038103d269b633813fc60c',
        'span_id' => 'eee19b7ec3c1b174',
    ])->and($mapped['context'])->not->toHaveKey('extra.trace_id');
});
