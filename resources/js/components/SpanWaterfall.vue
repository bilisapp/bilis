<script setup lang="ts">
import { ChevronsDownUp, ChevronsUpDown } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import SpanNamingToggle from '@/components/SpanNamingToggle.vue';
import SpanWaterfallRow from '@/components/SpanWaterfallRow.vue';
import {
    axisTicks,
    collapsibleSpanIds,
    serviceColours,
    spanAncestors,
    traceExtentMs,
    visibleSpans,
    waterfallGeometry,
} from '@/lib/traces';
import { cn } from '@/lib/utils';
import type { Span } from '@/types';

const props = defineProps<{
    /** Depth-first, already flattened by SpanTree, each row carrying its depth. */
    spans: Span[];
    selectedSpanId: string | null;
    /**
     * Narrow enough for a side panel: the Detail column is dropped and the
     * Span column halves. The timeline is the part worth keeping at any width,
     * so it is the part that survives.
     */
    compact?: boolean;
    /**
     * A span the reader must be able to see right now — from `?span=` in the
     * URL, or from stepping through search matches. Its ancestors are expanded
     * and its row scrolled to the centre. Distinct from `selectedSpanId`: a
     * click selects a row that is already on screen and must not scroll it.
     */
    revealSpanId?: string | null;
    /**
     * The result of a search over this trace: the ids that matched, or `null`
     * when nothing is being searched. Rows outside the set are dimmed rather
     * than removed, so no bar moves while the reader types, and every match's
     * ancestors are expanded so a hit inside a collapsed subtree is visible.
     */
    matches?: Set<string> | null;
}>();

const emit = defineEmits<{ (event: 'select', spanId: string): void }>();

const collapsed = ref<Set<string>>(new Set());
const tree = ref<HTMLElement | null>(null);

// A different trace is a different tree; keep no collapse state across them.
watch(
    () => props.spans,
    () => {
        collapsed.value = new Set();
    },
);

/**
 * The column template, shared by the header, the gridline layer and every row.
 * Defined once because three grids that must line up cannot be allowed to
 * disagree — a mismatch would put the axis labels out of step with the bars.
 */
const columns = computed(() =>
    props.compact
        ? 'grid-cols-[minmax(0,10.5rem)_1fr]'
        : 'grid-cols-[minmax(0,20rem)_8rem_1fr]',
);

const extentMs = computed(() => traceExtentMs(props.spans));
const ticks = computed(() => axisTicks(extentMs.value, props.compact ? 3 : 6));
const colours = computed(() => serviceColours(props.spans));
const rows = computed(() => visibleSpans(props.spans, collapsed.value));

/** Span ids present in this result, to tell a root from an orphan. */
const presentIds = computed(
    () => new Set(props.spans.map((span) => span.spanId)),
);

/** Bar geometry, computed against the full set so collapsing never moves a bar. */
const geometry = computed(() => waterfallGeometry(props.spans));

/** The services in this trace, in the order the palette assigned them. */
const legend = computed(() => [...colours.value.entries()]);

const anyExpanded = computed(() =>
    props.spans.some(
        (span) => span.childCount > 0 && !collapsed.value.has(span.spanId),
    ),
);

function toggle(spanId: string) {
    const next = new Set(collapsed.value);

    if (next.has(spanId)) {
        next.delete(spanId);
    } else {
        next.add(spanId);
    }

    collapsed.value = next;
}

function collapseAll() {
    collapsed.value = new Set(collapsibleSpanIds(props.spans));
}

function expandAll() {
    collapsed.value = new Set();
}

/** Open every collapsed ancestor of the given spans, in one state change. */
function expandAncestors(spanIds: Iterable<string>) {
    if (collapsed.value.size === 0) {
        return;
    }

    const next = new Set(collapsed.value);

    for (const spanId of spanIds) {
        for (const ancestor of spanAncestors(props.spans, spanId)) {
            next.delete(ancestor);
        }
    }

    if (next.size !== collapsed.value.size) {
        collapsed.value = next;
    }
}

function prefersReducedMotion(): boolean {
    return (
        typeof window !== 'undefined' &&
        typeof window.matchMedia === 'function' &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches
    );
}

/**
 * Bring a row to the centre of the scroll area.
 *
 * Centre rather than nearest, because a revealed row is one the reader has not
 * seen yet — from a link, or a match several screens away — and its context
 * above and below is what makes it legible. Smooth only when the reader has
 * not asked otherwise.
 */
async function scrollTo(spanId: string) {
    await nextTick();

    const row = tree.value?.querySelector<HTMLElement>(
        `[data-span-id="${CSS.escape(spanId)}"]`,
    );

    row?.scrollIntoView({
        block: 'center',
        behavior: prefersReducedMotion() ? 'auto' : 'smooth',
    });
}

watch(
    () => props.revealSpanId,
    (spanId) => {
        if (!spanId || !presentIds.value.has(spanId)) {
            return;
        }

        expandAncestors([spanId]);
        void scrollTo(spanId);
    },
    { immediate: true },
);

watch(
    () => props.matches,
    (matches) => {
        if (matches && matches.size > 0) {
            expandAncestors(matches);
        }
    },
    { immediate: true },
);
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col" data-test="span-waterfall">
        <!--
          Legend and bulk controls. The legend is not decoration: bars are
          coloured by service, so it is the key that makes the colours mean
          something rather than just look like something.
        -->
        <div
            class="flex flex-wrap items-center gap-x-4 gap-y-2 border-b px-3 py-2"
        >
            <ul class="flex flex-wrap items-center gap-x-3 gap-y-1">
                <li
                    v-for="[service, colour] in legend"
                    :key="service"
                    class="flex items-center gap-1.5 text-xs text-muted-foreground"
                >
                    <span
                        :class="cn('size-2 shrink-0 rounded-full', colour)"
                        aria-hidden="true"
                    />
                    {{ service }}
                </li>
            </ul>

            <div class="ml-auto flex items-center gap-1.5">
                <SpanNamingToggle :compact="compact" />

                <button
                    type="button"
                    class="inline-flex size-6 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                    :title="anyExpanded ? 'Collapse all' : 'Already collapsed'"
                    aria-label="Collapse all"
                    data-test="waterfall-collapse-all"
                    @click="collapseAll"
                >
                    <ChevronsDownUp class="size-3.5" />
                </button>
                <button
                    type="button"
                    class="inline-flex size-6 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                    title="Expand all"
                    aria-label="Expand all"
                    data-test="waterfall-expand-all"
                    @click="expandAll"
                >
                    <ChevronsUpDown class="size-3.5" />
                </button>
            </div>
        </div>

        <div class="min-h-0 flex-1 overflow-auto">
            <!--
              min-h-full so the gridlines run the whole height of the scroll
              area rather than stopping at the last row: a short trace would
              otherwise leave a dead rectangle under the chart that reads as
              something failing to render.
            -->
            <div
                :class="
                    cn('flex min-h-full flex-col', compact ? '' : 'min-w-3xl')
                "
            >
                <!--
                  The column header doubles as the time axis. Span and Detail
                  are fixed-width so nothing shifts between rows (DESIGN.md's
                  Fixed Column Rule); the timeline takes whatever is left.
                -->
                <div
                    :class="
                        cn(
                            'sticky top-0 z-10 grid items-end gap-3 border-b bg-card px-3 py-1.5 text-xs text-muted-foreground',
                            columns,
                        )
                    "
                >
                    <span class="font-medium">Span</span>
                    <span v-if="!compact" class="font-medium">Detail</span>

                    <div class="relative h-4">
                        <!--
                          A tick centred on 100% hangs half its label past the
                          right edge and gives the whole panel a horizontal
                          scrollbar. The ends anchor to their own edge instead.
                        -->
                        <span
                            v-for="tick in ticks"
                            :key="tick.percent"
                            :class="
                                cn(
                                    'absolute bottom-0 whitespace-nowrap tabular-nums',
                                    tick.percent > 92
                                        ? '-translate-x-full'
                                        : tick.percent < 4
                                          ? ''
                                          : '-translate-x-1/2',
                                )
                            "
                            :style="{ left: `${tick.percent}%` }"
                        >
                            {{ tick.label }}
                        </span>
                    </div>
                </div>

                <div class="relative flex-1">
                    <!--
                      Gridlines run behind every row rather than being drawn per
                      row, so they stay continuous down the whole waterfall and
                      a bar can be read against them without a ruler.
                    -->
                    <div
                        :class="
                            cn(
                                'pointer-events-none absolute inset-0 grid gap-3 px-3',
                                columns,
                            )
                        "
                        aria-hidden="true"
                    >
                        <div />
                        <div v-if="!compact" />
                        <div class="relative">
                            <span
                                v-for="tick in ticks"
                                :key="tick.percent"
                                class="absolute inset-y-0 w-px bg-border/60"
                                :style="{ left: `${tick.percent}%` }"
                            />
                        </div>
                    </div>

                    <!--
                      The rows are a tree to assistive tech — depth, expanded
                      and selected are all announced — while staying a flat list
                      in the DOM, because a 2,000-node component tree is what
                      the server-side flatten exists to avoid.
                    -->
                    <div
                        ref="tree"
                        role="tree"
                        aria-label="Spans"
                        data-test="span-tree"
                    >
                        <SpanWaterfallRow
                            v-for="span in rows"
                            :key="span.spanId"
                            :span="span"
                            :geometry="
                                geometry.get(span.spanId) ?? {
                                    offsetPercent: 0,
                                    widthPercent: 0.4,
                                }
                            "
                            :colour="
                                colours.get(span.serviceName || 'unknown') ?? ''
                            "
                            :compact="compact"
                            :collapsed="collapsed.has(span.spanId)"
                            :orphan="
                                span.parentSpanId !== '' &&
                                !presentIds.has(span.parentSpanId)
                            "
                            :selected="selectedSpanId === span.spanId"
                            :dimmed="
                                matches !== null &&
                                matches !== undefined &&
                                !matches.has(span.spanId)
                            "
                            @select="emit('select', span.spanId)"
                            @toggle="toggle(span.spanId)"
                        />
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
