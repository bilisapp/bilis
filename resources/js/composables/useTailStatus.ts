import { computed, ref } from 'vue';
import type { ComputedRef, Ref } from 'vue';
import type { LogEntry, SeverityLevel } from '@/types';
import { severityLevelFor } from '@/lib/logs';

/**
 * Live-tail state shared between the log viewer and the app chrome.
 *
 * The sidebar mark and the browser tab both report on tailing, and neither is
 * a descendant of the logs page — so the state lives at module scope rather
 * than being threaded through the layout as props. There is only ever one log
 * stream open at a time, which is what makes a single shared instance correct
 * here rather than a convenience.
 */
const tailing = ref(false);

/** Lines that arrived while the tab was in the background. */
const unseen = ref(0);

/** The loudest severity among those unseen lines. */
const unseenPeak = ref<SeverityLevel | null>(null);

/**
 * Severity order, so "loudest" is a comparison rather than a lookup table.
 */
const SEVERITY_RANK: Record<SeverityLevel, number> = {
    trace: 0,
    debug: 1,
    info: 2,
    warn: 3,
    error: 4,
    fatal: 5,
};

export type UseTailStatusReturn = {
    /** True while the viewer is live tailing. */
    tailing: Ref<boolean>;
    unseen: Ref<number>;
    unseenPeak: Ref<SeverityLevel | null>;
    /** True when at least one unseen line was an error or worse. */
    unseenIsLoud: ComputedRef<boolean>;
    /** Record lines that arrived while the reader was not looking. */
    noteUnseen: (entries: LogEntry[]) => void;
    /** The reader is back; the count starts again. */
    clearUnseen: () => void;
    stopTailing: () => void;
};

export function useTailStatus(): UseTailStatusReturn {
    const unseenIsLoud = computed(
        () =>
            unseenPeak.value !== null &&
            SEVERITY_RANK[unseenPeak.value] >= SEVERITY_RANK.error,
    );

    const noteUnseen = (entries: LogEntry[]) => {
        if (entries.length === 0) {
            return;
        }

        unseen.value += entries.length;

        for (const entry of entries) {
            const level = severityLevelFor(entry);

            if (
                unseenPeak.value === null ||
                SEVERITY_RANK[level] > SEVERITY_RANK[unseenPeak.value]
            ) {
                unseenPeak.value = level;
            }
        }
    };

    const clearUnseen = () => {
        unseen.value = 0;
        unseenPeak.value = null;
    };

    const stopTailing = () => {
        tailing.value = false;
        clearUnseen();
    };

    return {
        tailing,
        unseen,
        unseenPeak,
        unseenIsLoud,
        noteUnseen,
        clearUnseen,
        stopTailing,
    };
}
