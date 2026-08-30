import type { Ref } from 'vue';
import { onMounted, ref } from 'vue';
import type { SpanNaming } from '@/lib/traces';

export type { SpanNaming };

export type SpanNamingOption = {
    value: SpanNaming;
    label: string;
    hint: string;
};

export const SPAN_NAMING_OPTIONS: SpanNamingOption[] = [
    {
        value: 'smart',
        label: 'Smart',
        hint: 'Label each span from its attributes — the route, the statement, the command, the model.',
    },
    {
        value: 'raw',
        label: 'Raw',
        hint: 'Show the span name and kind exactly as the exporter sent them.',
    },
];

const STORAGE_KEY = 'span-naming';

/**
 * Module-level so every waterfall and detail panel on the page agrees.
 *
 * The toggle describes how the reader wants spans named, not a piece of one
 * component's state: flipping it in the waterfall must move the detail panel
 * beside it in the same frame, or the panel is captioning a different row than
 * the one the eye is on.
 */
const naming = ref<SpanNaming>('smart');

export function useSpanNaming(): {
    naming: Ref<SpanNaming>;
    setNaming: (value: SpanNaming) => void;
} {
    onMounted(() => {
        const stored = localStorage.getItem(STORAGE_KEY);

        if (stored === 'smart' || stored === 'raw') {
            naming.value = stored;
        }
    });

    function setNaming(value: SpanNaming): void {
        naming.value = value;

        localStorage.setItem(STORAGE_KEY, value);
    }

    return { naming, setNaming };
}
