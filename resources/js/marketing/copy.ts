/**
 * Copy-to-clipboard for the snippets on the marketing pages.
 *
 * A curl command nobody can copy is a picture of a curl command. The button
 * is rendered hidden by Blade and only revealed here, so a reader without
 * JavaScript never sees a control that does nothing.
 */
const RESET_AFTER = 1800;

/**
 * The async API first, a hidden textarea second.
 *
 * `navigator.clipboard` is undefined on any origin the browser does not
 * consider secure — which includes the `.test` domain this app is developed
 * on — so the old command is the fallback rather than the exception.
 */
const copy = async (text: string): Promise<boolean> => {
    try {
        await navigator.clipboard.writeText(text);

        return true;
    } catch {
        // fall through
    }

    const field = document.createElement('textarea');
    field.value = text;
    field.setAttribute('readonly', '');
    field.style.cssText = 'position:fixed;top:0;left:0;opacity:0';
    document.body.append(field);
    field.select();

    try {
        return document.execCommand('copy');
    } catch {
        return false;
    } finally {
        field.remove();
    }
};

const wire = (button: HTMLElement): void => {
    const target = document.getElementById(button.dataset.copy ?? '');

    if (!target) {
        return;
    }

    const idle = button.querySelector<HTMLElement>('[data-copy-idle]');
    const done = button.querySelector<HTMLElement>('[data-copy-done]');
    let timer: number | undefined;

    button.hidden = false;

    button.addEventListener('click', async () => {
        const text = (
            target.dataset.copyText ??
            target.textContent ??
            ''
        ).trim();

        if (!(await copy(text))) {
            return;
        }

        idle?.setAttribute('hidden', '');
        done?.removeAttribute('hidden');

        window.clearTimeout(timer);
        timer = window.setTimeout(() => {
            done?.setAttribute('hidden', '');
            idle?.removeAttribute('hidden');
        }, RESET_AFTER);
    });
};

const boot = (): void => {
    document.querySelectorAll<HTMLElement>('[data-copy]').forEach(wire);
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
} else {
    boot();
}

export {};
