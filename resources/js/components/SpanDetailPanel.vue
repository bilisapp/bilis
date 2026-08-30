<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Check, Copy, ScrollText } from '@lucide/vue';
import { useClipboard } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import TraceFact from '@/components/TraceFact.vue';
import { Button } from '@/components/ui/button';
import { useSpanNaming } from '@/composables/useSpanNaming';
import { formatTimestamp, formatUtcTimestamp } from '@/lib/logs';
import {
    formatDuration,
    spanDetail,
    spanLabel,
    SPAN_KIND_LABEL,
    SPAN_STATUS_BAR_CLASS,
    SPAN_STATUS_TEXT_CLASS,
    spanStatus,
} from '@/lib/traces';
import { cn } from '@/lib/utils';
import { index as logsIndex } from '@/routes/logs';
import type { Span } from '@/types';

const props = defineProps<{
    span: Span;
    teamSlug: string;
    /** The palette utility for this span's service, matching its bar. */
    colour?: string;
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
]);

const activeTab = ref<'attributes' | 'events'>('attributes');

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
            />
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
                <dl v-if="tabs[0].count > 0" class="flex flex-col gap-2.5">
                    <div
                        v-for="(value, key) in span.attributes"
                        :key="key"
                        class="flex flex-col gap-0.5"
                    >
                        <dt
                            class="font-mono text-xs break-all text-muted-foreground"
                        >
                            {{ key }}
                        </dt>
                        <dd class="font-mono text-xs break-all">{{ value }}</dd>
                    </div>
                </dl>
                <p v-else class="text-xs text-muted-foreground">
                    This span carries no attributes.
                </p>
            </section>

            <section
                v-else
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
                    <dl
                        v-if="Object.keys(event.attributes).length > 0"
                        class="mt-1.5 flex flex-col gap-2"
                    >
                        <div
                            v-for="(value, key) in event.attributes"
                            :key="key"
                            class="flex flex-col gap-0.5"
                        >
                            <dt
                                class="font-mono text-xs break-all text-muted-foreground"
                            >
                                {{ key }}
                            </dt>
                            <dd class="font-mono text-xs break-all">
                                {{ value }}
                            </dd>
                        </div>
                    </dl>
                </div>

                <p
                    v-if="span.events.length === 0"
                    class="text-xs text-muted-foreground"
                >
                    This span recorded no events.
                </p>
            </section>
        </div>
    </aside>
</template>
