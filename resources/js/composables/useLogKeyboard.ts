import { useListCursor } from '@/composables/useListCursor';
import type { UseListCursorReturn } from '@/composables/useListCursor';

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
    /** Put the row the cursor is on onto the clipboard. */
    copy: (index: number) => void;
};

export type UseLogKeyboardReturn = UseListCursorReturn;

/**
 * `less`-style keys for the log stream.
 *
 * The log viewer's binding of {@link useListCursor}: every handler is wired,
 * and "open" means expanding the row in place. The trace list binds the same
 * composable with fewer handlers, so the two surfaces share one keyboard
 * model and a shortcut learned on either works on both.
 */
export function useLogKeyboard(
    options: LogKeyboardOptions,
): UseLogKeyboardReturn {
    return useListCursor({
        count: options.count,
        open: options.toggle,
        collapse: options.collapse,
        focusSearch: options.focusSearch,
        openShortcuts: options.openShortcuts,
        copy: options.copy,
    });
}
