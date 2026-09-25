import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    bucketLabel,
    catalogByType,
    escapeHtml,
    formatCompactNumber,
    formatMetricValue,
    metricFilterQuery,
    metricReading,
    metricUnitLabel,
    seriesName,
    visibleSeries,
} from '@/lib/metrics';
import type {
    MetricCatalogEntry,
    MetricFilters,
    MetricSeries,
    MetricSeriesResult,
} from '@/types';

const filters: MetricFilters = {
    project: 'checkout',
    service: null,
    metric: 'http.server.request.duration',
    where: { 'http.route': '/orders/{id}', 'http.request.method': 'POST' },
    groupBy: 'http.response.status_code',
    agg: 'avg',
    from: '2026-09-25T10:00:00.000Z',
    to: '2026-09-25T11:00:00.000Z',
};

function result(
    overrides: Partial<MetricSeriesResult> = {},
): MetricSeriesResult {
    return {
        metric: 'http.server.request.duration',
        type: 'histogram',
        kind: 'distribution',
        unit: 's',
        intervalSeconds: 60,
        buckets: ['2026-09-25 10:00:00', '2026-09-25 10:01:00'],
        series: [],
        truncatedGroups: 0,
        droppedSeries: 0,
        approximate: false,
        unavailable: false,
        ...overrides,
    };
}

function line(overrides: Partial<MetricSeries>): MetricSeries {
    return {
        label: 'p95',
        group: null,
        stat: 'p95',
        points: [0.1, 0.2],
        ...overrides,
    };
}

function entry(overrides: Partial<MetricCatalogEntry>): MetricCatalogEntry {
    return {
        name: 'http.server.requests',
        type: 'sum',
        unit: '{request}',
        description: '',
        services: ['checkout'],
        monotonic: true,
        temporality: 2,
        points: 100,
        ...overrides,
    };
}

describe('metricFilterQuery', () => {
    it('keeps a custom range, spells where as brackets and drops the default agg', () => {
        expect(metricFilterQuery(filters, 'custom')).toEqual({
            project: 'checkout',
            metric: 'http.server.request.duration',
            group_by: 'http.response.status_code',
            from: '2026-09-25T10:00:00.000Z',
            to: '2026-09-25T11:00:00.000Z',
            'where[http.route]': '/orders/{id}',
            'where[http.request.method]': 'POST',
        });
    });

    it('keeps a non-default aggregation', () => {
        expect(
            metricFilterQuery({ ...filters, agg: 'max' }, 'custom').agg,
        ).toBe('max');
    });

    it('replaces the whole where map and lets scalar changes override', () => {
        const query = metricFilterQuery(filters, 'custom', {
            where: { 'service.version': '2.4.1' },
            group_by: null,
            service: 'checkout-api',
        });

        expect(query['where[http.route]']).toBeUndefined();
        expect(query['where[service.version]']).toBe('2.4.1');
        expect(query.group_by).toBeUndefined();
        expect(query.service).toBe('checkout-api');
    });

    it('never sends more than five filters, nor an empty one', () => {
        const query = metricFilterQuery(filters, 'custom', {
            where: { a: '1', b: '2', c: '3', d: '', e: '5', f: '6', g: '7' },
        });

        expect(
            Object.keys(query).filter((key) => key.startsWith('where[')),
        ).toEqual(['where[a]', 'where[b]', 'where[c]', 'where[e]']);
    });

    describe('with a preset window', () => {
        beforeEach(() => {
            vi.useFakeTimers();
            vi.setSystemTime(new Date('2026-09-25T12:00:00.000Z'));
        });

        afterEach(() => {
            vi.useRealTimers();
        });

        it('resolves the preset against the clock', () => {
            const query = metricFilterQuery(filters, '1h');

            expect(query.from).toBe('2026-09-25T11:00:00.000Z');
            expect(query.to).toBe('2026-09-25T12:00:00.000Z');
        });
    });
});

describe('formatCompactNumber', () => {
    it('shortens large numbers and keeps three digits of small ones', () => {
        expect(formatCompactNumber(0)).toBe('0');
        expect(formatCompactNumber(42)).toBe('42');
        expect(formatCompactNumber(0.123456)).toBe('0.123');
        expect(formatCompactNumber(1_234)).toBe('1.2k');
        expect(formatCompactNumber(12_345)).toBe('12.3k');
        expect(formatCompactNumber(123_456)).toBe('123k');
        expect(formatCompactNumber(3_400_000)).toBe('3.4M');
        expect(formatCompactNumber(2_000)).toBe('2k');
        expect(formatCompactNumber(-1_500)).toBe('-1.5k');
    });

    it('steps up a suffix rather than printing 1000k', () => {
        expect(formatCompactNumber(999_999)).toBe('1M');
    });
});

describe('formatMetricValue', () => {
    it('formats durations in every time unit through the trace formatter', () => {
        expect(formatMetricValue(0.25, 's')).toBe('250 ms');
        expect(formatMetricValue(1.5, 's')).toBe('1.50 s');
        expect(formatMetricValue(12, 'ms')).toBe('12 ms');
        expect(formatMetricValue(400, 'us')).toBe('400 µs');
        expect(formatMetricValue(2_000_000, 'ns')).toBe('2.0 ms');
    });

    it('formats bytes, including the binary prefixes', () => {
        expect(formatMetricValue(512, 'By')).toBe('512 B');
        expect(formatMetricValue(1_572_864, 'By')).toBe('1.5 MB');
        expect(formatMetricValue(2, 'KiBy')).toBe('2.0 KB');
        expect(formatMetricValue(512, 'MiBy')).toBe('512 MB');
    });

    it('prints ratios, percentages and counts bare', () => {
        expect(formatMetricValue(0.42, '1')).toBe('0.42');
        expect(formatMetricValue(87.5, '%')).toBe('87.5%');
        expect(formatMetricValue(1_200, '{request}')).toBe('1.2k');
        expect(formatMetricValue(3, '')).toBe('3');
    });

    it('appends an unknown unit as sent', () => {
        expect(formatMetricValue(21.5, 'Cel')).toBe('21.5 Cel');
    });

    it('marks a rate per second', () => {
        expect(formatMetricValue(12.5, '{request}', true)).toBe('12.5/s');
        expect(formatMetricValue(2048, 'By', true)).toBe('2.0 KB/s');
    });

    it('renders a missing point as a dash', () => {
        expect(formatMetricValue(null, 's')).toBe('—');
        expect(formatMetricValue(Number.NaN, 'By')).toBe('—');
    });
});

describe('metricUnitLabel', () => {
    it('names the dimension, per second for a rate', () => {
        expect(metricUnitLabel('{request}', 'rate')).toBe('requests/s');
        expect(metricUnitLabel('{request}', 'value')).toBe('requests');
        expect(metricUnitLabel('By', 'value')).toBe('bytes');
        expect(metricUnitLabel('By', 'rate')).toBe('bytes/s');
        expect(metricUnitLabel('s', 'distribution')).toBe('duration');
        expect(metricUnitLabel('s', 'rate')).toBe('seconds/s');
        expect(metricUnitLabel('1', 'value')).toBe('ratio');
        expect(metricUnitLabel('Cel', 'value')).toBe('Cel');
    });
});

describe('metricReading', () => {
    it('says how each type and temporality is drawn', () => {
        expect(metricReading(entry({}))).toBe(
            'cumulative counter — shown as a rate',
        );
        expect(metricReading(entry({ temporality: 1 }))).toBe(
            'delta counter — shown as a rate',
        );
        expect(metricReading(entry({ monotonic: false }))).toBe(
            'up-down counter — shown as a level',
        );
        expect(metricReading(entry({ type: 'gauge' }))).toBe(
            'gauge — shown as a level',
        );
    });
});

describe('visibleSeries', () => {
    it('draws every stat of an ungrouped distribution', () => {
        const series = [
            line({ label: 'p50', stat: 'p50' }),
            line({ label: 'p95', stat: 'p95' }),
            line({ label: 'p99', stat: 'p99' }),
        ];

        expect(visibleSeries(result({ series }), 'p95')).toHaveLength(3);
    });

    it('narrows a grouped distribution to the chosen percentile', () => {
        const series = ['p50', 'p95'].flatMap((stat) =>
            ['200', '500'].map((group) =>
                line({ label: `${group} ${stat}`, group, stat }),
            ),
        );

        expect(
            visibleSeries(result({ series }), 'p50').map(
                (entry) => entry.label,
            ),
        ).toEqual(['200 p50', '500 p50']);
    });

    it('falls back to the first stat when the chosen one is missing', () => {
        const series = [
            line({ label: 'a p90', group: 'a', stat: 'p90' }),
            line({ label: 'a p99', group: 'a', stat: 'p99' }),
        ];

        expect(
            visibleSeries(result({ kind: 'summary', series }), 'p95').map(
                (entry) => entry.stat,
            ),
        ).toEqual(['p90']);
    });

    it('never narrows a level or a rate', () => {
        const series = [
            line({ label: 'a', group: 'a', stat: 'rate' }),
            line({ label: 'b', group: 'b', stat: 'rate' }),
        ];

        expect(visibleSeries(result({ kind: 'rate', series }), 'p95')).toEqual(
            series,
        );
    });
});

describe('seriesName', () => {
    it('names a grouped percentile line by its group', () => {
        expect(
            seriesName(line({ group: '/orders', label: '/orders p95' }), true),
        ).toBe('/orders');
        expect(seriesName(line({ group: '', label: ' p95' }), true)).toBe(
            '(not set)',
        );
        expect(seriesName(line({ label: 'p99' }), false)).toBe('p99');
    });
});

describe('catalogByType', () => {
    it('groups in picker order and drops empty types', () => {
        const groups = catalogByType([
            entry({ name: 'b', type: 'histogram' }),
            entry({ name: 'a', type: 'gauge' }),
            entry({ name: 'c', type: 'gauge' }),
        ]);

        expect(groups.map((group) => group.type)).toEqual([
            'gauge',
            'histogram',
        ]);
        expect(groups[0].metrics.map((metric) => metric.name)).toEqual([
            'a',
            'c',
        ]);
    });
});

describe('bucketLabel', () => {
    const at = new Date('2026-09-25T09:05:30.000Z');

    it('picks the precision from the bucket width', () => {
        expect(bucketLabel(at, 60, true)).toBe('09:05');
        expect(bucketLabel(at, 10, true)).toBe('09:05:30');
        expect(bucketLabel(at, 86_400, true)).toBe('Sep 25');
    });

    it('is empty for an unparseable date', () => {
        expect(bucketLabel(new Date('nope'), 60)).toBe('');
    });
});

describe('escapeHtml', () => {
    it('neutralises markup in a group name', () => {
        expect(escapeHtml('<img src=x onerror="alert(1)">')).toBe(
            '&lt;img src=x onerror=&quot;alert(1)&quot;&gt;',
        );
    });
});
