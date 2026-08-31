<script setup lang="ts">
import { ChevronDown, ChevronRight } from '@lucide/vue';
import { computed } from 'vue';
import { useSpanNaming } from '@/composables/useSpanNaming';
import {
    formatDuration,
    parentLink,
    SPAN_STATUS_BAR_CLASS,
    spanDetail,
    spanLabel,
    spanStatus,
} from '@/lib/traces';
import { cn } from '@/lib/utils';
import type { Span } from '@/types';

const props = defineProps<{
    span: Span;
    /** Percentages of the trace's extent, computed once for every row. */
    geometry: { offsetPercent: number; widthPercent: number };
    /** The palette utility for this span's service. */
    colour: string;
    /** Side-panel width: no Detail column, shallower indent step. */
    compact?: boolean;
    collapsed?: boolean;
    selected?: boolean;
    /**
     * The span's parent is not in this result set, so it is drawn at the top
     * level. Worth saying out loud — otherwise the tree looks wrong rather than
     * incomplete.
     */
    orphan?: boolean;
    /**
     * Outside the current search's matches. Dimmed, never removed: the row
     * keeps its place so the bars around it do not move while the reader types.
     */
    dimmed?: boolean;
}>();

const emit = defineEmits<{
    (event: 'select'): void;
    (event: 'toggle'): void;
}>();

const { naming } = useSpanNaming();

const status = computed(() => spanStatus(props.span));

/**
 * This span has no parent in the tree, but names one through a link.
 *
 * The distinction from `orphan` is the whole point: an orphan's parent is
 * missing, this one's parent is elsewhere and was told to us.
 */
const externalParent = computed(
    () =>
        props.span.parentSpanId === '' &&
        parentLink(props.span) !== undefined &&
        parentLink(props.span)?.traceId !== props.span.traceId,
);
const label = computed(() => spanLabel(props.span, naming.value));
const detail = computed(() => spanDetail(props.span, naming.value));

/**
 * The exporter's own name, whenever the row is not already showing it.
 *
 * A derived label is an interpretation, and the tooltip is where the reader
 * checks it without leaving the row or flipping the whole view to Raw.
 */
const rawName = computed(() =>
    label.value === props.span.name ? undefined : props.span.name,
);

/**
 * Indentation is capped so a deep trace still leaves room for the bar.
 *
 * Past about a dozen levels the extra offset stops carrying information and
 * starts eating the only column that does.
 */
const indent = computed(
    () =>
        Math.min(props.span.depth, props.compact ? 6 : 12) *
        (props.compact ? 8 : 12),
);

/**
 * A failed span takes the severity colour, overriding its service's.
 *
 * The two hues would otherwise compete for the same mark, and "this broke" has
 * to win over "this belongs to payments" every time.
 */
const barClass = computed(() =>
    status.value === 'Error' ? SPAN_STATUS_BAR_CLASS.Error : props.colour,
);

/**
 * A duration sitting inside its own bar is unreadable below a certain width, so
 * a narrow bar puts the label outside it instead.
 */
const labelInside = computed(
    () => props.geometry.widthPercent >= (props.compact ? 34 : 18),
);

/**
 * Which side of a too-narrow bar its duration sits on.
 *
 * After the bar by default, but a bar that ends near the right edge would push
 * its own label off the panel and give the whole waterfall a horizontal
 * scrollbar. Those flip to the left of the bar instead, where there is always
 * room, rather than being clipped or dragged away from what they describe.
 */
const labelAfterBar = computed(
    () =>
        props.geometry.offsetPercent + props.geometry.widthPercent <
        (props.compact ? 60 : 82),
);
</script>

<template>
    <div
        :class="
            cn(
                'group relative grid items-center gap-3 border-b border-border/50 px-3 text-xs transition-[background-color,opacity]',
                compact
                    ? 'grid-cols-[minmax(0,10.5rem)_1fr]'
                    : 'grid-cols-[minmax(0,20rem)_8rem_1fr]',
                selected ? 'bg-accent' : 'hover:bg-accent/40',
                dimmed && 'opacity-35',
            )
        "
        role="treeitem"
        :aria-level="span.depth + 1"
        :aria-expanded="span.childCount > 0 ? !collapsed : undefined"
        :aria-selected="selected"
        :data-span-id="span.spanId"
        :data-test="`span-row-${span.spanId}`"
        :data-dimmed="dimmed ? 'true' : undefined"
    >
        <!--
          The selected row is marked on its left edge rather than by a ring: a
          ring around a full-width row in a dense table reads as a box drawn
          over the data, and the edge stays legible without colour.
        -->
        <span
            v-if="selected"
            class="absolute inset-y-0 left-0 w-0.5 bg-foreground"
            aria-hidden="true"
        />

        <div
            class="flex min-w-0 items-center gap-1"
            :style="{ paddingLeft: `${indent}px` }"
        >
            <button
                v-if="span.childCount > 0"
                type="button"
                class="inline-flex size-4 shrink-0 items-center justify-center rounded text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                :aria-label="collapsed ? 'Expand span' : 'Collapse span'"
                :aria-expanded="!collapsed"
                :data-test="`span-toggle-${span.spanId}`"
                @click.stop="emit('toggle')"
            >
                <component
                    :is="collapsed ? ChevronRight : ChevronDown"
                    class="size-3"
                />
            </button>
            <span v-else class="size-4 shrink-0" aria-hidden="true" />

            <span
                :class="cn('size-1.5 shrink-0 rounded-full', barClass)"
                aria-hidden="true"
            />

            <button
                type="button"
                class="min-w-0 flex-1 truncate text-left font-medium focus-visible:underline focus-visible:outline-none"
                :title="rawName"
                @click="emit('select')"
            >
                {{ label }}
                <span v-if="status === 'Error'" class="sr-only">, failed</span>
            </button>

            <!--
              How many children are hidden under a collapsed row. Shown only when
              collapsed: while expanded the children are right there, and the
              badge would be repeating what the reader can already see.
            -->
            <span
                v-if="collapsed && span.childCount > 0"
                class="shrink-0 rounded-full bg-muted px-1.5 text-muted-foreground tabular-nums"
                :title="`${span.childCount} child span(s) hidden`"
            >
                {{ span.childCount }}
            </span>

            <span
                v-if="orphan"
                class="shrink-0 rounded border px-1 text-muted-foreground"
                title="This span's parent is not in this result — it may have aged out, still be in flight, or fall past the row cap."
            >
                orphan
            </span>

            <!--
              A root that names its parent through a link. Not an orphan — this
              span knows exactly where it came from, but the parent is in another
              trace, which a tree cannot draw. Marked so the top of a waterfall
              is never mistaken for the top of the work.
            -->
            <span
                v-else-if="externalParent"
                class="shrink-0 rounded border px-1 text-muted-foreground"
                data-test="span-external-parent"
                title="This span's parent is in another trace, named by a span link. Open the span for its target."
            >
                linked parent
            </span>
        </div>

        <span v-if="!compact" class="truncate text-muted-foreground">{{
            detail
        }}</span>

        <!-- The bar is the whole point of the row: it gets the space. -->
        <div class="relative h-6 min-w-0 overflow-hidden">
            <div
                :class="
                    cn(
                        'absolute top-1/2 flex h-3.5 -translate-y-1/2 items-center overflow-hidden rounded-sm',
                        barClass,
                        status === 'Unset' && 'opacity-80',
                    )
                "
                :style="{
                    left: `${geometry.offsetPercent}%`,
                    width: `${geometry.widthPercent}%`,
                }"
            >
                <span
                    v-if="labelInside"
                    class="truncate px-1.5 font-mono text-background tabular-nums mix-blend-luminosity"
                >
                    {{ formatDuration(span.durationMs) }}
                </span>
            </div>

            <span
                v-if="!labelInside"
                :class="
                    cn(
                        'absolute top-1/2 -translate-y-1/2 font-mono whitespace-nowrap text-muted-foreground tabular-nums',
                        labelAfterBar ? 'pl-1.5' : 'pr-1.5 text-right',
                    )
                "
                :style="
                    labelAfterBar
                        ? {
                              left: `${geometry.offsetPercent + geometry.widthPercent}%`,
                          }
                        : { right: `${100 - geometry.offsetPercent}%` }
                "
            >
                {{ formatDuration(span.durationMs) }}
            </span>
        </div>
    </div>
</template>
