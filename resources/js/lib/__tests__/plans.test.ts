import { describe, expect, it } from 'vitest';
import {
    allowanceBarClass,
    allowanceLevel,
    allowancePercent,
    allowanceTextClass,
} from '@/lib/plans';

describe('allowanceLevel', () => {
    it('rests below the warn threshold', () => {
        expect(allowanceLevel(0, 3, 80)).toBe('ok');
        expect(allowanceLevel(2, 3, 80)).toBe('ok');
        expect(allowanceLevel(79_999, 100_000, 80)).toBe('ok');
    });

    it('warns from the threshold up to the limit', () => {
        expect(allowanceLevel(80_000, 100_000, 80)).toBe('warn');
        expect(allowanceLevel(99_999, 100_000, 80)).toBe('warn');
    });

    it('reads as over once the allowance is reached', () => {
        // Reaching the limit is already "over": the fifth of five seats is
        // spent, and the sixth invitation is the conversation this warns about.
        expect(allowanceLevel(100_000, 100_000, 80)).toBe('over');
        expect(allowanceLevel(140_000, 100_000, 80)).toBe('over');
    });

    it('treats an unmeasured allowance as comfortable', () => {
        expect(allowanceLevel(500, 0, 80)).toBe('ok');
        expect(allowanceLevel(500, -1, 80)).toBe('ok');
    });
});

describe('allowancePercent', () => {
    it('draws nothing when nothing has been spent', () => {
        expect(allowancePercent(0, 100_000)).toBe(0);
        expect(allowancePercent(10, 0)).toBe(0);
    });

    it('floors a tiny share at a visible sliver', () => {
        // One event against a hundred thousand is 0.001% and would draw no pixels.
        expect(allowancePercent(1, 100_000)).toBe(2);
    });

    it('is the plain share in between', () => {
        expect(allowancePercent(50_000, 100_000)).toBe(50);
    });

    it('never overflows the track', () => {
        expect(allowancePercent(140_000, 100_000)).toBe(100);
    });
});

describe('allowance classes', () => {
    it('spends a chart token at rest and the severity ramp above it', () => {
        expect(allowanceBarClass('ok')).toBe('bg-chart-2');
        expect(allowanceBarClass('warn')).toBe('bg-severity-warn');
        expect(allowanceBarClass('over')).toBe('bg-severity-error');
    });

    it('keeps the resting fraction achromatic', () => {
        expect(allowanceTextClass('ok')).toBe('text-muted-foreground');
        expect(allowanceTextClass('warn')).toBe('text-severity-warn');
        expect(allowanceTextClass('over')).toBe('text-severity-error');
    });
});
