<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import {
    ChevronDown,
    ExternalLink,
    FileCheck2,
    GitPullRequest,
    Radio,
    ScrollText,
    Waypoints,
    XCircle,
} from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import CodeCanvas from '@/components/CodeCanvas.vue';
import FactStrip from '@/components/FactStrip.vue';
import FixJobEventRow from '@/components/FixJobEventRow.vue';
import FixJobStatusBadge from '@/components/FixJobStatusBadge.vue';
import PageHeader from '@/components/PageHeader.vue';
import PageSection from '@/components/PageSection.vue';
import TraceFact from '@/components/TraceFact.vue';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { useFixJobStream } from '@/composables/useFixJobStream';
import {
    cancel as cancelJob,
    index as autofixIndex,
    show as autofixShow,
    streamToken,
} from '@/routes/autofix';
import { index as logsIndex } from '@/routes/logs';
import { show as traceShow } from '@/routes/traces';
import type { FixJobDetail, FixJobEvent, FixJobStream, Team } from '@/types';

const props = defineProps<{
    teamSlug: string;
    job: FixJobDetail;
    stream: FixJobStream | null;
    canCancel: boolean;
}>();

defineOptions({
    layout: (layoutProps: {
        currentTeam?: Team | null;
        job: FixJobDetail;
    }) => ({
        breadcrumbs: [
            {
                title: 'Autofix',
                href: layoutProps.currentTeam
                    ? autofixIndex(layoutProps.currentTeam.slug)
                    : '/',
            },
            {
                title: layoutProps.job.title,
                href: layoutProps.currentTeam
                    ? autofixShow([
                          layoutProps.currentTeam.slug,
                          layoutProps.job.uuid,
                      ])
                    : '/',
            },
        ],
    }),
});

/**
 * A running job streams; a finished one renders the transcript it was handed.
 * Either way the persisted events are the floor, so a stream that never
 * connects still leaves a readable page.
 */
const liveStream = props.stream
    ? useFixJobStream(
          {
              streamUrl: props.stream.url,
              tokenUrl: streamToken([props.teamSlug, props.job.uuid]).url,
          },
          props.job.events,
      )
    : null;

onMounted(() => {
    liveStream?.start();
});

const rawEvents = computed(() => liveStream?.events.value ?? props.job.events);

function toolCallKey(event: FixJobEvent): string | null {
    if (event.type !== 'tool_call' && event.type !== 'tool_result') {
        return null;
    }

    const id = event.data?.tool_call_id;

    return typeof id === 'string' && id !== '' ? `${event.type}:${id}` : null;
}

/**
 * Collapse a transcript's superseded rows before rendering.
 *
 * Pi announces a tool call before its arguments have streamed in and repeats
 * it once they have, and an agent message arrives as progressively longer
 * copies of itself; a transcript that persisted those intermediates would
 * otherwise render every draft as its own row. Only the final version of a
 * tool call/result (by `tool_call_id`) and of a message run survives.
 */
const events = computed<FixJobEvent[]>(() => {
    const source = rawEvents.value;
    const lastByCall = new Map<string, FixJobEvent>();

    for (const event of source) {
        const key = toolCallKey(event);

        if (key) {
            lastByCall.set(key, event);
        }
    }

    const out: FixJobEvent[] = [];

    for (const event of source) {
        const key = toolCallKey(event);

        if (key && lastByCall.get(key) !== event) {
            continue;
        }

        const prev = out[out.length - 1];

        if (
            prev &&
            event.type === 'agent_message' &&
            prev.type === 'agent_message'
        ) {
            const text = event.data?.text;
            const prevText = prev.data?.text;

            if (
                typeof text === 'string' &&
                typeof prevText === 'string' &&
                text.startsWith(prevText)
            ) {
                out[out.length - 1] = event;

                continue;
            }
        }

        out.push(event);
    }

    return out;
});
const streamStatus = computed(() => liveStream?.status.value ?? 'idle');

/**
 * Turn ClickHouse's naive UTC timestamps back into instants the log viewer
 * accepts, and pad the window a little so the surrounding lines come with it.
 */
function toIso(value: string | null, padMinutes: number): string | null {
    if (!value) {
        return null;
    }

    const parsed = new Date(`${value.slice(0, 23).replace(' ', 'T')}Z`);

    if (Number.isNaN(parsed.getTime())) {
        return null;
    }

    return new Date(parsed.getTime() + padMinutes * 60_000).toISOString();
}

/**
 * The log viewer, narrowed to the error this job was raised for: same project,
 * same service, searching the exception class over the window it was seen in.
 */
const logsHref = computed(() => {
    const from = toIso(props.job.firstSeen, -5);
    const to = toIso(props.job.lastSeen, 5);

    return logsIndex(props.teamSlug, {
        query: {
            project: props.job.project.slug,
            service: props.job.serviceName ?? undefined,
            search: props.job.exception,
            from: from ?? undefined,
            to: to ?? undefined,
        },
    });
});

const formatTime = (iso: string | null): string => {
    if (!iso) {
        return '—';
    }

    const parsed = new Date(iso);

    return Number.isNaN(parsed.getTime())
        ? '—'
        : new Intl.DateTimeFormat(undefined, {
              dateStyle: 'medium',
              timeStyle: 'short',
          }).format(parsed);
};

const isCustom = computed(() => props.job.type === 'custom');

/**
 * A custom job has no error to describe, so the stats it can honestly show are
 * the ones about the run itself. Nothing is padded with an em dash that would
 * only ever be an em dash.
 */
const stats = computed(() =>
    isCustom.value
        ? [
              { label: 'Project', value: props.job.project.name },
              { label: 'Repository', value: props.job.repository },
              {
                  label: 'Base commit',
                  value: props.job.baseSha?.slice(0, 12) || '—',
              },
              { label: 'Started', value: formatTime(props.job.createdAt) },
          ]
        : [
              { label: 'Project', value: props.job.project.name },
              { label: 'Service', value: props.job.serviceName ?? '—' },
              {
                  label: 'Occurrences',
                  value:
                      props.job.occurrences === null
                          ? '—'
                          : new Intl.NumberFormat().format(
                                props.job.occurrences,
                            ),
              },
              { label: 'Repository', value: props.job.repository },
              {
                  label: 'Base commit',
                  value: props.job.baseSha?.slice(0, 12) || '—',
              },
              { label: 'Started', value: formatTime(props.job.createdAt) },
          ],
);

/**
 * The trace the triggering log line belonged to, as `TraceContextBuilder`
 * froze it onto the job. Mirrors that class's stored shape; it travels inside
 * `errorContext`, which is why it is narrowed here rather than typed upstream.
 */
type TraceContext = {
    trace_id: string;
    span_id: string;
    state: 'rendered' | 'expired' | 'missing' | 'unavailable';
    root_name: string;
    root_service: string;
    started_at: string;
    span_count: number;
    error_count: number;
    rendered_spans: number;
    omitted_spans: number;
    waterfall: string | null;
};

const trace = computed<TraceContext | null>(() => {
    const candidate = props.job.errorContext?.trace;

    return candidate && typeof candidate === 'object' && 'state' in candidate
        ? (candidate as TraceContext)
        : null;
});

/** The waterfall page for the trace, bounded by the time the summary gave. */
const traceHref = computed(() =>
    trace.value
        ? traceShow(
              { current_team: props.teamSlug, trace: trace.value.trace_id },
              {
                  query: trace.value.started_at
                      ? { ts: toIso(trace.value.started_at, 0) ?? undefined }
                      : undefined,
              },
          )
        : null,
);

/** One sentence on why there is no waterfall, in the state's own words. */
const traceNotice = computed(() => {
    switch (trace.value?.state) {
        case 'expired':
            return 'The trace was referenced by the log line, but its spans have expired. Only the summary remains.';
        case 'missing':
            return 'The trace was referenced by the log line, but no trace with that id is stored.';
        case 'unavailable':
            return 'The trace was referenced by the log line, but trace storage could not be read when this job was raised.';
        default:
            return null;
    }
});

/** Long requests fold away; short ones are never worth a disclosure control. */
const LONG_REQUEST = 320;

const instructions = computed(() => props.job.instructions ?? '');
const foldable = computed(() => instructions.value.length > LONG_REQUEST);
const expanded = ref(false);
</script>

<template>
    <Head :title="job.title" />

    <div class="flex flex-1 flex-col">
        <PageHeader :title="job.title">
            <template #title>
                <span class="flex min-w-0 items-center gap-3">
                    <span
                        v-if="isCustom"
                        class="inline-flex h-5 shrink-0 items-center rounded-full px-2 text-[11px] font-medium tracking-normal text-foreground/80 ring-1 ring-border ring-inset"
                        data-test="fix-job-custom-marker"
                    >
                        {{ job.typeLabel }}
                    </span>
                    <span class="truncate" :title="job.title">{{
                        job.title
                    }}</span>
                    <FixJobStatusBadge
                        :status="job.status"
                        :label="job.statusLabel"
                        class="tracking-normal"
                    />
                </span>
            </template>

            <div class="flex max-w-4xl flex-col gap-1.5">
                <p
                    v-if="!isCustom && job.message"
                    class="break-words text-foreground/80"
                >
                    {{ job.message }}
                </p>
                <p>
                    <template v-if="!isCustom && job.occurrences !== null">
                        <span class="font-medium text-foreground"
                            >{{
                                new Intl.NumberFormat().format(job.occurrences)
                            }}
                            occurrences</span
                        >
                        ·
                    </template>
                    against
                    <span class="font-mono">{{ job.repository }}</span>
                    · started {{ formatTime(job.createdAt) }}
                    <template v-if="streamStatus === 'live'">
                        ·
                        <span
                            class="inline-flex items-center gap-1 font-medium text-foreground"
                            data-test="fix-job-live"
                        >
                            <Radio class="size-3.5" /> Live
                        </span>
                    </template>
                    <template v-else-if="streamStatus === 'reconnecting'">
                        ·
                        <span data-test="fix-job-reconnecting"
                            >Reconnecting…</span
                        >
                    </template>
                    <template v-else-if="streamStatus === 'unavailable'">
                        ·
                        <span data-test="fix-job-stream-unavailable"
                            >Live updates unavailable — showing what was
                            recorded.</span
                        >
                    </template>
                </p>
            </div>

            <template #actions>
                <Button v-if="job.prUrl" as-child>
                    <a
                        :href="job.prUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        data-test="fix-job-pr-button"
                    >
                        <GitPullRequest /> Pull request #{{ job.prNumber }}
                        <ExternalLink />
                    </a>
                </Button>

                <Button v-if="!isCustom" variant="outline" as-child>
                    <Link :href="logsHref" data-test="fix-job-logs-link">
                        <ScrollText /> View in logs
                    </Link>
                </Button>

                <Form
                    v-if="canCancel"
                    v-bind="cancelJob.form([teamSlug, job.uuid])"
                    v-slot="{ processing }"
                >
                    <Button
                        type="submit"
                        variant="ghost"
                        class="text-muted-foreground"
                        :disabled="processing"
                        data-test="fix-job-cancel"
                    >
                        <XCircle /> Cancel
                    </Button>
                </Form>
            </template>
        </PageHeader>

        <!-- The run's facts, on one hairline-bounded strip. -->
        <FactStrip :columns="6" data-test="fix-job-facts">
            <TraceFact
                v-for="stat in stats"
                :key="stat.label"
                :label="stat.label"
                :value="stat.value"
                mono
            />
        </FactStrip>

        <p
            v-if="job.failureReason"
            class="border-b bg-severity-error/10 px-4 py-3 text-sm text-severity-error md:px-10"
            data-test="fix-job-failure"
        >
            {{ job.failureReason }}
        </p>

        <!--
          What was asked for, in the words it was asked in. Long requests
          fold, because the timeline underneath is the reason to be here.
        -->
        <Collapsible
            v-if="isCustom && instructions"
            v-model:open="expanded"
            class="border-b px-4 py-5 md:px-10"
            data-test="fix-job-instructions"
        >
            <div class="flex items-baseline justify-between gap-3">
                <p class="eyebrow">Requested</p>

                <CollapsibleTrigger
                    v-if="foldable"
                    class="inline-flex shrink-0 items-center gap-1 text-xs text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                    data-test="fix-job-instructions-toggle"
                >
                    {{ expanded ? 'Show less' : 'Show all' }}
                    <ChevronDown
                        class="size-3.5 transition-transform"
                        :class="expanded ? 'rotate-180' : undefined"
                    />
                </CollapsibleTrigger>
            </div>

            <p
                v-if="!foldable || !expanded"
                class="mt-2 max-w-4xl text-sm whitespace-pre-wrap text-foreground"
                :class="foldable ? 'line-clamp-3' : undefined"
            >
                {{ instructions }}
            </p>

            <CollapsibleContent v-if="foldable">
                <p
                    class="mt-2 max-w-4xl text-sm whitespace-pre-wrap text-foreground"
                >
                    {{ instructions }}
                </p>
            </CollapsibleContent>
        </Collapsible>

        <div class="grid flex-1 grid-cols-1 lg:grid-cols-2">
            <PageSection
                title="Session"
                description="What the agent did, in order. The transcript is stored with the job, so it reads the same after the run ends."
                class="pt-8 pb-10"
                data-test="fix-job-session"
            >
                <template v-if="events.length > 0" #aside>
                    <span class="font-mono tabular-nums"
                        >{{ events.length }} events</span
                    >
                </template>

                <ol
                    v-if="events.length > 0"
                    class="relative"
                    data-test="fix-job-timeline"
                >
                    <FixJobEventRow
                        v-for="(event, index) in events"
                        :key="`${event.seq ?? event.data?.stream_id ?? index}-${event.ts}`"
                        :event="event"
                        :last="index === events.length - 1"
                    />
                </ol>

                <p
                    v-else
                    class="py-4 text-sm text-muted-foreground"
                    data-test="fix-job-timeline-empty"
                >
                    No session events recorded yet.
                </p>
            </PageSection>

            <div
                class="flex min-w-0 flex-col border-t pb-10 lg:border-t-0 lg:border-l"
            >
                <PageSection title="Proposed change" class="pt-8">
                    <template #description>
                        The validated diff, against
                        <code class="font-mono">{{ job.defaultBranch }}</code
                        >. Machine-generated — read it the way you would read a
                        stranger's patch.
                    </template>

                    <CodeCanvas
                        v-if="job.diff"
                        :patch="job.diff"
                        data-test="fix-job-diff"
                    />
                    <div
                        v-else-if="job.status === 'no_change'"
                        class="flex flex-col gap-2 py-4"
                        data-test="fix-job-no-change"
                    >
                        <p
                            class="inline-flex items-center gap-2 text-sm font-medium text-foreground"
                        >
                            <FileCheck2 class="size-4 text-muted-foreground" />
                            No change needed
                        </p>
                        <p class="max-w-prose text-sm text-muted-foreground">
                            The agent finished without touching the tree. Its
                            reasoning is in the session transcript.
                        </p>
                    </div>
                    <p
                        v-else
                        class="py-4 text-sm text-muted-foreground"
                        data-test="fix-job-diff-empty"
                    >
                        No diff has come back for this job yet.
                    </p>
                </PageSection>

                <PageSection
                    v-if="job.stack"
                    title="Stack trace"
                    description="The trace the fingerprint was cut from — what the agent was given to work with."
                >
                    <CodeCanvas
                        :code="job.stack"
                        filename="stack.log"
                        max-height="20rem"
                        hide-header
                        data-test="fix-job-stack"
                    />
                </PageSection>

                <!--
                  The trace as the agent read it: the same bounded text the
                  prompt carried, so a reviewer sees exactly what the model
                  saw rather than a richer view of it.
                -->
                <PageSection
                    v-if="trace"
                    title="Trace"
                    data-test="fix-job-trace"
                >
                    <template #aside>
                        <Link
                            v-if="traceHref && trace.state !== 'missing'"
                            :href="traceHref"
                            class="inline-flex items-center gap-1.5 text-xs text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                            data-test="fix-job-trace-link"
                        >
                            <Waypoints class="size-3.5" /> Open trace
                        </Link>
                    </template>
                    <template #description>
                        <template v-if="trace.state === 'rendered'">
                            The waterfall the agent was handed —
                            <span class="font-mono tabular-nums"
                                >{{ trace.rendered_spans }} of
                                {{ trace.span_count }}</span
                            >
                            spans,
                            <span class="font-mono tabular-nums">{{
                                trace.error_count
                            }}</span>
                            with Error status.
                            <code class="font-mono">&gt;&gt;</code> marks the
                            span that emitted the log line,
                            <code class="font-mono">!!</code> a span that
                            failed.
                        </template>
                        <template v-else>{{ traceNotice }}</template>
                    </template>
                    <CodeCanvas
                        v-if="trace.waterfall"
                        :code="trace.waterfall"
                        filename="trace.txt"
                        max-height="24rem"
                        hide-header
                        data-test="fix-job-trace-waterfall"
                    />
                </PageSection>
            </div>
        </div>
    </div>
</template>
