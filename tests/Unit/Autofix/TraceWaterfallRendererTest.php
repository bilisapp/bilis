<?php

use App\Services\Autofix\TraceWaterfallRenderer;

/**
 * A span in the shape TraceQuery::spans() returns.
 *
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function waterfallSpan(string $spanId, string $parentSpanId = '', array $overrides = []): array
{
    return [
        'timestamp' => '2026-08-30 09:14:02.184000000',
        'traceId' => str_repeat('a', 32),
        'spanId' => $spanId,
        'parentSpanId' => $parentSpanId,
        'name' => 'span ' . $spanId,
        'kind' => 'Internal',
        'serviceName' => 'checkout',
        'durationMs' => 10.0,
        'statusCode' => 'Unset',
        'statusMessage' => '',
        'attributes' => [],
        'events' => [],
        'links' => [],
        ...$overrides,
    ];
}

/**
 * The waterfall as a list of span lines, legend and trailer stripped.
 *
 * @return list<string>
 */
function waterfallLines(string $text): array
{
    return array_values(array_filter(
        explode("\n", $text),
        fn(string $line): bool => !str_starts_with($line, 'Legend:') && !str_starts_with($line, '('),
    ));
}

test('spans render in tree order, indented by depth', function () {
    $rendered = (new TraceWaterfallRenderer)->render([
        // Deliberately out of order: the child arrives before its parent.
        waterfallSpan('child', 'root', ['name' => 'SELECT orders', 'serviceName' => 'db', 'kind' => 'Client', 'durationMs' => 4.2]),
        waterfallSpan('root', '', ['name' => 'POST /checkout', 'kind' => 'Server', 'durationMs' => 252.0]),
        waterfallSpan('grandchild', 'child', ['name' => 'parse', 'durationMs' => 0.35]),
    ]);

    $lines = waterfallLines($rendered['text']);

    expect($lines)->toHaveCount(3)
        ->and($lines[0])->toBe('     checkout POST /checkout [Server] 252ms')
        ->and($lines[1])->toBe('       db SELECT orders [Client] 4.2ms')
        ->and($lines[2])->toBe('         checkout parse [Internal] 0.35ms')
        ->and($rendered['rendered'])->toBe(3)
        ->and($rendered['omitted'])->toBe(0);
});

test('the triggering span and every error span are marked, and the legend says what the marks mean', function () {
    $rendered = (new TraceWaterfallRenderer)->render([
        waterfallSpan('root'),
        waterfallSpan('worker', 'root', ['statusCode' => 'Error', 'statusMessage' => 'charge declined']),
        waterfallSpan('logger', 'root'),
    ], 'logger');

    $lines = waterfallLines($rendered['text']);

    expect($rendered['text'])->toStartWith('Legend: >> = the span that emitted the triggering log line; !! = a span whose status is Error')
        ->and($lines[0])->toStartWith('     checkout span root')
        ->and($lines[1])->toStartWith('  !!   checkout span worker [Internal] 10ms Error: charge declined')
        ->and($lines[2])->toStartWith('>>     checkout span logger');
});

test('a span that both triggered the log and failed wears both marks', function () {
    $rendered = (new TraceWaterfallRenderer)->render([
        waterfallSpan('root', '', ['statusCode' => 'Error']),
    ], 'root');

    expect(waterfallLines($rendered['text'])[0])->toStartWith('>>!! checkout span root');
});

test('only the curated attributes travel, statements are truncated, and exception events are spelled out', function () {
    $rendered = (new TraceWaterfallRenderer)->render([
        waterfallSpan('root', '', [
            'attributes' => [
                'http.method' => 'POST',
                'http.route' => '/checkout',
                'http.status_code' => '500',
                'db.system' => 'postgresql',
                'db.statement' => 'SELECT ' . str_repeat('col, ', 60) . 'FROM orders WHERE id = $1',
                'code.function' => 'charge',
                'code.filepath' => 'app/Billing/Charger.php',
                'code.lineno' => '118',
                'host.name' => 'web-1',
                'telemetry.sdk.version' => '1.30.0',
                'session.id' => 'deadbeef',
            ],
            'events' => [
                ['timestamp' => '2026-08-30 09:14:02.400000000', 'name' => 'exception', 'attributes' => [
                    'exception.type' => 'App\\Exceptions\\PaymentFailed',
                    'exception.message' => "Charge declined\nfor order 4821",
                    'exception.stacktrace' => str_repeat('#0 frame', 200),
                ]],
                ['timestamp' => '2026-08-30 09:14:02.100000000', 'name' => 'retry', 'attributes' => ['attempt' => '2']],
            ],
        ]),
    ]);

    $line = waterfallLines($rendered['text'])[0];

    expect($line)
        ->toContain('http.method=POST')
        ->toContain('http.route=/checkout')
        ->toContain('http.status_code=500')
        ->toContain('db.system=postgresql')
        ->toContain('db.statement=SELECT col, ')
        ->toContain('…')
        // Six attributes at most: the code.* trio is behind the cap here.
        ->not->toContain('code.lineno=118')
        ->not->toContain('host.name')
        ->not->toContain('telemetry.sdk')
        ->not->toContain('session.id')
        // The exception event is never dropped for the attribute cap.
        ->toContain('exception=App\\Exceptions\\PaymentFailed: Charge declined for order 4821')
        ->not->toContain('#0 frame')
        ->not->toContain('retry');

    preg_match('/db\.statement=(.*?)(?: [a-z][a-z._]*=|$)/', $line, $matches);

    expect(mb_strlen($matches[1]))->toBe(TraceWaterfallRenderer::STATEMENT_LIMIT + 1);
});

test('code location attributes travel when the request ones leave room', function () {
    $rendered = (new TraceWaterfallRenderer)->render([
        waterfallSpan('root', '', [
            'attributes' => [
                'rpc.system' => 'grpc',
                'rpc.service' => 'billing.Charges',
                'rpc.method' => 'Charge',
                'code.function' => 'charge',
                'code.filepath' => 'app/Billing/Charger.php',
                'code.lineno' => '118',
            ],
        ]),
    ]);

    expect(waterfallLines($rendered['text'])[0])
        ->toContain('rpc.system=grpc rpc.service=billing.Charges rpc.method=Charge code.function=charge code.filepath=app/Billing/Charger.php code.lineno=118');
});

test('a trace over the span cap keeps the triggering path, its siblings, the failures and the slowest', function () {
    $spans = [waterfallSpan('root', '', ['durationMs' => 5000.0])];

    // Deep call path down to the triggering span.
    $spans[] = waterfallSpan('a', 'root');
    $spans[] = waterfallSpan('b', 'a');
    $spans[] = waterfallSpan('trigger', 'b', ['durationMs' => 1.0]);
    $spans[] = waterfallSpan('sibling', 'b', ['durationMs' => 1.0]);

    // One failure buried among a lot of fast noise.
    $spans[] = waterfallSpan('failed', 'root', ['statusCode' => 'Error', 'durationMs' => 1.0]);

    for ($i = 0; $i < 200; $i++) {
        $spans[] = waterfallSpan('noise' . $i, 'root', ['durationMs' => 2.0]);
    }

    // A handful of slow spans that should outrank the noise.
    for ($i = 0; $i < 5; $i++) {
        $spans[] = waterfallSpan('slow' . $i, 'root', ['durationMs' => 900.0 + $i]);
    }

    $rendered = (new TraceWaterfallRenderer)->render($spans, 'trigger');
    $text = $rendered['text'];

    expect($rendered['rendered'])->toBeLessThanOrEqual(TraceWaterfallRenderer::MAX_SPANS)
        ->and($rendered['omitted'])->toBe(count($spans) - $rendered['rendered'])
        ->and($text)->toContain(sprintf('(%d more spans omitted)', $rendered['omitted']))
        // Marks are a fixed four-character column; the trigger sits at depth 3.
        ->and($text)->toContain('>>   ' . str_repeat('  ', 3) . 'checkout span trigger')
        ->and($text)->toContain('checkout span sibling')
        ->and($text)->toContain('checkout span a ')
        ->and($text)->toContain('checkout span b ')
        ->and($text)->toContain('!!   checkout span failed')
        ->and($text)->toContain('span slow4')
        ->and($text)->toContain('span slow0');

    // Tree order survives the selection: the root comes before its descendants.
    $lines = waterfallLines($text);
    expect($lines[0])->toContain('span root');
});

test('the character cap cuts whole lines and reports what it dropped', function () {
    $spans = [];

    for ($i = 0; $i < 50; $i++) {
        $spans[] = waterfallSpan('s' . $i, '', [
            'name' => str_repeat('n', 80),
            'attributes' => ['http.route' => str_repeat('r', 90)],
        ]);
    }

    $rendered = (new TraceWaterfallRenderer)->render($spans);

    expect(mb_strlen($rendered['text']))->toBeLessThanOrEqual(TraceWaterfallRenderer::MAX_CHARS + 40)
        ->and($rendered['rendered'])->toBeLessThan(50)
        ->and($rendered['omitted'])->toBe(50 - $rendered['rendered'])
        ->and($rendered['text'])->toEndWith(sprintf('(%d more spans omitted)', $rendered['omitted']));

    foreach (waterfallLines($rendered['text']) as $line) {
        expect($line)->toEndWith(str_repeat('r', 90));
    }
});

test('an empty span list renders nothing', function () {
    expect((new TraceWaterfallRenderer)->render([]))->toBe(['text' => '', 'rendered' => 0, 'omitted' => 0]);
});

test('durations print in ms below a second and in seconds above', function () {
    $rendered = (new TraceWaterfallRenderer)->render([
        waterfallSpan('a', '', ['durationMs' => 0.4]),
        waterfallSpan('b', '', ['durationMs' => 42.6]),
        waterfallSpan('c', '', ['durationMs' => 1240.0]),
    ]);

    $lines = waterfallLines($rendered['text']);

    expect($lines[0])->toEndWith(' 0.4ms')
        ->and($lines[1])->toEndWith(' 43ms')
        ->and($lines[2])->toEndWith(' 1.24s');
});
