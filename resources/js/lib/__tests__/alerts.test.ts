import { describe, expect, it } from 'vitest';
import {
    breachRanges,
    canCompare,
    compareLabel,
    conditionHolds,
    describeQuery,
    formatAlertValue,
    formatCondition,
    formatLabels,
    formatNumber,
    formatReading,
    formatSeconds,
    isUtilization,
    previewSeriesName,
    sinceSeconds,
    thresholdHint,
} from '@/lib/alerts';

describe('formatSeconds', () => {
    it.each([
        [0, '0s'],
        [45, '45s'],
        [60, '1m'],
        [300, '5m'],
        [3600, '1h'],
        [5400, '1h 30m'],
        [86400, '1d'],
        [90061, '1d 1h 1m 1s'],
    ])('%i seconds reads %s', (seconds, expected) => {
        expect(formatSeconds(seconds)).toBe(expected);
    });

    it('refuses nonsense', () => {
        expect(formatSeconds(-1)).toBe('—');
        expect(formatSeconds(Number.NaN)).toBe('—');
    });
});

describe('formatAlertValue', () => {
    it('reads each aggregation in its own unit', () => {
        expect(formatAlertValue(1204, 'logs', 'count')).toBe('1,204');
        expect(formatAlertValue(12.5, 'logs', 'rate')).toBe('12.5/min');
        expect(formatAlertValue(0.05, 'logs', 'ratio')).toBe('5%');
        expect(formatAlertValue(0.0042, 'traces', 'error_ratio')).toBe('0.42%');
        expect(formatAlertValue(512, 'traces', 'p95')).toBe('512 ms');
        expect(formatAlertValue(1500, 'traces', 'p99')).toBe('1.50 s');
        expect(formatAlertValue(0.912345, 'metrics', 'avg')).toBe('0.9123');
    });

    it('prints a dash for no value', () => {
        expect(formatAlertValue(null, 'logs', 'count')).toBe('—');
        expect(formatAlertValue(Number.NaN, 'metrics', 'avg')).toBe('—');
    });
});

describe('formatNumber', () => {
    it('drops float noise', () => {
        expect(formatNumber(0.1 + 0.2)).toBe('0.3');
        expect(formatNumber(1234.5678)).toBe('1,234.6');
        expect(formatNumber(25)).toBe('25');
    });
});

describe('formatCondition', () => {
    it('says a latency rule the way a person would', () => {
        expect(
            formatCondition({
                source: 'traces',
                query: { aggregation: 'p95' },
                condition: '>',
                threshold: 500,
                forSeconds: 300,
            }),
        ).toBe('p95 > 500 ms for 5m');
    });

    it('leaves out a zero `for`', () => {
        expect(
            formatCondition({
                source: 'logs',
                query: { aggregation: 'count' },
                condition: '>=',
                threshold: 25,
                forSeconds: 0,
            }),
        ).toBe('count >= 25');
    });

    it('prints a ratio threshold as a percentage', () => {
        expect(
            formatCondition({
                source: 'traces',
                query: { aggregation: 'error_ratio' },
                condition: '>',
                threshold: 0.02,
            }),
        ).toBe('error ratio > 2%');
    });
});

describe('describeQuery', () => {
    it('names what a log rule counts', () => {
        expect(
            describeQuery('logs', {
                aggregation: 'count',
                filters: {
                    services: ['checkout'],
                    severities: ['error', 'fatal'],
                    search: 'timeout',
                },
            }),
        ).toBe('count of error, fatal logs from checkout matching “timeout”');
    });

    it('names a span percentile', () => {
        expect(
            describeQuery('traces', {
                aggregation: 'p95',
                filters: { kind: 'Server', services: ['payments-api'] },
            }),
        ).toBe('p95 of server span duration from payments-api');
    });

    it('names a metric with its attribute filters', () => {
        expect(
            describeQuery('metrics', {
                aggregation: 'avg',
                metric: 'system.cpu.utilization',
                filters: { 'host.name': 'web-1' },
            }),
        ).toBe('avg system.cpu.utilization where host.name=web-1');
    });
});

describe('labels', () => {
    it('sorts keys so equal labels always read alike', () => {
        expect(
            formatLabels({ 'service.name': 'checkout', 'host.name': 'web-1' }),
        ).toBe('host.name=web-1, service.name=checkout');
    });

    it('says when a series is the whole rule', () => {
        expect(formatLabels({})).toBe('all series');
    });

    it('names a preview line by its label values', () => {
        expect(
            previewSeriesName({
                labels: { 'service.name': 'checkout' },
                points: [],
            }),
        ).toBe('checkout');
        expect(previewSeriesName({ labels: {}, points: [] }, 'count')).toBe(
            'count',
        );
    });
});

describe('conditions and breaches', () => {
    it('compares like the evaluator', () => {
        expect(conditionHolds(26, '>', 25)).toBe(true);
        expect(conditionHolds(25, '>', 25)).toBe(false);
        expect(conditionHolds(25, '>=', 25)).toBe(true);
        expect(conditionHolds(3, '<', 5)).toBe(true);
        expect(conditionHolds(null, '<', 5)).toBe(false);
    });

    it('merges the buckets where any series breaches', () => {
        const ranges = breachRanges(
            [
                {
                    labels: { s: 'a' },
                    points: [
                        [0, 1],
                        [60, 30],
                        [120, 40],
                        [180, 2],
                        [240, 50],
                    ],
                },
                {
                    labels: { s: 'b' },
                    points: [
                        [0, 1],
                        [60, 1],
                        [120, 1],
                        [180, 30],
                        [240, null],
                    ],
                },
            ],
            '>',
            25,
        );

        expect(ranges).toEqual([[1, 4]]);
    });

    it('keeps separate breaches apart', () => {
        expect(
            breachRanges(
                [
                    {
                        labels: {},
                        points: [
                            [0, 30],
                            [60, 1],
                            [120, 30],
                        ],
                    },
                ],
                '>',
                25,
            ),
        ).toEqual([
            [0, 0],
            [2, 2],
        ]);
    });
});

describe('sinceSeconds and hints', () => {
    it('measures from an ISO timestamp', () => {
        expect(
            sinceSeconds(
                '2026-11-08T12:00:00Z',
                Date.parse('2026-11-08T12:05:00Z'),
            ),
        ).toBe(300);
        expect(sinceSeconds(null)).toBeNull();
    });

    it('tells the editor what unit a threshold is in', () => {
        expect(thresholdHint('traces', 'p95')).toBe('Milliseconds.');
        expect(thresholdHint('logs', 'ratio')).toContain('0 to 1');
    });
});

describe('baseline comparison and minimum volume', () => {
    it('says a compared rule with a volume guard as a multiple of its baseline', () => {
        expect(
            formatCondition({
                source: 'logs',
                query: {
                    aggregation: 'count',
                    compare: { offset_seconds: 604800 },
                    min_events: 20,
                },
                condition: '>',
                threshold: 3,
            }),
        ).toBe('count > 3× last week (min 20)');
    });

    it('keeps the `for` before the volume guard', () => {
        expect(
            formatCondition({
                source: 'traces',
                query: {
                    aggregation: 'p95',
                    compare: { offset_seconds: 86400 },
                    min_events: 1500,
                },
                condition: '>',
                threshold: 2,
                forSeconds: 600,
            }),
        ).toBe('p95 > 2× yesterday for 10m (min 1,500)');
    });

    it('adds a volume guard to a plain threshold', () => {
        expect(
            formatCondition({
                source: 'traces',
                query: { aggregation: 'error_ratio', min_events: 50 },
                condition: '>',
                threshold: 0.05,
            }),
        ).toBe('error ratio > 5% (min 50)');
    });

    it('prints a compared value as × normal, whatever the aggregation', () => {
        expect(
            formatAlertValue(4.2, 'traces', 'p95', { offset_seconds: 604800 }),
        ).toBe('4.2× normal');
        expect(formatAlertValue(4.2, 'traces', 'p95', null)).toBe('4.2 ms');
        expect(
            formatAlertValue(null, 'logs', 'count', { offset_seconds: 86400 }),
        ).toBe('—');
    });

    it('names the baseline', () => {
        expect(compareLabel({ offset_seconds: 86400 })).toBe('yesterday');
        expect(compareLabel({ offset_seconds: 604800 })).toBe('last week');
        expect(compareLabel({ offset_seconds: 7200 })).toBe('2h earlier');
    });

    it('offers comparison only where the sources accept it', () => {
        expect(canCompare('logs', 'count')).toBe(true);
        expect(canCompare('logs', 'rate')).toBe(true);
        expect(canCompare('logs', 'ratio')).toBe(false);
        expect(canCompare('traces', 'error_ratio')).toBe(true);
        expect(canCompare('metrics', 'avg')).toBe(false);
    });

    it('tells the editor the threshold is a multiple', () => {
        expect(
            thresholdHint('logs', 'count', { offset_seconds: 604800 }),
        ).toContain('3× normal');
    });
});

describe('utilization metrics', () => {
    it('recognises OTel *.utilization gauges as shares', () => {
        expect(isUtilization('metrics', 'system.filesystem.utilization')).toBe(
            true,
        );
        expect(isUtilization('metrics', 'system.cpu.time')).toBe(false);
        expect(isUtilization('logs', 'system.cpu.utilization')).toBe(false);
        expect(isUtilization('metrics', undefined)).toBe(false);
    });

    it('prints a utilization value and threshold as percentages', () => {
        expect(
            formatAlertValue(
                0.93,
                'metrics',
                'max',
                null,
                'system.memory.utilization',
            ),
        ).toBe('93%');
        expect(
            formatCondition({
                source: 'metrics',
                query: {
                    aggregation: 'max',
                    metric: 'system.filesystem.utilization',
                },
                condition: '>',
                threshold: 0.9,
            }),
        ).toBe('max > 90%');
        expect(
            formatAlertValue(0.93, 'metrics', 'max', null, 'http.requests'),
        ).toBe('0.93');
    });
});

describe('formatReading', () => {
    it('says a compared value as a multiple of normal against the baseline', () => {
        expect(
            formatReading({
                source: 'logs',
                query: {
                    aggregation: 'count',
                    compare: { offset_seconds: 604800 },
                },
                condition: '>',
                threshold: 3,
                value: 4.2,
            }),
        ).toEqual({ value: '4.2× normal', threshold: '> 3× last week' });
    });

    it('names a percentile, since a duration alone does not say of what', () => {
        expect(
            formatReading({
                source: 'traces',
                query: { aggregation: 'p95' },
                condition: '>',
                threshold: 500,
                value: 812,
            }).value,
        ).toMatch(/^p95 812/);
    });

    it('prints a utilization share as a percentage', () => {
        expect(
            formatReading({
                source: 'metrics',
                query: {
                    aggregation: 'max',
                    metric: 'system.filesystem.utilization',
                },
                condition: '>',
                threshold: 0.9,
                value: 0.93,
            }),
        ).toEqual({ value: '93%', threshold: '> 90%' });
    });

    it('says no data rather than a dash', () => {
        expect(
            formatReading({
                source: 'metrics',
                query: {
                    aggregation: 'last',
                    metric: 'system.memory.utilization',
                },
                condition: '<',
                threshold: 0,
                value: null,
            }).value,
        ).toBe('no data');
    });
});
