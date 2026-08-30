<?php

use App\Services\Traces\SpanTree;

/**
 * @return array<string, mixed>
 */
function span(string $id, string $parent = '', string $name = ''): array
{
    return ['spanId' => $id, 'parentSpanId' => $parent, 'name' => $name === '' ? $id : $name];
}

/**
 * The flattened tree as `depth:spanId` pairs, which is what a waterfall draws.
 *
 * @param  list<array<string, mixed>>  $spans
 * @return list<string>
 */
function outline(array $spans): array
{
    return array_map(
        fn (array $span): string => $span['depth'].':'.$span['spanId'],
        SpanTree::flatten($spans),
    );
}

it('nests children under their parent, depth first', function () {
    expect(outline([
        span('root'),
        span('a', 'root'),
        span('a1', 'a'),
        span('b', 'root'),
    ]))->toBe(['0:root', '1:a', '2:a1', '1:b']);
});

it('returns nothing for no spans', function () {
    expect(SpanTree::flatten([]))->toBe([]);
});

/*
 * The case that matters most. A parent may have aged past the 30-day span TTL,
 * may still be in flight from another service, or may have fallen outside the
 * row cap — none of which is a reason to hide a span that was queried and
 * returned.
 */
it('renders a span whose parent is missing at root level', function () {
    expect(outline([
        span('root'),
        span('a', 'root'),
        span('orphan', 'a-parent-that-never-arrived'),
    ]))->toBe(['0:root', '1:a', '0:orphan']);
});

it('keeps the children of an orphan under it', function () {
    expect(outline([
        span('orphan', 'gone'),
        span('child', 'orphan'),
    ]))->toBe(['0:orphan', '1:child']);
});

it('renders several roots when a trace has more than one', function () {
    expect(outline([
        span('root-a'),
        span('root-b'),
        span('child', 'root-b'),
    ]))->toBe(['0:root-a', '0:root-b', '1:child']);
});

/*
 * Impossible from a conforming SDK, trivial to POST by hand: every span in the
 * cycle has a parent that is present, so none of them is ever a root. Without
 * the sweep at the end they would be queried, counted, and never drawn.
 */
it('still renders spans caught in a parent cycle', function () {
    $outline = outline([
        span('a', 'b'),
        span('b', 'a'),
    ]);

    expect($outline)->toHaveCount(2)
        ->and($outline)->toContain('0:a');
});

it('does not recurse forever on a span that is its own parent', function () {
    expect(outline([span('self', 'self')]))->toBe(['0:self']);
});

it('keeps a span that carries no id at all', function () {
    $flattened = SpanTree::flatten([span('root'), ['spanId' => '', 'parentSpanId' => '']]);

    expect($flattened)->toHaveCount(2);
});

it('preserves the fields the row already carried', function () {
    $flattened = SpanTree::flatten([
        ['spanId' => 'root', 'parentSpanId' => '', 'name' => 'GET /pay', 'durationMs' => 12.5],
    ]);

    expect($flattened[0])->toMatchArray([
        'spanId' => 'root',
        'name' => 'GET /pay',
        'durationMs' => 12.5,
        'depth' => 0,
    ]);
});
