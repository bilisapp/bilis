<?php

use App\Services\Ingest\Envelope\ErrorEventMapper;

/**
 * One event with the context an SDK sends, as decoded from an envelope item.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function sdkEvent(array $overrides = []): array
{
    return array_replace_recursive([
        'event_id' => '9f2c1e7a4b3d4f5e8a6b7c8d9e0f1a2b',
        'timestamp' => '2026-11-07T11:50:00Z',
        'level' => 'error',
        'exception' => ['values' => [[
            'type' => 'TypeError',
            'value' => "Cannot read properties of undefined (reading 'total')",
            'stacktrace' => ['frames' => [
                ['filename' => 'node_modules/react-dom/index.js', 'lineno' => 118, 'function' => 'render', 'in_app' => false, 'context_line' => 'lib code'],
                [
                    'filename' => 'src/cart/summary.ts', 'lineno' => 88, 'colno' => 17, 'function' => 'summarise', 'in_app' => true,
                    'pre_context' => ['a', 'b', 'c', 'd', 'e', 'f', 'g'],
                    'context_line' => '  return cart.total.amount;',
                    'post_context' => ['}', '', 'x', 'y', 'z', 'w'],
                ],
            ]],
        ]]],
        'contexts' => [
            'os' => ['name' => 'macOS', 'version' => '15.1'],
            'browser' => ['name' => 'Safari', 'version' => '18.1'],
            'device' => ['model' => 'MacBookPro18,3'],
        ],
        'request' => [
            'url' => 'https://shop.test/cart',
            'method' => 'GET',
            'query_string' => 'ref=email',
            'headers' => [['User-Agent', 'Mozilla/5.0 Safari']],
        ],
    ], $overrides);
}

test('in-app frames keep five lines of source either side and library frames none', function () {
    $attributes = (new ErrorEventMapper)->map([sdkEvent()], '1')->rows[0]['LogAttributes'];
    $frames = json_decode($attributes['exception.frames'], true);

    // Most recent call first, as everywhere else.
    expect($frames[0])->toMatchArray([
        'filename' => 'src/cart/summary.ts',
        'lineno' => 88,
        'colno' => 17,
        'in_app' => true,
        'pre_context' => ['c', 'd', 'e', 'f', 'g'],
        'context_line' => '  return cart.total.amount;',
        'post_context' => ['}', '', 'x', 'y', 'z'],
    ]);

    expect($frames[1])->not->toHaveKey('context_line')
        ->and($frames[1]['in_app'])->toBeFalse();
});

test('os, browser, device and request details land under OpenTelemetry names', function () {
    $attributes = (new ErrorEventMapper)->map([sdkEvent()], '1')->rows[0]['LogAttributes'];

    expect($attributes)->toMatchArray([
        'os.name' => 'macOS',
        'os.version' => '15.1',
        'browser.name' => 'Safari',
        'browser.version' => '18.1',
        'device.model' => 'MacBookPro18,3',
        'url.query' => 'ref=email',
        'user_agent.original' => 'Mozilla/5.0 Safari',
    ]);
});

test('a frame list over budget drops source before it drops frames', function () {
    $huge = str_repeat('x', 290);
    $frames = array_map(fn (int $line): array => [
        'filename' => "src/f{$line}.ts", 'lineno' => $line, 'function' => "f{$line}", 'in_app' => true,
        'pre_context' => array_fill(0, 5, $huge), 'context_line' => $huge, 'post_context' => array_fill(0, 5, $huge),
    ], range(1, 50));

    $event = sdkEvent();
    $event['exception']['values'][0]['stacktrace']['frames'] = $frames;

    $json = (new ErrorEventMapper)->map([$event], '1')->rows[0]['LogAttributes']['exception.frames'];
    $decoded = json_decode($json, true);

    expect(strlen($json))->toBeLessThanOrEqual(32_768)
        ->and($decoded)->toHaveCount(50)
        ->and($decoded[0])->not->toHaveKey('context_line');
});

test('an event with a single exception has no chain', function () {
    $attributes = (new ErrorEventMapper)->map([sdkEvent()], '1')->rows[0]['LogAttributes'];

    expect($attributes)->not->toHaveKey('exception.chain');
});

test('a frame the sdk did not classify keeps its source and leaves the call to the reader', function () {
    $event = sdkEvent();
    $event['exception']['values'][0]['stacktrace']['frames'] = [[
        'filename' => 'src/cart/summary.ts', 'lineno' => 88, 'function' => 'summarise',
        'context_line' => '  return cart.total.amount;',
    ]];

    $frame = json_decode((new ErrorEventMapper)->map([$event], '1')->rows[0]['LogAttributes']['exception.frames'], true)[0];

    expect($frame)->not->toHaveKey('in_app')
        ->and($frame['context_line'])->toBe('  return cart.total.amount;');
});
