<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Check, Copy, ExternalLink, ScrollText } from '@lucide/vue';
import { useClipboard } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import SpanAttributes from '@/components/SpanAttributes.vue';
import TraceFact from '@/components/TraceFact.vue';
import { Button } from '@/components/ui/button';
import { useSpanNaming } from '@/composables/useSpanNaming';
import { formatTimestamp, formatUtcTimestamp } from '@/lib/logs';
import {
    durationClass,
    formatDuration,
    spanDetail,
    spanLabel,
    SPAN_KIND_LABEL,
    SPAN_STATUS_BAR_CLASS,
    SPAN_STATUS_TEXT_CLASS,
    linkRelation,
    spanStatus,
} from '@/lib/traces';
import { cn } from '@/lib/utils';
import { index as logsIndex } from '@/routes/logs';
import { show as traceShow } from '@/routes/traces';
import type { LinkedTrace, Span, SpanLink } from '@/types';

const props = defineProps<{
    span: Span;
    teamSlug: string;
    /** The palette utility for this span's service, matching its bar. */
    colour?: string;
    /**
     * The linked traces this instance actually holds, keyed by trace id.
     *
     * Absent where nothing resolved them — the styleguide, the log viewer's
     * preview panel — in which case every link is offered as unresolved rather
     * than claimed to be missing.
     */
    linkedTraces?: Record<string, LinkedTrace>;
}>();

const { naming } = useSpanNaming();

const status = computed(() => spanStatus(props.span));
const heading = computed(() => spanLabel(props.span, naming.value));
const detail = computed(() => spanDetail(props.span, naming.value));

/** Only worth printing when it is not already the heading. */
const rawName = computed(() =>
    heading.value === props.span.name ? '' : props.span.name,
);

const tabs = computed(() => [
    {
        id: 'attributes' as const,
        label: 'Attributes',
        count: Object.keys(props.span.attributes).length,
    },
    { id: 'events' as const, label: 'Events', count: props.span.events.length },
    { id: 'links' as const, label: 'Links', count: props.span.links.length },
]);

const activeTab = ref<'attributes' | 'events' | 'links'>('attributes');

/**
 * What one link resolves to, if anything.
 *
 * Three states, and they are not the same: a trace we hold and can open, a
 * trace this instance never received (another process's, or one past the 30-day
 * span window), and a link back into the trace already on screen.
 */
const resolve = (link: SpanLink) => {
    const self = link.traceId === props.span.traceId;
    const trace = self ? undefined : props.linkedTraces?.[link.traceId];

    return {
        self,
        trace,
        relation: linkRelation(link),
        href: trace
            ? traceShow({
                  current_team: props.teamSlug,
                  trace: link.traceId,
              }).url.concat(`?ts=${encodeURIComponent(trace.startedAt)}`)
            : null,
    };
};

// A different span is a different set of tabs; start from the top.
watch(
    () => props.span.spanId,
    () => {
        activeTab.value = 'attributes';
    },
);

const { copy, copied } = useClipboard({ copiedDuring: 1_500, legacy: true });

const attributesJson = computed(() =>
    JSON.stringify(props.span.attributes, null, 2),
);

/**
 * The other half of the logs/traces link.
 *
 * The window is deliberately generous around the span rather than exactly its
 * extent: a log line is written at some point during the work the span covers,
 * and clock skew between two services is measured in seconds.
 */
const logsHref = computed(() => {
    const start = Date.parse(`${props.span.timestamp}Z`);
    const from = new Date(start - 60_000).toISOString();
    const to = new Date(start + props.span.durationMs + 60_000).toISOString();

    const query = new URLSearchParams({
        trace_id: props.span.traceId,
        span_id: props.span.spanId,
        from,
        to,
    });

    return `${logsIndex(props.teamSlug).url}?${query.toString()}`;
});
</script>

<template>
    <aside
        class="flex min-h-0 flex-col gap-4 overflow-y-auto rounded-lg border bg-card p-4"
        data-test="span-detail"
    >
        <header class="flex flex-col gap-2">
            <div class="flex min-w-0 items-center gap-2">
                <span
                    :class="
                        cn(
                            'size-2 shrink-0 rounded-full',
                            status === 'Error'
                                ? SPAN_STATUS_BAR_CLASS.Error
                                : (colour ?? SPAN_STATUS_BAR_CLASS.Unset),
                        )
                    "
                    aria-hidden="true"
                />
                <h2 class="min-w-0 flex-1 truncate text-sm font-semibold">
                    {{ heading }}
                </h2>
                <span
                    v-if="SPAN_KIND_LABEL[span.kind]"
                    class="shrink-0 rounded border px-1.5 text-xs text-muted-foreground"
                >
                    {{ SPAN_KIND_LABEL[span.kind] }}
                </span>
            </div>

            <!--
              The name the exporter chose, whenever the heading above is one
              Bilis derived instead. The panel is where a reader goes to check
              what is actually stored, so the derivation never gets to be the
              only thing on the page claiming to be the span's name.
            -->
            <p
                v-if="rawName"
                class="truncate font-mono text-xs text-muted-foreground"
            >
                {{ rawName }}
            </p>

            <p
                class="text-xs text-muted-foreground"
                :title="formatUtcTimestamp(span.timestamp)"
            >
                {{ formatTimestamp(span.timestamp) }}
            </p>
        </header>

        <div
            v-if="status === 'Error'"
            class="rounded-md border border-severity-error/40 bg-severity-error/[0.06] p-3"
        >
            <p
                :class="
                    cn('text-xs font-medium', SPAN_STATUS_TEXT_CLASS[status])
                "
            >
                Span failed
            </p>
            <p
                v-if="span.statusMessage"
                class="mt-1 font-mono text-xs break-words"
            >
                {{ span.statusMessage }}
            </p>
        </div>

        <dl class="grid grid-cols-2 gap-x-4 gap-y-3">
            <TraceFact label="Service" :value="span.serviceName || '—'" />
            <TraceFact
                label="Duration"
                :value="formatDuration(span.durationMs)"
            >
                <span :class="durationClass(span.durationMs)">
                    {{ formatDuration(span.durationMs) }}
                </span>
            </TraceFact>
            <TraceFact label="Detail" :value="detail || '—'" />
            <TraceFact label="Status" :value="span.statusCode || 'Unset'" />
            <TraceFact label="Span ID" :value="span.spanId" mono copyable />
            <TraceFact
                label="Parent"
                :value="span.parentSpanId || 'none (root)'"
                mono
                :copyable="span.parentSpanId !== ''"
            />
        </dl>

        <!--
          The reason both signals live in one product. The span already knows its
          trace and span ids, so the log viewer can be handed an exact predicate
          rather than a search.
        -->
        <Button as-child variant="outline" size="sm" data-test="span-logs-link">
            <Link :href="logsHref">
                <ScrollText class="size-4" />
                View logs for this span
            </Link>
        </Button>

        <div class="flex min-h-0 flex-col gap-3">
            <div class="flex items-center gap-1 border-b">
                <button
                    v-for="tab in tabs"
                    :key="tab.id"
                    type="button"
                    :class="
                        cn(
                            '-mb-px flex items-center gap-1.5 border-b-2 px-2 py-1.5 text-xs transition-colors focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none',
                            activeTab === tab.id
                                ? 'border-foreground font-medium text-foreground'
                                : 'border-transparent text-muted-foreground hover:text-foreground',
                        )
                    "
                    :aria-selected="activeTab === tab.id"
                    role="tab"
                    :data-test="`span-tab-${tab.id}`"
                    @click="activeTab = tab.id"
                >
                    {{ tab.label }}
                    <span
                        v-if="tab.count > 0"
                        class="rounded-full bg-muted px-1.5 text-muted-foreground tabular-nums"
                    >
                        {{ tab.count }}
                    </span>
                </button>

                <button
                    v-if="activeTab === 'attributes' && tabs[0].count > 0"
                    type="button"
                    class="ml-auto inline-flex size-6 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                    :aria-label="copied ? 'Copied' : 'Copy attributes as JSON'"
                    :title="copied ? 'Copied' : 'Copy attributes as JSON'"
                    data-test="span-copy-attributes"
                    @click="copy(attributesJson)"
                >
                    <component :is="copied ? Check : Copy" class="size-3.5" />
                </button>
            </div>

            <section v-if="activeTab === 'attributes'" role="tabpanel">
                <!--
                  Key above value rather than beside it. In a 22rem panel a
                  two-column grid gives every value whatever the longest key
                  leaves behind, so one long attribute name starves the whole
                  list; stacked, each value gets the full width and wraps once
                  instead of three times.
                -->
                <SpanAttributes
                    :attributes="span.attributes"
                    :reset-key="span.spanId"
                />
            </section>

            <section
                v-else-if="activeTab === 'events'"
                role="tabpanel"
                class="flex flex-col gap-2"
                data-test="span-events"
            >
                <div
                    v-for="(event, index) in span.events"
                    :key="`${event.name}-${index}`"
                    class="rounded-md border p-2"
                >
                    <div class="flex items-baseline justify-between gap-2">
                        <span class="text-xs font-medium break-words">
                            {{ event.name }}
                        </span>
                        <span
                            class="shrink-0 font-mono text-xs text-muted-foreground tabular-nums"
                            :title="formatUtcTimestamp(event.timestamp)"
                        >
                            {{ formatTimestamp(event.timestamp) }}
                        </span>
                    </div>
                    <SpanAttributes
                        v-if="Object.keys(event.attributes).length > 0"
                        class="mt-1.5"
                        :attributes="event.attributes"
                        :reset-key="span.spanId"
                        flat
                    />
                </div>

                <p
                    v-if="span.events.length === 0"
                    class="text-xs text-muted-foreground"
                >
                    This span recorded no events.
                </p>
            </section>

            <!--
              Links are the other way a span says where it belongs, and the only
              way one whose parent lives in a different trace can say it at all.
              Each is offered as a way out only when this instance actually holds
              the trace it names — a link is a claim about a trace, not proof we
              received it.
            -->
            <section
                v-else
                role="tabpanel"
                class="flex flex-col gap-2"
                data-test="span-links"
            >
                <template
                    v-for="(link, index) in span.links"
                    :key="`${link.traceId}-${link.spanId}-${index}`"
                >
                    <component
                        :is="resolve(link).href ? Link : 'div'"
                        v-bind="
                            resolve(link).href
                                ? { href: resolve(link).href }
                                : {}
                        "
                        :class="
                            cn(
                                'flex flex-col gap-1 rounded-md border p-2',
                                resolve(link).href
                                    ? 'transition-colors hover:bg-accent/60 focus-visible:bg-accent/60 focus-visible:outline-none'
                                    : '',
                            )
                        "
                        :data-test="`span-link-${link.spanId}`"
                    >
                        <div class="flex items-baseline justify-between gap-2">
                            <span class="text-xs font-medium break-words">
                                {{ resolve(link).relation || 'linked span' }}
                            </span>
                            <ExternalLink
                                v-if="resolve(link).href"
                                class="size-3.5 shrink-0 text-muted-foreground"
                                aria-hidden="true"
                            />
                        </div>

                        <p
                            v-if="resolve(link).trace"
                            class="text-xs break-words text-muted-foreground"
                        >
                            {{
                                resolve(link).trace?.rootName ||
                                'Root span not received'
                            }}
                            <span v-if="resolve(link).trace?.rootService">
                                · {{ resolve(link).trace?.rootService }}
                            </span>
                        </p>

                        <p
                            class="font-mono text-xs break-all text-muted-foreground"
                        >
                            {{ link.traceId }} / {{ link.spanId }}
                        </p>

                        <!--
                          Three states, three sentences. "Not stored here" is a
                          fact about this instance, not about the trace: it is
                          normally another process's trace that was never sent,
                          or one whose spans have passed the 30-day window.
                        -->
                        <p
                            v-if="resolve(link).self"
                            class="text-xs text-muted-foreground"
                        >
                            Points at a span in this same trace.
                        </p>
                        <p
                            v-else-if="!resolve(link).trace"
                            class="text-xs text-muted-foreground"
                            data-test="span-link-unresolved"
                        >
                            That trace is not stored here — it was never sent to
                            this instance, or its spans have aged out.
                        </p>

                        <SpanAttributes
                            v-if="Object.keys(link.attributes).length > 0"
                            class="mt-0.5"
                            :attributes="link.attributes"
                            :reset-key="span.spanId"
                            flat
                        />
                    </component>
                </template>

                <p
                    v-if="span.links.length === 0"
                    class="text-xs text-muted-foreground"
                >
                    This span links to no others.
                </p>
            </section>
        </div>
    </aside>
</template>
