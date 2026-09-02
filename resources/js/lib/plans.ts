import type { PlanAllowanceLevel } from '@/types';

/**
 * Where one allowance sits: comfortable, close, or past.
 *
 * A limit of zero or less means "not measured" and always reads as `ok` —
 * there is nothing to be over. Nothing derived from this level ever blocks an
 * action; it only decides a colour and a sentence.
 */
export function allowanceLevel(
    used: number,
    limit: number,
    warnAtPercent: number,
): PlanAllowanceLevel {
    if (limit <= 0) {
        return 'ok';
    }

    if (used >= limit) {
        return 'over';
    }

    if ((used / limit) * 100 >= warnAtPercent) {
        return 'warn';
    }

    return 'ok';
}

/**
 * The bar's width, as a percentage of the allowance.
 *
 * Floored at a visible sliver whenever anything at all has been spent, the
 * same way `IngestRateCard` does it: one event against a million is 0.0001%
 * and would draw nothing, and "something is arriving" is the first thing the
 * row has to say. Capped at 100 because the bar cannot overflow its track —
 * the copy carries "over", not the geometry.
 */
export function allowancePercent(used: number, limit: number): number {
    if (limit <= 0 || used <= 0) {
        return 0;
    }

    return Math.min(100, Math.max(2, (used / limit) * 100));
}

/**
 * The bar's hue.
 *
 * A resting meter is chart data — usage is a quantity, not a warning — so it
 * takes a chart token. The two upper states borrow the severity ramp, which
 * is the same ladder the app spends everywhere else for "look at this" and
 * "this is bad".
 */
export function allowanceBarClass(level: PlanAllowanceLevel): string {
    if (level === 'over') {
        return 'bg-severity-error';
    }

    if (level === 'warn') {
        return 'bg-severity-warn';
    }

    return 'bg-chart-2';
}

/**
 * The matching text colour, for the fraction beside a meter.
 */
export function allowanceTextClass(level: PlanAllowanceLevel): string {
    if (level === 'over') {
        return 'text-severity-error';
    }

    if (level === 'warn') {
        return 'text-severity-warn';
    }

    return 'text-muted-foreground';
}
