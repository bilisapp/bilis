<script setup lang="ts">
import { ChevronDown, ChevronUp, Search, X } from '@lucide/vue';
import { refDebounced } from '@vueuse/core';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { matchingSpanIds, spanHaystack } from '@/lib/traces';
import { cn } from '@/lib/utils';
import type { Span } from '@/types';

const props = defineProps<{
    /** The full trace, depth-first: match order is document order. */
    spans: Span[];
    /** The row currently selected, so stepping continues from where the eye is. */
    selectedSpanId: string | null;
    /**
     * Take the `/` shortcut. On by default; a styleguide showing several of
     * these at once turns it off so one key does not focus three fields.
     */
    shortcut?: boolean;
}>();

const emit = defineEmits<{
    /**
     * The current match set, or `null` when nothing is being searched. The
     * waterfall dims everything outside it and expands to show what is inside.
     */
    (event: 'matches', matches: Set<string> | null): void;
    /** The reader stepped onto a match: select it and bring it into view. */
    (event: 'step', spanId: string): void;
}>();

const input = ref<HTMLInputElement | null>(null);
const query = ref('');

/**
 * Debounced so a 2,000-span trace is not re-scanned on every keystroke; short
 * enough that the dimming still feels attached to the typing.
 */
const debouncedQuery = refDebounced(query, 120);

/** Lower-cased once per span, not once per keystroke. */
const haystacks = computed(
    () => new Map(props.spans.map((span) => [span.spanId, spanHaystack(span)])),
);

const matches = computed(() =>
    matchingSpanIds(haystacks.value, debouncedQuery.value),
);

/** Matches in the order the rows are drawn, for stepping. */
const ordered = computed(() =>
    matches.value === null
        ? []
        : props.spans
              .filter((span) => matches.value?.has(span.spanId))
              .map((span) => span.spanId),
);

const active = computed(() => matches.value !== null);

/** 1-based position of the selected span among the matches, or 0. */
const position = computed(() =>
    props.selectedSpanId === null
        ? 0
        : ordered.value.indexOf(props.selectedSpanId) + 1,
);

const summary = computed(() => {
    if (!active.value) {
        return '';
    }

    const total = props.spans.length;
    const count = ordered.value.length;

    return `${count.toLocaleString()} of ${total.toLocaleString()} ${total === 1 ? 'span' : 'spans'} match`;
});

watch(
    matches,
    (value) => {
        emit('matches', value);
    },
    { immediate: true },
);

/**
 * Move to the next or previous match relative to the selected row.
 *
 * Relative to the selection rather than to an internal cursor, so a reader who
 * clicks a row between two presses of Enter continues from the row they
 * clicked, not from the one the field last visited.
 */
function step(direction: 1 | -1) {
    const ids = ordered.value;

    if (ids.length === 0) {
        return;
    }

    const current = props.selectedSpanId
        ? props.spans.findIndex((span) => span.spanId === props.selectedSpanId)
        : -1;

    let target: string | undefined;

    if (direction === 1) {
        target = ids.find(
            (id) =>
                props.spans.findIndex((span) => span.spanId === id) > current,
        );
        target ??= ids[0];
    } else {
        target = [...ids]
            .reverse()
            .find(
                (id) =>
                    props.spans.findIndex((span) => span.spanId === id) <
                    current,
            );
        target ??= ids[ids.length - 1];
    }

    emit('step', target);
}

function clear() {
    query.value = '';
}

function onKeydown(event: KeyboardEvent) {
    if (event.key === 'Escape') {
        if (query.value === '') {
            input.value?.blur();
        }

        clear();
        event.preventDefault();

        return;
    }

    if (event.key === 'Enter' || event.key === 'ArrowDown') {
        step(event.shiftKey && event.key === 'Enter' ? -1 : 1);
        event.preventDefault();

        return;
    }

    if (event.key === 'ArrowUp') {
        step(-1);
        event.preventDefault();
    }
}

/**
 * `/` focuses the field from anywhere on the page that is not already a place
 * to type — the same convention the log viewer's search and half the web use.
 */
function onDocumentKeydown(event: KeyboardEvent) {
    if (
        event.key !== '/' ||
        event.metaKey ||
        event.ctrlKey ||
        event.altKey ||
        event.defaultPrevented
    ) {
        return;
    }

    const target = event.target as HTMLElement | null;

    if (
        target &&
        (target.isContentEditable ||
            ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName))
    ) {
        return;
    }

    event.preventDefault();
    input.value?.focus();
    input.value?.select();
}

onMounted(() => {
    if (props.shortcut !== false) {
        document.addEventListener('keydown', onDocumentKeydown);
    }
});

onBeforeUnmount(() => {
    document.removeEventListener('keydown', onDocumentKeydown);
});

defineExpose({ focus: () => input.value?.focus(), clear });
</script>

<template>
    <div
        class="flex flex-wrap items-center gap-x-3 gap-y-1.5"
        data-test="span-search"
    >
        <label class="relative flex min-w-0 flex-1 items-center sm:max-w-md">
            <span class="sr-only">Search spans</span>
            <Search
                class="pointer-events-none absolute left-2.5 size-3.5 text-muted-foreground"
                aria-hidden="true"
            />
            <input
                ref="input"
                v-model="query"
                type="search"
                enterkeyhint="search"
                autocomplete="off"
                spellcheck="false"
                placeholder="Search spans — name, service, attribute…"
                :class="
                    cn(
                        'h-8 w-full min-w-0 rounded-md border border-input bg-transparent pr-14 pl-8 text-xs shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground dark:bg-input/30',
                        'focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50',
                        '[&::-webkit-search-cancel-button]:hidden',
                    )
                "
                data-test="span-search-input"
                @keydown="onKeydown"
            />
            <span
                class="pointer-events-none absolute right-2 flex items-center gap-1"
            >
                <button
                    v-if="query !== ''"
                    type="button"
                    class="pointer-events-auto inline-flex size-5 items-center justify-center rounded text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                    aria-label="Clear search"
                    data-test="span-search-clear"
                    @click="clear"
                >
                    <X class="size-3.5" />
                </button>
                <kbd
                    v-else
                    class="rounded border px-1 font-mono text-[10px] text-muted-foreground"
                    aria-hidden="true"
                >
                    /
                </kbd>
            </span>
        </label>

        <!--
          The count is the search's only verdict, so it is a live region: a
          reader who cannot see rows dim still hears "0 of 284 spans match".
        -->
        <p
            class="flex items-center gap-1 text-xs text-muted-foreground tabular-nums"
            aria-live="polite"
            data-test="span-search-summary"
        >
            <template v-if="active">
                <span>{{ summary }}</span>
                <span v-if="position > 0" class="text-foreground">
                    · {{ position }}/{{ ordered.length }}
                </span>

                <span class="ml-1 inline-flex items-center">
                    <button
                        type="button"
                        class="inline-flex size-5 items-center justify-center rounded text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none disabled:pointer-events-none disabled:opacity-40"
                        aria-label="Previous match"
                        title="Previous match (Shift+Enter)"
                        :disabled="ordered.length === 0"
                        data-test="span-search-prev"
                        @click="step(-1)"
                    >
                        <ChevronUp class="size-3.5" />
                    </button>
                    <button
                        type="button"
                        class="inline-flex size-5 items-center justify-center rounded text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none disabled:pointer-events-none disabled:opacity-40"
                        aria-label="Next match"
                        title="Next match (Enter)"
                        :disabled="ordered.length === 0"
                        data-test="span-search-next"
                        @click="step(1)"
                    >
                        <ChevronDown class="size-3.5" />
                    </button>
                </span>
            </template>
        </p>
    </div>
</template>
