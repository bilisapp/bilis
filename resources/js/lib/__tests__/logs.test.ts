import { describe, expect, it } from 'vitest';
import {
    formatDayHeading,
    formatLocalTime,
    localDayKey,
    splitExceptionHeadline,
} from '@/lib/logs';

describe('splitExceptionHeadline', () => {
    it('lifts a bare, dotted or namespaced exception class off the body', () => {
        expect(splitExceptionHeadline('TypeError: Cannot read x')).toEqual({
            head: 'TypeError',
            rest: 'Cannot read x',
        });
        expect(splitExceptionHeadline('Error: Failed to load')).toEqual({
            head: 'Error',
            rest: 'Failed to load',
        });
        expect(
            splitExceptionHeadline('java.io.IOException: disk full'),
        ).toEqual({ head: 'java.io.IOException', rest: 'disk full' });
        expect(
            splitExceptionHeadline('App\\Exceptions\\PaymentFailed: no'),
        ).toEqual({ head: '', rest: 'App\\Exceptions\\PaymentFailed: no' });
        expect(
            splitExceptionHeadline('App\\Exceptions\\PaymentException: no'),
        ).toEqual({ head: 'App\\Exceptions\\PaymentException', rest: 'no' });
    });

    it('leaves ordinary sentences with a colon alone', () => {
        expect(splitExceptionHeadline('Order placed: #1234')).toEqual({
            head: '',
            rest: 'Order placed: #1234',
        });
        expect(splitExceptionHeadline('retry Error: later')).toEqual({
            head: '',
            rest: 'retry Error: later',
        });
    });
});

describe('day and time helpers', () => {
    it('splits the local timestamp into a day key and a time of day', () => {
        const stamp = '2026-11-07 14:20:31.402000000';

        expect(formatLocalTime(stamp)).toMatch(/^\d{2}:\d{2}:\d{2}\.\d{3}$/);
        expect(localDayKey(stamp)).toMatch(/^\d{4}-\d{2}-\d{2}$/);
    });

    it('names today and yesterday, and dates anything older', () => {
        const now = new Date('2026-11-07T12:00:00Z');

        expect(formatDayHeading('2026-11-07 11:00:00.000', now).label).toBe(
            'Today',
        );
        expect(formatDayHeading('2026-11-06 11:00:00.000', now).label).toBe(
            'Yesterday',
        );
        expect(formatDayHeading('2026-11-01 11:00:00.000', now).label).toMatch(
            /Nov 1/,
        );
    });
});
