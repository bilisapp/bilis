import { onBeforeUnmount, onMounted, ref } from 'vue';
import type { Ref } from 'vue';

export type ListCursorOptions = {
    /** How many rows the list currently holds. */
    count: () => number;
    /** Act on the row the cursor is on — expand it, open it. Enter and `o`. */
    open?: (index: number) => void;
    /**
     * Close whatever is open. Returns true if anything closed; when nothing
     * did, Escape drops the cursor instead. Escape alone when absent.
     */
    collapse?: () => boolean;
    /** Put the caret in the list's search or filter field. `/`. */
    focusSearch?: () => void;
    /** Open the shortcuts sheet. `?`. */
    openShortcuts?: () => void;
    /** Put the row the cursor is on onto the clipboard. `y`. */
    copy?: (index: number) => void;
};

export type UseListCursorReturn = {
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
export function isTypingTarget(target: EventTarget | null): boolean {
    if (!(target instanceof HTMLElement)) {
        return false;
    }

    if (target.isContentEditable) {
        return true;
    }

    return ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName);
}

/**
 * `less`-style keys over a list of rows.
 *
 * The people who self-host an observability tool already have muscle memory
 * for `j`, `k`, `/` and `G` — this hands them the list on the keyboard rather
 * than making them reach for a mouse in the middle of an incident. One model
 * for every list: the log stream and the trace list bind the same keys to the
 * same motions, and differ only in what "open" means. Every shortcut has a
 * pointer equivalent; none of them is the only way to do anything. Handlers
 * that are not given are simply not bound — the key falls through.
 */
export function useListCursor(options: ListCursorOptions): UseListCursorReturn {
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

        // Escape is the one key that must work from inside a text field,
        // because that is where a reader most often wants out.
        if (event.key === 'Escape') {
            if (isTypingTarget(event.target)) {
                (event.target as HTMLElement).blur();

                return;
            }

            if (options.collapse?.()) {
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
                if (cursor.value !== null && options.open) {
                    event.preventDefault();
                    options.open(cursor.value);
                }

                break;
            // `y` is vi's yank, which is the muscle memory this reaches for.
            // Only copying is bound: a keystroke must not be able to spend an
            // agent run, so the fix action stays behind a pointer and a dialog.
            case 'y':
                if (cursor.value !== null && options.copy) {
                    event.preventDefault();
                    options.copy(cursor.value);
                }

                break;
            case '/':
                if (options.focusSearch) {
                    event.preventDefault();
                    options.focusSearch();
                }

                break;
            case '?':
                if (options.openShortcuts) {
                    event.preventDefault();
                    options.openShortcuts();
                }

                break;
            default:
                break;
        }
    };

    onMounted(() => window.addEventListener('keydown', onKeydown));
    onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));

    return { cursor, reset };
}
