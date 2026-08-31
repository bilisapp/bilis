<?php

use App\Services\Ingest\LogWriter;
use App\Services\Ingest\SpanWriter;

/*
 * The writers are the last line before json_encode, and json_encode is the
 * problem: `['0' => 'x']` is a PHP array with an integer key, which it writes
 * as the list `["x"]`. ClickHouse refuses that for a Map — after the async
 * insert was acked, so the block simply vanishes. Every Map must be an object.
 */
it('serialises every log map column as a JSON object whatever its keys', function () {
    [$row] = LogWriter::normalise([[
        'ResourceAttributes' => [],
        'ScopeAttributes' => ['0' => 'x'],
        'LogAttributes' => ['0' => 'x', 'user.id' => '42'],
        'Body' => 'kept',
    ]]);

    expect(json_encode($row))->toBe('{"ResourceAttributes":{},"ScopeAttributes":{"0":"x"},"LogAttributes":{"0":"x","user.id":"42"},"Body":"kept"}');
});

it('serialises every span map column and each Array(Map) element as a JSON object', function () {
    [$row] = SpanWriter::normalise([[
        'ResourceAttributes' => ['1' => 'a'],
        'SpanAttributes' => [],
        'Events.Name' => ['first', 'second', 'third'],
        'Events.Attributes' => [[], ['0' => 'x'], ['k' => 'v']],
        'Links.Attributes' => [['2' => 'y']],
    ]]);

    expect(json_encode($row))->toBe('{"ResourceAttributes":{"1":"a"},"SpanAttributes":{},"Events.Name":["first","second","third"],"Events.Attributes":[{},{"0":"x"},{"k":"v"}],"Links.Attributes":[{"2":"y"}]}');
});

it('leaves a column that is not an array alone', function () {
    [$row] = SpanWriter::normalise([['SpanAttributes' => (object)['a' => 'b'], 'Events.Attributes' => 'garbage']]);

    expect($row['SpanAttributes'])->toBeInstanceOf(stdClass::class)
        ->and($row['Events.Attributes'])->toBe('garbage');
});
