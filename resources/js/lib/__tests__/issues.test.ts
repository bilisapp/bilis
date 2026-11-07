import { describe, expect, it } from 'vitest';
import {
    contextSections,
    eventBreadcrumbs,
    eventCauses,
    eventFrames,
    formatCount,
    frameLocation,
    groupFrames,
    issueFilterQuery,
    isInAppFrame,
    isOpen,
    issueHref,
    parseCausesFromText,
    parseFramesJson,
    parseStackTrace,
    remainingAttributes,
} from '@/lib/issues';
import type { LogEntry } from '@/types';

function entry(
    logAttributes: Record<string, string>,
    resourceAttributes: Record<string, string> = {},
): LogEntry {
    return {
        projectId: '1',
        timestamp: '2026-11-07 11:50:00.000000000',
        traceId: '',
        spanId: '',
        severityText: 'ERROR',
        severityNumber: 17,
        serviceName: 'checkout',
        body: '',
        scopeName: '',
        scopeVersion: '',
        resourceAttributes,
        logAttributes,
    };
}

describe('parseStackTrace', () => {
    it('reads PHP frames, internal calls and {main}', () => {
        const frames = parseStackTrace(
            [
                '#0 /srv/app/app/Ledger.php(12): App\\Ledger->lock()',
                '#1 [internal function]: App\\Ledger->retry()',
                '#2 /srv/app/vendor/laravel/framework/src/Pipeline.php(180): Illuminate\\Pipeline->handle()',
                '#3 {main}',
            ].join('\n'),
        );

        expect(frames).toHaveLength(4);
        expect(frames[0]).toMatchObject({
            filename: '/srv/app/app/Ledger.php',
            lineno: 12,
            function: 'App\\Ledger->lock()',
            inApp: true,
        });
        expect(frames[1]).toMatchObject({
            filename: '[internal function]',
            inApp: false,
        });
        expect(frames[2].inApp).toBe(false);
        expect(frames[3]).toMatchObject({ function: '{main}', inApp: false });
    });

    it('reads the envelope mapper text form', () => {
        const frames = parseStackTrace(
            '#0 app/Payments/Charge.php:41 charge()\n#1 app/Http/Checkout.php:88',
        );

        expect(frames[0]).toMatchObject({
            filename: 'app/Payments/Charge.php',
            lineno: 41,
            function: 'charge',
        });
        expect(frames[1]).toMatchObject({
            filename: 'app/Http/Checkout.php',
            lineno: 88,
            function: '',
        });
    });

    it('reads V8 frames with and without a function name', () => {
        const frames = parseStackTrace(
            [
                'TypeError: Cannot read properties of undefined',
                '    at chargeCard (/app/src/payments.ts:41:13)',
                '    at async Router.handle (/app/node_modules/express/lib/router.js:280:5)',
                '    at /app/src/index.ts:9:1',
                '    at node:internal/process/task_queues:95:5',
            ].join('\n'),
        );

        expect(
            frames.map((frame) => [
                frame.function,
                frame.lineno,
                frame.colno,
                frame.inApp,
            ]),
        ).toEqual([
            ['chargeCard', 41, 13, true],
            ['Router.handle', 280, 5, false],
            ['', 9, 1, true],
            ['', 95, 5, false],
        ]);
    });

    it('reads Java frames and stops at the first cause', () => {
        const frames = parseStackTrace(
            [
                'java.lang.IllegalStateException: boom',
                '\tat com.acme.Billing.charge(Billing.java:42)',
                '\tat java.base/java.lang.Thread.run(Thread.java:833)',
                'Caused by: java.io.IOException: socket closed',
                '\tat com.acme.Net.read(Net.java:7)',
            ].join('\n'),
        );

        expect(frames).toHaveLength(2);
        expect(frames[0]).toMatchObject({
            module: 'com.acme.Billing',
            filename: 'Billing.java',
            lineno: 42,
            inApp: true,
        });
    });

    it('reverses a Python traceback and keeps its source lines', () => {
        const frames = parseStackTrace(
            [
                'Traceback (most recent call last):',
                '  File "/app/server.py", line 20, in handle',
                '    return charge(order)',
                '  File "/app/payments.py", line 41, in charge',
                '    raise PaymentFailed(order.id)',
                'PaymentFailed: 42',
            ].join('\n'),
        );

        expect(frames.map((frame) => frame.function)).toEqual([
            'charge',
            'handle',
        ]);
        expect(frames[0].contextLine).toBe('raise PaymentFailed(order.id)');
    });

    it('reads Ruby and Go frames', () => {
        expect(
            parseStackTrace("/app/models/user.rb:12:in 'save'")[0],
        ).toMatchObject({
            filename: '/app/models/user.rb',
            lineno: 12,
            function: 'save',
        });

        const go = parseStackTrace(
            [
                'goroutine 1 [running]:',
                'main.charge(0x1)',
                '\t/app/main.go:41 +0x1d',
            ].join('\n'),
        );

        expect(go[0]).toMatchObject({
            function: 'main.charge',
            filename: '/app/main.go',
            lineno: 41,
        });
    });

    it('ignores lines that are not frames', () => {
        expect(
            parseStackTrace('Something went wrong\nwith no stack at all'),
        ).toEqual([]);
    });
});

describe('structured frames and causes', () => {
    it('believes the SDK in_app flag and keeps context lines', () => {
        const frames = parseFramesJson(
            JSON.stringify([
                {
                    filename: 'app/Charge.php',
                    function: 'charge',
                    lineno: 41,
                    in_app: true,
                    pre_context: ['$a = 1;'],
                    context_line: 'throw new PaymentFailed();',
                    post_context: ['}'],
                },
                {
                    filename: 'app/Kernel.php',
                    function: 'handle',
                    lineno: 9,
                    in_app: false,
                },
            ]),
        );

        expect(frames).toHaveLength(2);
        expect(frames?.[0]).toMatchObject({
            inApp: true,
            contextLine: 'throw new PaymentFailed();',
            preContext: ['$a = 1;'],
        });
        expect(frames?.[1].inApp).toBe(false);
    });

    it('prefers structured frames and falls back to the text', () => {
        const structured = entry({
            'exception.frames': JSON.stringify([
                { filename: 'a.php', lineno: 1, in_app: true },
            ]),
        });

        expect(eventFrames(structured, '#0 b.php(2): x()')[0].filename).toBe(
            'a.php',
        );
        expect(
            eventFrames(
                entry({ 'exception.frames': 'not json' }),
                '#0 b.php(2): x()',
            )[0].filename,
        ).toBe('b.php');
        expect(eventFrames(null, '')).toEqual([]);
    });

    it('reads causes from the chain attribute or the printed stack', () => {
        expect(
            eventCauses(
                entry({
                    'exception.chain': JSON.stringify([
                        { type: 'PDOException', message: 'gone away' },
                    ]),
                }),
                '',
            ),
        ).toEqual([
            {
                type: 'PDOException',
                message: 'gone away',
                origin: '',
                stacktrace: '',
            },
        ]);

        const causes = parseCausesFromText(
            [
                'RuntimeException: outer',
                '#0 a.php(1): f()',
                'Caused by: PDOException: gone away',
                '#0 db.php(9): q()',
            ].join('\n'),
        );

        expect(causes).toHaveLength(1);
        expect(causes[0]).toMatchObject({
            type: 'PDOException',
            message: 'gone away',
            origin: 'db.php:9',
        });
    });
});

describe('groupFrames', () => {
    it('folds runs of library frames between application frames', () => {
        const frames = parseStackTrace(
            [
                '#0 /srv/app/app/A.php(1): a()',
                '#1 /srv/app/vendor/x/B.php(2): b()',
                '#2 /srv/app/vendor/x/C.php(3): c()',
                '#3 /srv/app/app/D.php(4): d()',
                '#4 /srv/app/vendor/x/E.php(5): e()',
            ].join('\n'),
        );

        expect(
            groupFrames(frames).map((group) =>
                group.kind === 'frame' ? group.index : group.frames.length,
            ),
        ).toEqual([0, 2, 3, 4]);
    });

    it("shows everything when nothing is the application's", () => {
        const frames = parseStackTrace(
            '#0 /x/vendor/a.php(1): a()\n#1 /x/vendor/b.php(2): b()',
        );

        expect(
            groupFrames(frames).every((group) => group.kind === 'frame'),
        ).toBe(true);
    });
});

describe('helpers', () => {
    it('decides in-app frames by path', () => {
        expect(isInAppFrame('/app/src/a.ts')).toBe(true);
        expect(isInAppFrame('/app/node_modules/x/a.js')).toBe(false);
        expect(isInAppFrame('/usr/lib/python3/site-packages/x.py')).toBe(false);
        expect(
            isInAppFrame(
                'Thread.java',
                'java.lang.Thread.run',
                'java.lang.Thread',
            ),
        ).toBe(false);
    });

    it('formats counts and locations', () => {
        expect(formatCount(950)).toBe('950');
        expect(formatCount(1_250)).toBe('1.2k');
        expect(formatCount(34_900)).toBe('34k');
        expect(formatCount(1_500_000)).toBe('1.5M');
        expect(frameLocation(parseStackTrace('    at f (/a.ts:4:2)')[0])).toBe(
            '/a.ts:4:2',
        );
    });

    it('treats regressed as open', () => {
        expect(isOpen('regressed')).toBe(true);
        expect(isOpen('resolved')).toBe(false);
    });

    it('builds issue links with event navigation', () => {
        const fingerprint = 'a'.repeat(64);

        expect(issueHref('acme', 'checkout', fingerprint)).toBe(
            `/acme/issues/checkout/${fingerprint}`,
        );
        expect(
            issueHref('acme', 'checkout', fingerprint, {
                position: 'older',
                event: '2026-11-07 11:50:00.000000000',
            }),
        ).toBe(
            `/acme/issues/checkout/${fingerprint}?position=older&event=2026-11-07+11%3A50%3A00.000000000`,
        );
    });
});

describe('breadcrumbs and context', () => {
    it('reads structured breadcrumbs and the plain text fallback', () => {
        expect(
            eventBreadcrumbs(
                entry({
                    'breadcrumbs.json': JSON.stringify([
                        {
                            category: 'http',
                            message: 'GET /cart',
                            level: 'info',
                        },
                    ]),
                }),
            )[0],
        ).toMatchObject({
            category: 'http',
            message: 'GET /cart',
            level: 'info',
        });

        expect(
            eventBreadcrumbs(
                entry({ breadcrumbs: '[query] select 1\nplain line' }),
            ),
        ).toMatchObject([
            { category: 'query', message: 'select 1' },
            { category: '', message: 'plain line' },
        ]);
    });

    it('lays out context panels and leaves the rest for the attribute list', () => {
        const event = entry(
            {
                'http.url': 'https://shop.test/cart',
                'user.id': '42',
                'exception.frames': '[]',
                'tag.region': 'eu',
                'cart.items': '3',
            },
            { 'host.name': 'web-1' },
        );

        expect(contextSections(event).map((section) => section.title)).toEqual([
            'Request',
            'User',
            'Runtime',
        ]);
        expect(remainingAttributes(event)).toEqual([['cart.items', '3']]);
    });
});

describe('issueFilterQuery', () => {
    const filters = {
        period: '7d',
        project: null,
        service: null,
        environment: null,
        status: 'unresolved',
        query: null,
        sort: 'last_seen',
        page: 3,
    } as const;

    it('drops defaults and resets the page on a filter change', () => {
        expect(
            issueFilterQuery({ ...filters }, { service: 'checkout' }),
        ).toEqual({ service: 'checkout' });
        expect(issueFilterQuery({ ...filters }, { page: 4 })).toEqual({
            page: '4',
        });
        expect(
            issueFilterQuery({ ...filters, period: '24h' }, { status: 'all' }),
        ).toEqual({ period: '24h', status: 'all' });
    });
});
