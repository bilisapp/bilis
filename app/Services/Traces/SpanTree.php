<?php

namespace App\Services\Traces;

/**
 * Arranges a trace's spans into the order a waterfall renders them in.
 *
 * The tree is built here rather than in ClickHouse or in the browser: it is a
 * parent-pointer walk over at most {@see TraceQuery::SPAN_LIMIT} rows, and doing
 * it server side means the page ships a flat, already-ordered list with a depth
 * on each row — no recursive component, no reflow while the tree assembles.
 *
 * Each row comes out carrying its `depth` and its direct `childCount`, which is
 * everything the waterfall needs to draw tree guides, a disclosure triangle and
 * a child-count badge without walking the list again. Collapsing is then a pure
 * client-side pass over the flat list: hide every row whose depth is greater
 * than a collapsed row's, until the depth comes back.
 *
 * The rule that matters is that **no span is ever dropped**. A span whose parent
 * is missing is not corrupt data; it is the normal consequence of a parent that
 * has aged past the 30-day span TTL, that belongs to a service still exporting,
 * or that fell outside the row cap. Such a span is rendered at root level, and
 * so is any span caught in a parent cycle.
 */
class SpanTree
{
    /**
     * Flatten spans into depth-first order, each row carrying its depth.
     *
     * @param  list<array<string, mixed>>  $spans
     * @return list<array<string, mixed>>
     */
    public static function flatten(array $spans): array
    {
        if ($spans === []) {
            return [];
        }

        $byId = [];

        foreach ($spans as $span) {
            $spanId = (string) ($span['spanId'] ?? '');

            if ($spanId !== '') {
                $byId[$spanId] = $span;
            }
        }

        /** @var array<string, list<array<string, mixed>>> $children */
        $children = [];
        /** @var list<array<string, mixed>> $roots */
        $roots = [];

        foreach ($spans as $span) {
            $parentId = (string) ($span['parentSpanId'] ?? '');

            /*
             * A parent that is not in this result set makes the span a root for
             * rendering purposes. Anything else would hide it: the alternative
             * is a span that exists, was queried, and is simply never drawn.
             */
            if ($parentId === '' || ! isset($byId[$parentId])) {
                $roots[] = $span;

                continue;
            }

            $children[$parentId][] = $span;
        }

        $flattened = [];
        $visited = [];

        foreach ($roots as $root) {
            self::walk($root, $children, $visited, 0, $flattened);
        }

        /*
         * Anything still unvisited is in a parent cycle — every span in it has a
         * parent that is present, so none of them was ever a root. Impossible
         * from a conforming SDK, trivial to send by hand. They are appended at
         * root level rather than silently lost.
         */
        foreach ($spans as $span) {
            $spanId = (string) ($span['spanId'] ?? '');

            if ($spanId === '' || isset($visited[$spanId])) {
                continue;
            }

            self::walk($span, $children, $visited, 0, $flattened);
        }

        return $flattened;
    }

    /**
     * Emit a span, then its children, deepening as it goes.
     *
     * @param  array<string, mixed>  $span
     * @param  array<string, list<array<string, mixed>>>  $children
     * @param  array<string, true>  $visited
     * @param  list<array<string, mixed>>  $flattened
     */
    private static function walk(array $span, array $children, array &$visited, int $depth, array &$flattened): void
    {
        $spanId = (string) ($span['spanId'] ?? '');

        if ($spanId !== '') {
            if (isset($visited[$spanId])) {
                return;
            }

            $visited[$spanId] = true;
        }

        $span['depth'] = $depth;
        $span['childCount'] = count($children[$spanId] ?? []);
        $flattened[] = $span;

        foreach ($children[$spanId] ?? [] as $child) {
            self::walk($child, $children, $visited, $depth + 1, $flattened);
        }
    }
}
