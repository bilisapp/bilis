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
import FixJobEventRow from '@/components/FixJobEventRow.vue';
import FixJobStatusBadge from '@/components/FixJobStatusBadge.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
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

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <header class="space-y-3">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0 space-y-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <FixJobStatusBadge
                            :status="job.status"
                            :label="job.statusLabel"
                        />
                        <span
                            v-if="streamStatus === 'live'"
                            class="inline-flex items-center gap-1.5 text-xs font-medium text-severity-info"
                            data-test="fix-job-live"
                        >
                            <Radio class="size-3.5" /> Live
                        </span>
                        <span
                            v-else-if="streamStatus === 'reconnecting'"
                            class="text-xs text-muted-foreground"
                            data-test="fix-job-reconnecting"
                        >
                            Reconnecting…
                        </span>
                        <span
                            v-else-if="streamStatus === 'unavailable'"
                            class="text-xs text-muted-foreground"
                            data-test="fix-job-stream-unavailable"
                        >
                            Live updates unavailable — showing what was
                            recorded.
                        </span>
                    </div>

                    <div class="flex min-w-0 items-center gap-2">
                        <span
                            v-if="isCustom"
                            class="shrink-0 rounded-sm border px-1.5 py-0.5 text-xs font-medium tracking-[0.06em] text-muted-foreground uppercase"
                            data-test="fix-job-custom-marker"
                        >
                            {{ job.typeLabel }}
                        </span>
                        <h1
                            class="truncate text-xl font-semibold tracking-tight"
                        >
                            {{ job.title }}
                        </h1>
                    </div>
                    <p
                        v-if="!isCustom && job.message"
                        class="max-w-prose text-sm text-muted-foreground"
                    >
                        {{ job.message }}
                    </p>
                </div>

                <div class="flex shrink-0 flex-wrap items-center gap-2">
                    <Button
                        v-if="!isCustom"
                        variant="outline"
                        size="sm"
                        as-child
                    >
                        <Link :href="logsHref" data-test="fix-job-logs-link">
                            <ScrollText /> View in logs
                        </Link>
                    </Button>

                    <Button v-if="job.prUrl" size="sm" as-child>
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

                    <Form
                        v-if="canCancel"
                        v-bind="cancelJob.form([teamSlug, job.uuid])"
                        v-slot="{ processing }"
                    >
                        <Button
                            type="submit"
                            variant="outline"
                            size="sm"
                            :disabled="processing"
                            data-test="fix-job-cancel"
                        >
                            <XCircle /> Cancel
                        </Button>
                    </Form>
                </div>
            </div>

            <dl
                class="grid grid-cols-2 gap-x-6 gap-y-3 rounded-xl border bg-card p-4 sm:grid-cols-3 lg:grid-cols-6"
            >
                <div v-for="stat in stats" :key="stat.label" class="min-w-0">
                    <dt
                        class="text-[11px] font-medium tracking-[0.08em] text-muted-foreground uppercase"
                    >
                        {{ stat.label }}
                    </dt>
                    <dd class="truncate font-mono text-sm">{{ stat.value }}</dd>
                </div>
            </dl>

            <!--
              What was asked for, in the words it was asked in. Long requests
              fold, because the timeline underneath is the reason to be here.
            -->
            <Collapsible
                v-if="isCustom && instructions"
                v-model:open="expanded"
                class="rounded-xl border bg-card p-4"
                data-test="fix-job-instructions"
            >
                <div class="flex items-baseline justify-between gap-3">
                    <p
                        class="text-xs font-medium tracking-[0.06em] text-muted-foreground uppercase"
                    >
                        Requested
                    </p>

                    <CollapsibleTrigger
                        v-if="foldable"
                        class="inline-flex shrink-0 items-center gap-1 text-xs text-muted-foreground underline-offset-4 hover:underline"
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
                    class="mt-1.5 text-sm whitespace-pre-wrap text-foreground"
                    :class="foldable ? 'line-clamp-3' : undefined"
                >
                    {{ instructions }}
                </p>

                <CollapsibleContent v-if="foldable">
                    <p
                        class="mt-1.5 text-sm whitespace-pre-wrap text-foreground"
                    >
                        {{ instructions }}
                    </p>
                </CollapsibleContent>
            </Collapsible>
        </header>

        <p
            v-if="job.failureReason"
            class="rounded-lg border border-severity-error/40 bg-card p-3 text-sm text-severity-error"
            data-test="fix-job-failure"
        >
            {{ job.failureReason }}
        </p>

        <div class="grid gap-4 lg:grid-cols-2">
            <Card class="min-w-0">
                <CardHeader>
                    <CardTitle>Session</CardTitle>
                    <CardDescription>
                        What the agent did, in order. The transcript is stored
                        with the job, so it reads the same after the run ends.
                    </CardDescription>
                </CardHeader>

                <CardContent>
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
                        class="py-8 text-center text-sm text-muted-foreground"
                        data-test="fix-job-timeline-empty"
                    >
                        No session events recorded yet.
                    </p>
                </CardContent>
            </Card>

            <div class="flex min-w-0 flex-col gap-4">
                <Card class="min-w-0">
                    <CardHeader>
                        <CardTitle>Proposed change</CardTitle>
                        <CardDescription>
                            The validated diff, against
                            <code class="font-mono">{{
                                job.defaultBranch
                            }}</code
                            >. Machine-generated — read it the way you would
                            read a stranger's patch.
                        </CardDescription>
                    </CardHeader>

                    <CardContent>
                        <CodeCanvas
                            v-if="job.diff"
                            :patch="job.diff"
                            data-test="fix-job-diff"
                        />
                        <div
                            v-else-if="job.status === 'no_change'"
                            class="flex flex-col items-center gap-3 py-10 text-center"
                            data-test="fix-job-no-change"
                        >
                            <span
                                class="flex size-12 items-center justify-center rounded-full border bg-card text-muted-foreground"
                            >
                                <FileCheck2 class="size-5" />
                            </span>
                            <div class="space-y-1">
                                <p class="text-sm font-medium">
                                    No change needed
                                </p>
                                <p
                                    class="mx-auto max-w-sm text-sm text-muted-foreground"
                                >
                                    The agent finished without touching the
                                    tree. Its reasoning is in the session
                                    transcript.
                                </p>
                            </div>
                        </div>
                        <p
                            v-else
                            class="py-6 text-center text-sm text-muted-foreground"
                            data-test="fix-job-diff-empty"
                        >
                            No diff has come back for this job yet.
                        </p>
                    </CardContent>
                </Card>

                <Card v-if="job.stack" class="min-w-0">
                    <CardHeader>
                        <CardTitle>Stack trace</CardTitle>
                        <CardDescription>
                            The trace the fingerprint was cut from — what the
                            agent was given to work with.
                        </CardDescription>
                    </CardHeader>

                    <CardContent>
                        <CodeCanvas
                            :code="job.stack"
                            filename="stack.log"
                            max-height="20rem"
                            hide-header
                            data-test="fix-job-stack"
                        />
                    </CardContent>
                </Card>

                <!--
                  The trace as the agent read it: the same bounded text the
                  prompt carried, so a reviewer sees exactly what the model
                  saw rather than a richer view of it.
                -->
                <Card v-if="trace" class="min-w-0" data-test="fix-job-trace">
                    <CardHeader>
                        <div
                            class="flex flex-wrap items-start justify-between gap-3"
                        >
                            <div class="min-w-0 space-y-1.5">
                                <CardTitle>Trace</CardTitle>
                                <CardDescription>
                                    <template v-if="trace.state === 'rendered'">
                                        The waterfall the agent was handed —
                                        {{ trace.rendered_spans }} of
                                        {{ trace.span_count }} spans,
                                        {{ trace.error_count }} with Error
                                        status. <code>&gt;&gt;</code> marks the
                                        span that emitted the log line,
                                        <code>!!</code> a span that failed.
                                    </template>
                                    <template v-else>{{
                                        traceNotice
                                    }}</template>
                                </CardDescription>
                            </div>

                            <Button
                                v-if="traceHref && trace.state !== 'missing'"
                                variant="outline"
                                size="sm"
                                as-child
                            >
                                <Link
                                    :href="traceHref"
                                    data-test="fix-job-trace-link"
                                >
                                    <Waypoints /> Open trace
                                </Link>
                            </Button>
                        </div>
                    </CardHeader>

                    <CardContent v-if="trace.waterfall">
                        <CodeCanvas
                            :code="trace.waterfall"
                            filename="trace.txt"
                            max-height="24rem"
                            hide-header
                            data-test="fix-job-trace-waterfall"
                        />
                    </CardContent>
                </Card>
            </div>
        </div>
    </div>
</template>
