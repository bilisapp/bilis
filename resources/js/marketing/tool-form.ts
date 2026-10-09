/**
 * Live results for the free tools under /tools.
 *
 * Every tool is a GET form the server answers on its own, so without
 * JavaScript the button submits and the page reloads with the result. Here
 * the same request is made as the visitor types, and only the result region
 * of the answer is swapped in — the server stays the one place the
 * arithmetic lives. The address bar follows, so a result is still a link.
 */
const DEBOUNCE = 250;

const swap = async (
    form: HTMLFormElement,
    result: HTMLElement,
    submitter: HTMLElement | null,
    signal: AbortSignal,
): Promise<void> => {
    const data = new FormData(form, submitter);
    const query = new URLSearchParams();

    data.forEach((value, key) => {
        if (typeof value === 'string') {
            query.append(key, value);
        }
    });

    const url = `${form.action}?${query.toString()}`;
    const response = await fetch(url, {
        headers: { Accept: 'text/html' },
        signal,
    });

    if (!response.ok) {
        return;
    }

    const page = new DOMParser().parseFromString(
        await response.text(),
        'text/html',
    );
    const fresh = page.querySelector<HTMLElement>('[data-tool-result]');

    if (!fresh) {
        return;
    }

    result.innerHTML = fresh.innerHTML;
    window.history.replaceState(null, '', url);
    document.dispatchEvent(
        new CustomEvent('marketing:swapped', { detail: result }),
    );
};

const wire = (form: HTMLFormElement): void => {
    const result = document.querySelector<HTMLElement>('[data-tool-result]');

    if (!result) {
        return;
    }

    let timer: number | undefined;
    let inflight: AbortController | undefined;

    const run = (submitter: HTMLElement | null): void => {
        inflight?.abort();
        inflight = new AbortController();
        result.setAttribute('aria-busy', 'true');

        swap(form, result, submitter, inflight.signal)
            .catch(() => {
                // A dropped or aborted request leaves the last result standing.
            })
            .finally(() => result.removeAttribute('aria-busy'));
    };

    // The plain submit button is only for readers without JavaScript.
    form.querySelectorAll<HTMLElement>('[data-tool-submit]').forEach(
        (button) => {
            button.hidden = true;
        },
    );

    form.addEventListener('input', () => {
        window.clearTimeout(timer);
        timer = window.setTimeout(() => run(null), DEBOUNCE);
    });

    form.addEventListener('change', (event) => {
        if (event.target instanceof HTMLSelectElement) {
            window.clearTimeout(timer);
            run(null);
        }
    });

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        window.clearTimeout(timer);
        run(event.submitter);
    });
};

const boot = (): void => {
    document
        .querySelectorAll<HTMLFormElement>('form[data-tool-form]')
        .forEach(wire);
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
} else {
    boot();
}

export {};
