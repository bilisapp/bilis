import { describe, expect, it } from 'vitest';
import {
    matchingSpanIds,
    parseClickHouseTimestamp,
    serviceColours,
    spanAncestors,
    spanHaystack,
    spanStartMs,
    traceExtentMs,
    traceFilterQuery,
    traceHref,
    waterfallGeometry,
} from '@/lib/traces';
import type { Span, TraceFilters } from '@/types';

function span(overrides: Partial<Span> & { spanId: string }): Span {
    return {
        timestamp: '2026-08-30 20:34:07.000000000',
        traceId: '5b8efff798038103d269b633813fc60c',
        parentSpanId: '',
        name: 'span',
        kind: 'Internal',
        serviceName: 'api',
        durationMs: 10,
        statusCode: 'Unset',
        statusMessage: '',
        attributes: {},
        events: [],
        links: [],
        depth: 0,
        childCount: 0,
        ...overrides,
    };
}

const EPOCH = Date.UTC(2026, 7, 30, 20, 34, 7);

describe('parseClickHouseTimestamp', () => {
    it('parses the space-separated, nine-digit form ClickHouse prints', () => {
        expect(parseClickHouseTimestamp('2026-08-30 20:34:07.438000000')).toBe(
            EPOCH + 438,
        );
    });

    it('keeps sub-millisecond precision instead of rounding to the ms', () => {
        expect(
            parseClickHouseTimestamp('2026-08-30 20:34:07.438251000'),
        ).toBeCloseTo(EPOCH + 438.251, 6);
    });

    it.each([
        ['2026-08-30T20:34:07.438Z', EPOCH + 438],
        ['2026-08-30T20:34:07.438', EPOCH + 438],
        ['2026-08-30 20:34:07.438', EPOCH + 438],
        ['2026-08-30 20:34:07.438000', EPOCH + 438],
        ['2026-08-30 20:34:07', EPOCH],
        ['2026-08-30T20:34:07Z', EPOCH],
        ['2026-08-30 20:34:07.4', EPOCH + 400],
    ])('normalises %s', (input, expected) => {
        expect(parseClickHouseTimestamp(input)).toBe(expected);
    });

    it('never hands the raw ClickHouse string to Date.parse', () => {
        // Safari returns NaN for this grammar; the parser must not depend on
        // the engine being lenient, so the answer must come from the pieces.
        const value = parseClickHouseTimestamp('2026-08-30 20:34:07.438000000');

        expect(Number.isFinite(value)).toBe(true);
        expect(new Date(value).toISOString()).toBe('2026-08-30T20:34:07.438Z');
    });

    it('honours an explicit offset', () => {
        expect(parseClickHouseTimestamp('2026-08-30 22:34:07+02:00')).toBe(
            EPOCH,
        );
    });

    it('returns NaN for garbage rather than throwing', () => {
        expect(parseClickHouseTimestamp('not a time')).toBeNaN();
        expect(parseClickHouseTimestamp('')).toBeNaN();
    });

    it('is what spanStartMs reads', () => {
        expect(
            spanStartMs(
                span({ spanId: 'a', timestamp: '2026-08-30 20:34:07.5' }),
            ),
        ).toBe(EPOCH + 500);
    });
});

describe('waterfallGeometry', () => {
    const spans = [
        span({
            spanId: 'root',
            timestamp: '2026-08-30 20:34:07.000000000',
            durationMs: 200,
        }),
        span({
            spanId: 'mid',
            timestamp: '2026-08-30 20:34:07.050000000',
            durationMs: 100,
            parentSpanId: 'root',
            depth: 1,
        }),
        span({
            spanId: 'tail',
            timestamp: '2026-08-30 20:34:07.190000000',
            durationMs: 0,
            parentSpanId: 'root',
            depth: 1,
        }),
    ];

    it('measures every bar against the earliest span present', () => {
        const geometry = waterfallGeometry(spans);

        expect(geometry.get('root')).toEqual({
            offsetPercent: 0,
            widthPercent: 100,
        });
        expect(geometry.get('mid')).toEqual({
            offsetPercent: 25,
            widthPercent: 50,
        });
        expect(traceExtentMs(spans)).toBe(200);
    });

    it('floors an instantaneous span so it is still a visible mark', () => {
        expect(waterfallGeometry(spans).get('tail')?.widthPercent).toBe(0.4);
        expect(waterfallGeometry(spans).get('tail')?.offsetPercent).toBe(95);
    });

    it('is finite on Safari-hostile input', () => {
        const geometry = waterfallGeometry([
            span({
                spanId: 'a',
                timestamp: '2026-08-30 20:34:07.438000000',
                durationMs: 5,
            }),
            span({
                spanId: 'b',
                timestamp: '2026-08-30 20:34:07.440500000',
                durationMs: 1,
            }),
        ]);

        for (const bar of geometry.values()) {
            expect(Number.isFinite(bar.offsetPercent)).toBe(true);
            expect(Number.isFinite(bar.widthPercent)).toBe(true);
        }

        expect(geometry.get('b')?.offsetPercent).toBeCloseTo(50, 6);
    });

    it('places an unparseable timestamp at the start instead of poisoning the rest', () => {
        const geometry = waterfallGeometry([
            span({ spanId: 'ok', durationMs: 100 }),
            span({ spanId: 'bad', timestamp: 'garbage', durationMs: 10 }),
        ]);

        expect(geometry.get('ok')).toEqual({
            offsetPercent: 0,
            widthPercent: 100,
        });
        expect(geometry.get('bad')).toEqual({
            offsetPercent: 0,
            widthPercent: 10,
        });
    });

    it('draws something when every span shares one instant', () => {
        const geometry = waterfallGeometry([
            span({ spanId: 'a', durationMs: 0 }),
            span({ spanId: 'b', durationMs: 0 }),
        ]);

        expect(geometry.get('a')).toEqual({
            offsetPercent: 0,
            widthPercent: 0.4,
        });
        expect(traceExtentMs([])).toBe(0);
        expect(waterfallGeometry([]).size).toBe(0);
    });
});

describe('serviceColours', () => {
    it('assigns slots by first appearance and cycles past five', () => {
        const colours = serviceColours(
            [
                'gateway',
                'checkout',
                'gateway',
                'payments',
                'ledger',
                'mail',
                'search',
                '',
            ].map((serviceName, index) =>
                span({ spanId: String(index), serviceName }),
            ),
        );

        expect([...colours.entries()]).toEqual([
            ['gateway', 'bg-chart-1'],
            ['checkout', 'bg-chart-2'],
            ['payments', 'bg-chart-3'],
            ['ledger', 'bg-chart-4'],
            ['mail', 'bg-chart-5'],
            ['search', 'bg-chart-1'],
            ['unknown', 'bg-chart-2'],
        ]);
    });
});

describe('traceFilterQuery', () => {
    const filters: TraceFilters = {
        project: '3',
        service: null,
        errors: true,
        minDuration: 250,
        from: '2026-08-30T10:00:00.000Z',
        to: '2026-08-30T11:00:00.000Z',
        cursor: null,
    };

    it('keeps absolute bounds for a custom range and drops empty values', () => {
        expect(traceFilterQuery(filters, 'custom')).toEqual({
            project: '3',
            errors: '1',
            min_duration: '250',
            from: '2026-08-30T10:00:00.000Z',
            to: '2026-08-30T11:00:00.000Z',
        });
    });

    it('resolves a preset against the clock and lets changes override', () => {
        const query = traceFilterQuery(filters, '1h', {
            errors: false,
            service: 'checkout',
        });

        expect(query.errors).toBeUndefined();
        expect(query.service).toBe('checkout');
        expect(Date.parse(query.to) - Date.parse(query.from)).toBe(60 * 60_000);
    });
});

describe('traceHref', () => {
    it('carries ts and span through the query, encoded', () => {
        expect(
            traceHref('acme', '5b8efff798038103d269b633813fc60c', {
                ts: '2026-08-30 20:34:07.438000000',
                span: 'eee19b7ec3c1b174',
            }),
        ).toBe(
            '/acme/traces/5b8efff798038103d269b633813fc60c?ts=2026-08-30+20%3A34%3A07.438000000&span=eee19b7ec3c1b174',
        );
    });

    it('omits what it does not know', () => {
        expect(traceHref('acme', 'abc')).toBe('/acme/traces/abc');
        expect(traceHref('acme', 'abc', { ts: null, span: '' })).toBe(
            '/acme/traces/abc',
        );
    });
});

describe('span search', () => {
    const spans = [
        span({
            spanId: 'root',
            name: 'POST /checkout',
            serviceName: 'checkout',
            kind: 'Server',
            childCount: 1,
            attributes: { 'http.route': '/checkout' },
        }),
        span({
            spanId: 'charge',
            name: 'charge card',
            serviceName: 'payments',
            kind: 'Client',
            parentSpanId: 'root',
            depth: 1,
            childCount: 1,
        }),
        span({
            spanId: 'db',
            name: 'SELECT',
            serviceName: 'payments',
            parentSpanId: 'charge',
            depth: 2,
            statusCode: 'Error',
            statusMessage: 'deadlock detected',
            attributes: { 'db.system': 'postgresql', 'db.name': 'orders-db' },
        }),
        span({
            spanId: 'orphan',
            name: 'render',
            parentSpanId: 'missing',
            depth: 0,
        }),
    ];
    const haystacks = new Map(spans.map((s) => [s.spanId, spanHaystack(s)]));

    it('matches case-insensitively over name, service, kind, status message and attributes', () => {
        expect(matchingSpanIds(haystacks, 'CHECKOUT')).toEqual(
            new Set(['root']),
        );
        expect(matchingSpanIds(haystacks, 'payments')).toEqual(
            new Set(['charge', 'db']),
        );
        expect(matchingSpanIds(haystacks, 'client')).toEqual(
            new Set(['charge']),
        );
        expect(matchingSpanIds(haystacks, 'deadlock')).toEqual(new Set(['db']));
        expect(matchingSpanIds(haystacks, 'orders-db')).toEqual(
            new Set(['db']),
        );
        expect(matchingSpanIds(haystacks, 'db.system')).toEqual(
            new Set(['db']),
        );
    });

    it('requires every word and treats an empty query as no search', () => {
        expect(matchingSpanIds(haystacks, 'payments error')).toEqual(
            new Set(['db']),
        );
        expect(matchingSpanIds(haystacks, 'nothing-like-this')).toEqual(
            new Set(),
        );
        expect(matchingSpanIds(haystacks, '')).toBeNull();
        expect(matchingSpanIds(haystacks, '   ')).toBeNull();
    });

    it('walks ancestors through parentSpanId and stops at a missing parent', () => {
        expect(spanAncestors(spans, 'db')).toEqual(['charge', 'root']);
        expect(spanAncestors(spans, 'root')).toEqual([]);
        expect(spanAncestors(spans, 'orphan')).toEqual([]);
        expect(spanAncestors(spans, 'unknown')).toEqual([]);
    });

    it('terminates on a parent cycle', () => {
        const cyclic = [
            span({ spanId: 'a', parentSpanId: 'b' }),
            span({ spanId: 'b', parentSpanId: 'a' }),
        ];

        expect(spanAncestors(cyclic, 'a')).toEqual(['b']);
    });
});
