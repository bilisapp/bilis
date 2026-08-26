import { onBeforeUnmount, onMounted, ref } from 'vue';
import type { Ref } from 'vue';

export type LogKeyboardOptions = {
    /** How many rows are currently in the stream. */
    count: () => number;
    /** Expand or collapse the row the cursor is on. */
    toggle: (index: number) => void;
    /** Collapse whatever is expanded. Returns true if anything closed. */
    collapse: () => boolean;
    /** Put the caret in the search field. */
    focusSearch: () => void;
    /** Open the shortcuts sheet. */
    openShortcuts: () => void;
};

export type UseLogKeyboardReturn = {
    /** The row the keyboard is on, or null before the first keypress. */
    cursor: Ref<number | null>;
    /** Drop the cursor, e.g. when the result set changes underneath it. */
    reset: () => void;
};

/**
 * Whether a keystroke belongs to something the reader is typing into.
 *
 * Single-letter shortcuts and text fields cannot both own the keyboard, and
 * the field wins every time: someone searching for "jk" must get "jk".
 */
function isTypingTarget(target: EventTarget | null): boolean {
    if (!(target instanceof HTMLElement)) {
        return false;
    }

    if (target.isContentEditable) {
        return true;
    }

    return ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName);
}

/**
 * `less`-style keys for the log stream.
 *
 * The people who self-host a log viewer already have muscle memory for `j`,
 * `k`, `/` and `G` — this hands them the stream on the keyboard rather than
 * making them reach for a mouse in the middle of an incident. Every shortcut
 * has a pointer equivalent; none of them is the only way to do anything.
 */
export function useLogKeyboard(
    options: LogKeyboardOptions,
): UseLogKeyboardReturn {
    const cursor = ref<number | null>(null);

    const reset = () => {
        cursor.value = null;
    };

    const move = (delta: number) => {
        const total = options.count();

        if (total === 0) {
            return;
        }

        if (cursor.value === null) {
            cursor.value = delta > 0 ? 0 : total - 1;

            return;
        }

        cursor.value = Math.min(total - 1, Math.max(0, cursor.value + delta));
    };

    const onKeydown = (event: KeyboardEvent) => {
        if (event.metaKey || event.ctrlKey || event.altKey) {
            return;
        }

        // Escape is the one key that must work from inside the search field,
        // because that is where a reader most often wants out.
        if (event.key === 'Escape') {
            if (isTypingTarget(event.target)) {
                (event.target as HTMLElement).blur();

                return;
            }

            if (options.collapse()) {
                event.preventDefault();

                return;
            }

            reset();

            return;
        }

        if (isTypingTarget(event.target)) {
            return;
        }

        const total = options.count();

        switch (event.key) {
            case 'j':
            case 'ArrowDown':
                event.preventDefault();
                move(1);
                break;
            case 'k':
            case 'ArrowUp':
                event.preventDefault();
                move(-1);
                break;
            case 'g':
                event.preventDefault();
                cursor.value = total > 0 ? 0 : null;
                break;
            case 'G':
                event.preventDefault();
                cursor.value = total > 0 ? total - 1 : null;
                break;
            case 'o':
            case 'Enter':
                if (cursor.value !== null) {
                    event.preventDefault();
                    options.toggle(cursor.value);
                }

                break;
            case '/':
                event.preventDefault();
                options.focusSearch();
                break;
            case '?':
                event.preventDefault();
                options.openShortcuts();
                break;
            default:
                break;
        }
    };

    onMounted(() => window.addEventListener('keydown', onKeydown));
    onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));

    return { cursor, reset };
}
