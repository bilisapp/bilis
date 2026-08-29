/**
 * Keeps the landing page's log pane tailing.
 *
 * The pane is server-rendered complete, so this only ever adds to something
 * that already reads correctly: a new line at the top every second or two,
 * the oldest one off the bottom. It stops when the pane is off screen and it
 * never starts at all when the reader has asked for less motion — a stream
 * that scrolls past nobody is just a battery drain.
 */
type Line = {
    severity: string;
    service: string;
    message: string;
};

/** The severities the stylesheet actually has tokens for. */
const SEVERITIES = ['trace', 'debug', 'info', 'warn', 'error', 'fatal'];

const MIN_DELAY = 1400;
const MAX_DELAY = 2900;

const clock = (secondsAgo = 0): { time: string; ms: string } => {
    const now = new Date(Date.now() - secondsAgo * 1000);
    const pad = (value: number, length = 2): string =>
        String(value).padStart(length, '0');

    return {
        time: `${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())}`,
        ms: `.${pad(now.getMilliseconds(), 3)}`,
    };
};

const set = (row: HTMLElement, hook: string, text: string): void => {
    const target = row.querySelector<HTMLElement>(`[data-live-tail-${hook}]`);

    if (target) {
        target.textContent = text;
    }
};

const swap = (row: HTMLElement, hook: string, token: string): void => {
    const target = row.querySelector<HTMLElement>(`[data-live-tail-${hook}]`);

    if (target) {
        target.className = target.className.replace(
            /(text|bg)-severity-\w+/,
            (match) => `${match.split('-')[0]}-severity-${token}`,
        );
    }
};

/**
 * Build a row by cloning one already on the page.
 *
 * The server rendered the markup once; copying it means the class list lives
 * in the Blade partial alone and cannot drift away from it here.
 */
const render = (template: HTMLElement, line: Line): HTMLElement => {
    const row = template.cloneNode(true) as HTMLElement;
    const severity = SEVERITIES.includes(line.severity)
        ? line.severity
        : 'info';
    const now = clock();

    set(row, 'time', now.time);
    set(row, 'ms', now.ms);
    set(row, 'severity', severity.toUpperCase());
    set(row, 'service', line.service);
    set(row, 'message', line.message);
    swap(row, 'badge', severity);
    swap(row, 'dot', severity);

    // tw-animate-css: the row fades down into place rather than snapping in.
    row.classList.add(
        'animate-in',
        'fade-in',
        'slide-in-from-top-2',
        'duration-500',
    );

    return row;
};

const start = (pane: HTMLElement, pool: Line[]): void => {
    const list = pane.querySelector<HTMLElement>('[data-live-tail-list]');
    const counter = pane.querySelector<HTMLElement>('[data-live-tail-count]');
    const template = list?.firstElementChild as HTMLElement | undefined;

    if (!list || !template || pool.length === 0) {
        return;
    }

    const rows = list.children.length;
    let count = Number(counter?.textContent?.replace(/\D/g, '') ?? 0);
    let timer: number | undefined;
    let visible = false;

    /**
     * Any line but the one already on top.
     *
     * Walking the pool in order would march in step with the rows the server
     * rendered from that same pool, and the stream would visibly repeat.
     */
    const pick = (): Line => {
        const top =
            list.firstElementChild?.lastElementChild?.textContent?.trim();
        const candidates = pool.filter((line) => line.message !== top);

        return (
            candidates[Math.floor(Math.random() * candidates.length)] ?? pool[0]
        );
    };

    const tick = (): void => {
        list.prepend(render(template, pick()));

        while (list.children.length > rows) {
            list.lastElementChild?.remove();
        }

        count += 1;

        if (counter) {
            counter.textContent = count.toLocaleString();
        }

        timer = window.setTimeout(
            tick,
            MIN_DELAY + Math.random() * (MAX_DELAY - MIN_DELAY),
        );
    };

    const stop = (): void => {
        window.clearTimeout(timer);
        timer = undefined;
    };

    // A pane nobody is looking at is not tailing.
    new IntersectionObserver((entries) => {
        visible = entries[0]?.isIntersecting ?? false;

        if (visible && timer === undefined) {
            timer = window.setTimeout(tick, MIN_DELAY);
        } else if (!visible) {
            stop();
        }
    }).observe(pane);

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            stop();
        } else if (visible && timer === undefined) {
            timer = window.setTimeout(tick, MIN_DELAY);
        }
    });
};

/**
 * Restamp the server-rendered rows against the reader's own clock.
 *
 * The pane is rendered in the app's timezone and may be served from a cache,
 * so its timestamps and the ones this file appends would otherwise disagree
 * — a visible seam in the middle of the stream.
 */
const seed = (): void => {
    document
        .querySelectorAll<HTMLElement>('[data-live-tail-list] > li')
        .forEach((row, index) => {
            const now = clock(index * 3 + 1);

            set(row, 'time', now.time);
            set(row, 'ms', now.ms);
        });
};

const boot = (): void => {
    seed();

    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        // The server-rendered stream stands on its own; leave it still, and
        // take the pulsing dot down with it so nothing claims to be moving.
        document
            .querySelectorAll<HTMLElement>('[data-live-tail-pulse]')
            .forEach((dot) => dot.classList.remove('animate-pulse'));

        return;
    }

    const source = document.querySelector('[data-live-tail-pool]');

    if (!source?.textContent) {
        return;
    }

    let pool: Line[];

    try {
        pool = JSON.parse(source.textContent) as Line[];
    } catch {
        return;
    }

    document
        .querySelectorAll<HTMLElement>('[data-live-tail]')
        .forEach((pane) => start(pane, pool));
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
} else {
    boot();
}

export {};
