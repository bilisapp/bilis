<script setup lang="ts">
import { Head, Link, router, usePage, usePoll } from '@inertiajs/vue3';
import { ArrowUpRight, BellOff, Pencil } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import AlertEventRow from '@/components/AlertEventRow.vue';
import AlertExampleList from '@/components/AlertExampleList.vue';
import AlertStateBadge from '@/components/AlertStateBadge.vue';
import FactStrip from '@/components/FactStrip.vue';
import PageHeader from '@/components/PageHeader.vue';
import PageSection from '@/components/PageSection.vue';
import StorageBusy from '@/components/StorageBusy.vue';
import SurfaceMessage from '@/components/SurfaceMessage.vue';
import ThresholdChart from '@/components/ThresholdChart.vue';
import TraceFact from '@/components/TraceFact.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Skeleton } from '@/components/ui/skeleton';
import {
    ALERT_SEVERITY_LABEL,
    MUTE_OPTIONS,
    NO_DATA_LABEL,
    describeQuery,
    formatCondition,
    formatLabels,
    formatReading,
    formatSeconds,
    sinceSeconds,
} from '@/lib/alerts';
import { formatRelativeTime } from '@/lib/logs';
import { cn } from '@/lib/utils';
import {
    edit as alertsEdit,
    index as alertsIndex,
    mute as alertsMute,
} from '@/routes/alerts';
import type {
    AlertEventSummary,
    AlertLiveExamples,
    AlertLiveWindow,
    AlertPreviewResult,
    AlertRuleLive,
    AlertSeriesLive,
    Team,
} from '@/types';

/**
 * One rule, as it is now — the page a notification links to.
 *
 * Read-only, unlike the editor: which series are firing and how far over
 * the threshold, the last 24 hours against the threshold, what the rule's
 * window holds right now (the messages or spans behind the number), and
 * what it has fired and resolved. It polls, so it can stay open while
 * something burns. Nothing here evaluates the rule: "right now" is a read of
 * the window ending now, which the next evaluation will judge.
 */
const props = withDefaults(
    defineProps<{
        rule: AlertRuleLive;
        instances: AlertSeriesLive[];
        events: AlertEventSummary[];
        historyLimit: number;
        live: AlertLiveWindow;
        /** Deferred: the last 24 hours, bucketed. */
        preview?: AlertPreviewResult;
        /** Deferred: what the live window holds. */
        examples?: AlertLiveExamples;
    }>(),
    { preview: undefined, examples: undefined },
);

defineOptions({
    layout: (layoutProps: {
        currentTeam?: Team | null;
        rule?: AlertRuleLive;
    }) => ({
        breadcrumbs: [
            {
                title: 'Alerts',
                href: layoutProps.currentTeam
                    ? alertsIndex(layoutProps.currentTeam.slug)
                    : '/',
            },
            { title: layoutProps.rule?.name ?? 'Rule', href: '#' },
        ],
    }),
});

const POLL_MS = 30_000;

const page = usePage();

const teamSlug = computed(() => page.props.currentTeam?.slug ?? '');

usePoll(POLL_MS, {
    only: ['rule', 'instances', 'events', 'live', 'preview', 'examples'],
});

/** Ticks once a second so "for 12m" and "4m ago" stay true between polls. */
const now = ref(Date.now());
let clock: ReturnType<typeof setInterval> | undefined;

onMounted(() => {
    clock = setInterval(() => (now.value = Date.now()), 1000);
});

onBeforeUnmount(() => clearInterval(clock));

const sentence = computed(() => {
    const groupBy = props.rule.query.group_by
        ? ` · per ${props.rule.query.group_by}`
        : '';

    return `${describeQuery(props.rule.source, props.rule.query)}${groupBy} · ${formatCondition(props.rule)} · ${formatSeconds(props.rule.windowSeconds)} window, checked every ${formatSeconds(props.rule.intervalSeconds)}`;
});

const state = computed(() => {
    if (!props.rule.enabled) {
        return { state: 'disabled' as const, label: 'Turned off' };
    }

    if (props.rule.health === 'err') {
        return { state: 'error' as const, label: 'Query failed' };
    }

    if (props.rule.firing > 0) {
        return { state: 'firing' as const, label: undefined };
    }

    if (props.rule.pending > 0) {
        return { state: 'pending' as const, label: undefined };
    }

    return { state: 'inactive' as const, label: undefined };
});

const lastEvaluated = computed(() => {
    void now.value;

    return props.rule.lastEvaluatedAt
        ? formatRelativeTime(props.rule.lastEvaluatedAt)
        : 'Not yet';
});

const muted = computed(() =>
    props.rule.mutedUntil
        ? new Date(props.rule.mutedUntil).toLocaleString(undefined, {
              month: 'short',
              day: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
          })
        : null,
);

const notifies = computed(() => {
    if (muted.value) {
        return { value: 'Muted', detail: `until ${muted.value}` };
    }

    const enabled = props.rule.channels.filter((channel) => channel.enabled);

    return enabled.length
        ? {
              value: enabled.map((channel) => channel.name).join(', '),
              detail: undefined,
          }
        : { value: 'Nowhere', detail: 'No channel is attached' };
});

const scope = computed(() =>
    props.rule.projects === null
        ? 'Every project'
        : props.rule.projects.join(', ') || 'No project left',
);

const windowText = computed(() => {
    const format = (iso: string) =>
        new Date(iso).toLocaleTimeString(undefined, {
            hour: '2-digit',
            minute: '2-digit',
        });

    return `${format(props.live.from)} – ${format(props.live.to)}`;
});

/** "for 12m" on a firing or pending series; when it last had data otherwise. */
function seriesSince(series: AlertSeriesLive): string {
    void now.value;

    if (series.state === 'inactive') {
        return series.lastSeenWithDataAt
            ? `data ${formatRelativeTime(series.lastSeenWithDataAt)}`
            : '';
    }

    const since = sinceSeconds(
        series.state === 'firing' ? series.firedAt : series.activeAt,
        now.value,
    );

    return since === null
        ? ''
        : `for ${formatSeconds(since >= 3600 ? since - (since % 60) : since)}`;
}

function mute(until: '1h' | '1d' | '1w' | 'off') {
    router.post(
        alertsMute([teamSlug.value, props.rule.id]).url,
        { until },
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head :title="rule.name" />

    <div class="flex flex-1 flex-col pb-16">
        <PageHeader :title="rule.name">
            <p
                class="font-mono text-xs text-muted-foreground"
                data-test="alert-show-sentence"
            >
                {{ sentence }}
            </p>

            <template #actions>
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button variant="ghost" data-test="alert-show-mute">
                            <BellOff class="size-4" aria-hidden="true" />
                            {{ muted ? 'Muted' : 'Mute' }}
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem
                            v-for="option in MUTE_OPTIONS"
                            :key="option.value"
                            @select="mute(option.value)"
                            >{{ option.label }}</DropdownMenuItem
                        >
                        <DropdownMenuItem v-if="muted" @select="mute('off')"
                            >Unmute</DropdownMenuItem
                        >
                    </DropdownMenuContent>
                </DropdownMenu>
                <Button variant="outline" as-child>
                    <Link :href="alertsEdit([teamSlug, rule.id]).url">
                        <Pencil class="size-4" aria-hidden="true" />
                        Edit rule
                    </Link>
                </Button>
            </template>
        </PageHeader>

        <FactStrip :columns="5">
            <TraceFact label="State" :value="state.label ?? state.state">
                <AlertStateBadge
                    :state="state.state"
                    :label="state.label"
                    :count="
                        state.state === 'firing'
                            ? rule.firing
                            : state.state === 'pending'
                              ? rule.pending
                              : null
                    "
                    data-test="alert-show-state"
                />
            </TraceFact>
            <TraceFact
                label="Last checked"
                :value="lastEvaluated"
                :detail="`every ${formatSeconds(rule.intervalSeconds)}`"
            />
            <TraceFact
                label="Severity"
                :value="ALERT_SEVERITY_LABEL[rule.severity]"
                :detail="
                    rule.keepFiringForSeconds > 0
                        ? `keeps firing ${formatSeconds(rule.keepFiringForSeconds)}`
                        : undefined
                "
            />
            <TraceFact
                label="Notifies"
                :value="notifies.value"
                :detail="notifies.detail"
            />
            <TraceFact
                label="Reads"
                :value="scope"
                :detail="`no data: ${NO_DATA_LABEL[rule.noDataState].toLowerCase()}`"
            />
        </FactStrip>

        <div
            v-if="rule.health === 'err' && rule.lastError"
            class="mx-4 mt-4 rounded-lg border bg-muted/40 px-4 py-3 text-sm md:mx-10"
            role="status"
            data-test="alert-show-last-error"
        >
            <span class="font-medium text-foreground"
                >The last evaluation failed.</span
            >
            <span class="text-muted-foreground"> {{ rule.lastError }}</span>
        </div>

        <p
            v-if="rule.template && rule.why"
            class="mx-4 mt-4 text-sm text-muted-foreground md:mx-10"
            data-test="alert-show-why"
        >
            <span class="font-medium text-foreground">Set up by Bilis.</span>
            {{ rule.why }}
        </p>

        <PageSection
            title="Right now"
            :note="windowText"
            :description="`What the last ${formatSeconds(rule.windowSeconds)} hold across every series, most first — the messages behind the number. The next check judges this window.`"
            class="pt-8"
            data-test="alert-show-now"
        >
            <template #aside>
                <a
                    v-if="live.explorerHref"
                    :href="live.explorerHref"
                    class="inline-flex items-center gap-1 text-xs text-muted-foreground transition-colors hover:text-foreground"
                    data-test="alert-show-explore"
                >
                    Open in
                    {{
                        rule.source === 'traces'
                            ? 'Traces'
                            : rule.source === 'metrics'
                              ? 'Metrics'
                              : 'Logs'
                    }}
                    <ArrowUpRight class="size-3" aria-hidden="true" />
                </a>
            </template>

            <div class="mt-4 max-w-3xl">
                <div v-if="!examples" class="flex flex-col gap-3">
                    <Skeleton
                        v-for="index in 3"
                        :key="index"
                        class="h-8 w-full"
                    />
                </div>
                <StorageBusy
                    v-else-if="examples.unavailable"
                    :store="
                        rule.source === 'traces'
                            ? 'Trace storage'
                            : 'Log storage'
                    "
                />
                <SurfaceMessage
                    v-else-if="!examples.supported"
                    title="A metric is one number per series"
                >
                    There are no messages behind it to list — the chart below
                    and the series table say everything the rule read.
                </SurfaceMessage>
                <SurfaceMessage
                    v-else-if="examples.items.length === 0"
                    title="Nothing matched in this window"
                >
                    The rule's filters matched no
                    {{ rule.source === 'traces' ? 'spans' : 'log lines' }} in
                    the last {{ formatSeconds(rule.windowSeconds) }}.
                </SurfaceMessage>
                <AlertExampleList
                    v-else
                    :examples="examples.items"
                    :team-slug="teamSlug"
                />
            </div>
        </PageSection>

        <PageSection
            title="Series"
            :note="instances.length ? String(instances.length) : undefined"
            description="Each value the rule tracks, as the last check read it."
            flush
            data-test="alert-show-series"
        >
            <div v-if="instances.length" class="mt-3 border-t">
                <div
                    v-for="series in instances"
                    :key="series.id"
                    class="relative grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-x-5 gap-y-1 border-b border-border/70 px-4 py-3 md:grid-cols-[5.5rem_minmax(0,1fr)_9rem_7rem_5rem] md:px-10"
                    :data-test="`alert-series-${series.id}`"
                >
                    <span
                        v-if="series.state === 'firing'"
                        class="absolute inset-y-2.5 left-0 w-[3px] rounded-r-sm bg-severity-error"
                        aria-hidden="true"
                    />
                    <div>
                        <AlertStateBadge :state="series.state" />
                    </div>
                    <span
                        class="truncate font-mono text-xs text-foreground"
                        :title="formatLabels(series.labels)"
                        >{{ formatLabels(series.labels) }}</span
                    >
                    <div class="text-right font-mono tabular-nums">
                        <span class="text-sm font-medium text-foreground">{{
                            formatReading(series).value
                        }}</span>
                        <span class="block text-[11px] text-muted-foreground">{{
                            formatReading(series).threshold
                        }}</span>
                    </div>
                    <span
                        class="hidden text-right text-xs text-muted-foreground tabular-nums md:block"
                        >{{ seriesSince(series) }}</span
                    >
                    <a
                        v-if="series.explorerHref"
                        :href="series.explorerHref"
                        class="hidden items-center justify-end gap-1 text-xs text-muted-foreground transition-colors hover:text-foreground md:inline-flex"
                    >
                        Explore
                        <ArrowUpRight class="size-3" aria-hidden="true" />
                    </a>
                    <span v-else class="hidden md:block" />
                </div>
            </div>
            <SurfaceMessage
                v-else
                class="mx-4 mt-3 md:mx-10"
                :title="
                    rule.lastEvaluatedAt
                        ? 'No series with data yet'
                        : 'Not checked yet'
                "
            >
                {{
                    rule.lastEvaluatedAt
                        ? 'The rule has not read a value since it was saved — nothing matched its filters, or every series was under its minimum.'
                        : 'The scheduler checks a new or edited rule within a minute.'
                }}
            </SurfaceMessage>
        </PageSection>

        <PageSection
            title="Last 24 hours"
            description="The rule's reading, one point per window, against its threshold. Shaded stretches would have fired."
            data-test="alert-show-chart"
        >
            <div class="mt-4">
                <Skeleton v-if="!preview" class="h-56 w-full" />
                <ThresholdChart
                    v-else
                    :result="preview"
                    :source="rule.source"
                    :aggregation="rule.query.aggregation"
                    :compare="rule.query.compare ?? null"
                    :metric="rule.query.metric ?? null"
                    :condition="rule.condition"
                    :threshold="rule.threshold"
                />
            </div>
        </PageSection>

        <PageSection
            title="History"
            :note="
                events.length >= historyLimit
                    ? `last ${historyLimit}`
                    : undefined
            "
            description="What the rule fired and resolved, with what each firing caught."
            flush
            data-test="alert-show-history"
        >
            <div v-if="events.length" class="mt-3 border-t">
                <AlertEventRow
                    v-for="event in events"
                    :key="event.id"
                    :event="event"
                    :team-slug="teamSlug"
                />
            </div>
            <SurfaceMessage
                v-else
                class="mx-4 mt-3 md:mx-10"
                title="Nothing has fired yet"
            >
                Every time a series crosses the threshold, and every time it
                recovers, it is listed here.
            </SurfaceMessage>
        </PageSection>

        <p
            :class="
                cn(
                    'mx-4 mt-6 text-xs text-muted-foreground md:mx-10',
                    !rule.enabled && 'text-foreground',
                )
            "
        >
            {{
                rule.enabled
                    ? 'This page refreshes every 30 seconds.'
                    : 'This rule is turned off: it is not checked, and nothing here changes until it is turned back on.'
            }}
        </p>
    </div>
</template>
