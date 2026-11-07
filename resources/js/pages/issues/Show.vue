<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ChevronFirst,
    ChevronLast,
    ChevronLeft,
    ChevronRight,
    ScrollText,
    Waypoints,
    Wrench,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import AutofixUpsellModal from '@/components/AutofixUpsellModal.vue';
import BreadcrumbList from '@/components/BreadcrumbList.vue';
import IssueHistogram from '@/components/IssueHistogram.vue';
import IssueStatusBadge from '@/components/IssueStatusBadge.vue';
import IssueTagBars from '@/components/IssueTagBars.vue';
import RunAutofixModal from '@/components/RunAutofixModal.vue';
import StackTrace from '@/components/StackTrace.vue';
import TraceFact from '@/components/TraceFact.vue';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import {
    contextSections,
    DECISION_LABEL,
    eventBreadcrumbs,
    eventCauses,
    eventFrames,
    eventTags,
    formatCount,
    isOpen,
    isoFromMs,
    issueHref,
    remainingAttributes,
} from '@/lib/issues';
import { autofixTargetFor } from '@/lib/logRow';
import {
    formatRelativeTime,
    formatTimestamp,
    parseTimestamp,
} from '@/lib/logs';
import { traceHref } from '@/lib/traces';
import { cn } from '@/lib/utils';
import { index as issuesIndex, update as issueUpdate } from '@/routes/issues';
import { index as logsIndex } from '@/routes/logs';
import type {
    IssueDecision,
    IssueDetail,
    IssueEvent,
    IssueEventList,
    IssueException,
    IssueHistograms,
    IssueTagResult,
    LogAutofixState,
    Team,
} from '@/types';

const props = defineProps<{
    issue: IssueDetail;
    event: IssueEvent;
    exception: IssueException | null;
    autofix: LogAutofixState;
    /** Deferred: events per hour (24h) and per day (30d). */
    histogram?: IssueHistograms;
    /** Deferred with the recent events: top values per tag. */
    tags?: IssueTagResult;
    recentEvents?: IssueEventList;
}>();

defineOptions({
    layout: (layoutProps: { currentTeam?: Team | null }) => ({
        breadcrumbs: [
            {
                title: 'Issues',
                href: layoutProps.currentTeam
                    ? issuesIndex(layoutProps.currentTeam.slug)
                    : '/',
            },
            { title: 'Issue', href: '#' },
        ],
    }),
});

const page = usePage();

const teamSlug = computed(() => page.props.currentTeam?.slug ?? '');

const row = computed(() => props.event.row);

const title = computed(
    () => props.exception?.type || props.issue.type || 'Unknown exception',
);

const message = computed(() => props.exception?.message || props.issue.message);

const stacktrace = computed(() => props.exception?.stacktrace ?? '');

const frames = computed(() => eventFrames(row.value, stacktrace.value));

const causes = computed(() => eventCauses(row.value, stacktrace.value));

const breadcrumbs = computed(() => eventBreadcrumbs(row.value));

const sections = computed(() => contextSections(row.value));

const tagsOnEvent = computed(() => eventTags(row.value));

const attributes = computed(() => remainingAttributes(row.value));

const handled = computed(
    () => row.value?.logAttributes['exception.handled'] ?? null,
);

const mechanism = computed(
    () => row.value?.logAttributes['exception.mechanism'] ?? null,
);

/*
 * Triage. A PATCH that comes back to this page; preserveScroll so resolving
 * an issue from halfway down its stack does not throw the reader to the top.
 */
const deciding = ref<IssueDecision | null>(null);

function decide(status: IssueDecision) {
    deciding.value = status;

    router.patch(
        issueUpdate({
            current_team: teamSlug.value,
            project: props.issue.projectSlug,
            fingerprint: props.issue.fingerprint,
        }).url,
        { status },
        {
            preserveScroll: true,
            // Only the status changed: the charts, tags and events stay.
            only: ['issue'],
            onFinish: () => {
                deciding.value = null;
            },
        },
    );
}

const target = computed(() =>
    row.value ? autofixTargetFor(row.value, props.autofix) : null,
);

/*
 * Event navigation: every step is a link, so an event is shareable. Stepping
 * only changes the event, so only the event is reloaded — the histogram, the
 * tag breakdown and the recent events describe the issue, not this event,
 * and re-reading them on every step is what made stepping expensive.
 */
const EVENT_PROPS = ['event', 'exception'];

const eventLink = (
    position: 'latest' | 'oldest' | 'older' | 'newer' | 'at',
    event?: string,
    eventKey?: string,
) =>
    issueHref(
        teamSlug.value,
        props.issue.projectSlug,
        props.issue.fingerprint,
        {
            position,
            event: event ?? row.value?.timestamp ?? null,
            eventKey:
                event === undefined ? props.event.eventKey : (eventKey ?? null),
        },
    );

const latestHref = computed(() =>
    issueHref(teamSlug.value, props.issue.projectSlug, props.issue.fingerprint),
);

/**
 * The log stream around this event: the same service, a minute either side,
 * so the lines that led up to the exception are on screen.
 */
const logsHref = computed(() => {
    if (!row.value) {
        return null;
    }

    const at = parseTimestamp(row.value.timestamp).getTime();

    return logsIndex(teamSlug.value, {
        query: {
            project: props.issue.projectSlug,
            service: row.value.serviceName || undefined,
            from: new Date(at - 60_000).toISOString(),
            to: new Date(at + 60_000).toISOString(),
        },
    }).url;
});

const traceLink = computed(() =>
    row.value?.traceId
        ? traceHref(teamSlug.value, row.value.traceId, {
              ts: row.value.timestamp,
              span: row.value.spanId || null,
          })
        : null,
);

const lastSeen = computed(() =>
    formatRelativeTime(isoFromMs(props.issue.lastSeen)),
);

const firstSeen = computed(() =>
    formatRelativeTime(isoFromMs(props.issue.firstSeen)),
);
</script>

<template>
    <Head :title="title" />

    <div class="flex flex-1 flex-col gap-4 overflow-y-auto p-4">
        <!-- What broke, and what to do about it. -->
        <section
            class="flex flex-col gap-4 rounded-lg border bg-card p-4"
            data-test="issue-header"
        >
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex min-w-0 flex-col gap-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <h1
                            class="min-w-0 font-mono text-xl font-semibold break-all"
                        >
                            {{ title }}
                        </h1>
                        <IssueStatusBadge :status="issue.status" />
                        <span
                            v-if="handled === 'false'"
                            class="rounded-full border border-severity-error/40 px-2 py-0.5 text-[11px] font-medium text-severity-error"
                            :title="
                                mechanism ? `Caught by ${mechanism}` : undefined
                            "
                        >
                            Unhandled
                        </span>
                    </div>
                    <p
                        v-if="message"
                        class="text-sm break-words whitespace-pre-wrap"
                    >
                        {{ message }}
                    </p>
                    <p
                        class="flex min-w-0 flex-wrap items-center gap-1.5 text-xs text-muted-foreground"
                    >
                        <span>{{ issue.projectName }}</span>
                        <span aria-hidden="true">·</span>
                        <span>{{
                            issue.services.join(', ') || issue.service
                        }}</span>
                        <template v-if="issue.origin">
                            <span aria-hidden="true">·</span>
                            <span
                                class="truncate font-mono"
                                :title="issue.origin"
                                >{{ issue.origin }}</span
                            >
                        </template>
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <template v-if="isOpen(issue.status)">
                        <Button
                            type="button"
                            size="sm"
                            :disabled="deciding !== null"
                            data-test="issue-resolve"
                            @click="decide('resolved')"
                        >
                            {{ DECISION_LABEL.resolved }}
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            :disabled="deciding !== null"
                            data-test="issue-ignore"
                            @click="decide('ignored')"
                        >
                            {{ DECISION_LABEL.ignored }}
                        </Button>
                    </template>
                    <Button
                        v-else
                        type="button"
                        size="sm"
                        variant="outline"
                        :disabled="deciding !== null"
                        data-test="issue-reopen"
                        @click="decide('unresolved')"
                    >
                        {{ DECISION_LABEL.unresolved }}
                    </Button>

                    <template v-if="row && target">
                        <RunAutofixModal
                            v-if="target.repository"
                            :entry="row"
                            :team-slug="teamSlug"
                            :repository="target.repository"
                            :credentials="autofix.credentials"
                        >
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                data-test="issue-autofix"
                            >
                                <Wrench class="size-3.5" /> Fix with Autofix
                            </Button>
                        </RunAutofixModal>
                        <AutofixUpsellModal
                            v-else
                            :entry="row"
                            :team-slug="teamSlug"
                            :project-slug="target.project?.slug ?? null"
                            :project-name="target.project?.name ?? null"
                        >
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                data-test="issue-autofix-upsell"
                            >
                                <Wrench class="size-3.5" /> Fix with Autofix
                            </Button>
                        </AutofixUpsellModal>
                    </template>
                </div>
            </div>

            <p
                v-if="issue.status === 'regressed' && issue.resolvedAt"
                class="text-xs text-severity-error"
                data-test="issue-regressed-note"
            >
                Resolved {{ formatRelativeTime(issue.resolvedAt)
                }}<template v-if="issue.statusChangedBy">
                    by {{ issue.statusChangedBy }}</template
                >, and seen again {{ lastSeen }}.
            </p>
            <p
                v-else-if="
                    issue.statusChangedBy &&
                    issue.statusChangedAt &&
                    issue.status !== 'unresolved'
                "
                class="text-xs text-muted-foreground"
            >
                Marked {{ issue.status }} by {{ issue.statusChangedBy }}
                {{ formatRelativeTime(issue.statusChangedAt) }}.
            </p>

            <dl
                class="grid grid-cols-2 gap-x-6 gap-y-3 border-t pt-4 sm:grid-cols-3 lg:grid-cols-5"
            >
                <TraceFact
                    label="Events"
                    :value="formatCount(issue.events)"
                    :detail="issue.events.toLocaleString()"
                    mono
                />
                <TraceFact
                    label="Users"
                    :value="formatCount(issue.users)"
                    mono
                />
                <TraceFact
                    label="First seen"
                    :value="firstSeen"
                    :detail="
                        issue.firstRelease
                            ? `in ${issue.firstRelease}`
                            : undefined
                    "
                />
                <TraceFact
                    label="Last seen"
                    :value="lastSeen"
                    :detail="
                        issue.lastRelease
                            ? `in ${issue.lastRelease}`
                            : undefined
                    "
                />
                <TraceFact
                    label="Fingerprint"
                    :value="issue.fingerprint.slice(0, 12)"
                    :detail="issue.environments.join(', ') || undefined"
                    mono
                    copyable
                />
            </dl>
        </section>

        <div class="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr)_20rem]">
            <div class="flex min-w-0 flex-col gap-4">
                <!-- Which occurrence this is, and how to step through the others. -->
                <section
                    class="flex flex-wrap items-center justify-between gap-3 rounded-lg border bg-card px-4 py-3"
                    data-test="issue-event-nav"
                >
                    <div class="flex min-w-0 flex-col text-xs">
                        <span class="text-muted-foreground">Event</span>
                        <span v-if="row" class="font-mono">
                            {{ formatTimestamp(row.timestamp) }}
                            <span
                                v-if="row.logAttributes['event.id']"
                                class="text-muted-foreground"
                            >
                                ·
                                {{ row.logAttributes['event.id'].slice(0, 12) }}
                            </span>
                        </span>
                        <span v-else class="text-muted-foreground">—</span>
                    </div>

                    <div class="flex flex-wrap items-center gap-1">
                        <Button
                            v-if="logsHref"
                            as-child
                            size="sm"
                            variant="ghost"
                        >
                            <Link :href="logsHref" data-test="issue-event-logs">
                                <ScrollText class="size-3.5" /> Logs around it
                            </Link>
                        </Button>
                        <Button
                            v-if="traceLink"
                            as-child
                            size="sm"
                            variant="ghost"
                        >
                            <Link
                                :href="traceLink"
                                data-test="issue-event-trace"
                            >
                                <Waypoints class="size-3.5" /> Trace
                            </Link>
                        </Button>

                        <div
                            class="ml-1 inline-flex items-center rounded-md border"
                        >
                            <component
                                :is="event.hasOlder ? Link : 'span'"
                                v-for="step in [
                                    {
                                        position: 'oldest',
                                        icon: ChevronFirst,
                                        label: 'Oldest event',
                                        enabled: event.hasOlder,
                                    },
                                    {
                                        position: 'older',
                                        icon: ChevronLeft,
                                        label: 'Older event',
                                        enabled: event.hasOlder,
                                    },
                                ] as const"
                                :key="step.position"
                                v-bind="
                                    step.enabled
                                        ? {
                                              href: eventLink(step.position),
                                              preserveScroll: true,
                                              only: EVENT_PROPS,
                                          }
                                        : {}
                                "
                                :class="
                                    cn(
                                        'inline-flex size-8 items-center justify-center',
                                        step.enabled
                                            ? 'text-foreground hover:bg-accent'
                                            : 'text-muted-foreground/40',
                                    )
                                "
                                :aria-label="step.label"
                                :title="step.label"
                                :data-test="`issue-event-${step.position}`"
                            >
                                <component :is="step.icon" class="size-4" />
                            </component>
                            <component
                                :is="event.hasNewer ? Link : 'span'"
                                v-for="step in [
                                    {
                                        position: 'newer',
                                        icon: ChevronRight,
                                        label: 'Newer event',
                                        href: eventLink('newer'),
                                    },
                                    {
                                        position: 'latest',
                                        icon: ChevronLast,
                                        label: 'Latest event',
                                        href: latestHref,
                                    },
                                ] as const"
                                :key="step.position"
                                v-bind="
                                    event.hasNewer
                                        ? {
                                              href: step.href,
                                              preserveScroll: true,
                                              only: EVENT_PROPS,
                                          }
                                        : {}
                                "
                                :class="
                                    cn(
                                        'inline-flex size-8 items-center justify-center',
                                        event.hasNewer
                                            ? 'text-foreground hover:bg-accent'
                                            : 'text-muted-foreground/40',
                                    )
                                "
                                :aria-label="step.label"
                                :title="step.label"
                                :data-test="`issue-event-${step.position}`"
                            >
                                <component :is="step.icon" class="size-4" />
                            </component>
                        </div>
                    </div>
                </section>

                <p
                    v-if="event.unavailable"
                    class="rounded-lg border bg-card p-4 text-sm text-muted-foreground"
                >
                    Event storage is busy and could not answer in time — retry
                    in a moment.
                </p>
                <p
                    v-else-if="event.expired"
                    class="rounded-lg border bg-card p-4 text-sm text-muted-foreground"
                    data-test="issue-events-expired"
                >
                    This issue's events are older than the 30 days events are
                    kept, so there is no stack trace or context to show. Its
                    summary above — type, message, origin, counts and releases —
                    is kept for as long as the issue exists.
                </p>

                <template v-if="row">
                    <section
                        class="flex flex-col gap-3 rounded-lg border bg-card p-4"
                        data-test="issue-exception"
                    >
                        <h2 class="text-sm font-semibold">Stack trace</h2>
                        <StackTrace :frames="frames" :raw="stacktrace" />

                        <div
                            v-for="(cause, index) in causes"
                            :key="index"
                            class="flex flex-col gap-2 border-t pt-3"
                            data-test="issue-cause"
                        >
                            <p class="text-xs text-muted-foreground">
                                Caused by
                            </p>
                            <p class="font-mono text-sm font-medium">
                                {{ cause.type || 'Exception' }}
                            </p>
                            <p v-if="cause.message" class="text-sm break-words">
                                {{ cause.message }}
                            </p>
                            <p
                                v-if="cause.origin"
                                class="font-mono text-xs text-muted-foreground"
                            >
                                {{ cause.origin }}
                            </p>
                            <pre
                                v-if="cause.stacktrace"
                                class="max-h-48 overflow-auto rounded-lg border bg-muted/40 p-3 font-mono text-xs whitespace-pre"
                                >{{ cause.stacktrace }}</pre>
                        </div>
                    </section>

                    <section
                        class="flex flex-col gap-3 rounded-lg border bg-card p-4"
                    >
                        <h2 class="text-sm font-semibold">Breadcrumbs</h2>
                        <BreadcrumbList :breadcrumbs="breadcrumbs" />
                    </section>

                    <section
                        v-if="sections.length > 0 || tagsOnEvent.length > 0"
                        class="grid grid-cols-1 gap-x-6 gap-y-4 rounded-lg border bg-card p-4 md:grid-cols-2"
                        data-test="issue-context"
                    >
                        <div
                            v-for="section in sections"
                            :key="section.title"
                            class="min-w-0"
                        >
                            <h2 class="mb-2 text-sm font-semibold">
                                {{ section.title }}
                            </h2>
                            <dl class="flex flex-col gap-1 text-xs">
                                <div
                                    v-for="[key, value] in section.values"
                                    :key="key"
                                    class="grid grid-cols-[9rem_minmax(0,1fr)] gap-2"
                                >
                                    <dt
                                        class="truncate text-muted-foreground"
                                        :title="key"
                                    >
                                        {{ key }}
                                    </dt>
                                    <dd class="font-mono break-all">
                                        {{ value }}
                                    </dd>
                                </div>
                            </dl>
                        </div>
                        <div v-if="tagsOnEvent.length > 0" class="min-w-0">
                            <h2 class="mb-2 text-sm font-semibold">Tags</h2>
                            <div class="flex flex-wrap gap-1.5">
                                <span
                                    v-for="[key, value] in tagsOnEvent"
                                    :key="key"
                                    class="inline-flex max-w-full items-center overflow-hidden rounded-md border text-xs"
                                >
                                    <span
                                        class="bg-muted px-1.5 py-0.5 text-muted-foreground"
                                        >{{ key }}</span
                                    >
                                    <span
                                        class="truncate px-1.5 py-0.5 font-mono"
                                        >{{ value }}</span
                                    >
                                </span>
                            </div>
                        </div>
                    </section>

                    <section
                        v-if="attributes.length > 0"
                        class="rounded-lg border bg-card p-4"
                    >
                        <details>
                            <summary
                                class="cursor-pointer text-sm font-semibold"
                            >
                                All attributes
                                <span
                                    class="font-normal text-muted-foreground"
                                    >{{ attributes.length }}</span
                                >
                            </summary>
                            <dl class="mt-3 flex flex-col gap-1 text-xs">
                                <div
                                    v-for="[key, value] in attributes"
                                    :key="key"
                                    class="grid grid-cols-[12rem_minmax(0,1fr)] gap-2"
                                >
                                    <dt
                                        class="truncate text-muted-foreground"
                                        :title="key"
                                    >
                                        {{ key }}
                                    </dt>
                                    <dd
                                        class="font-mono break-all whitespace-pre-wrap"
                                    >
                                        {{ value }}
                                    </dd>
                                </div>
                            </dl>
                        </details>
                    </section>
                </template>

                <!-- The most recent occurrences, each one a link to itself. -->
                <section
                    class="flex flex-col rounded-lg border bg-card"
                    data-test="issue-recent-events"
                >
                    <h2 class="border-b px-4 py-3 text-sm font-semibold">
                        Recent events
                    </h2>
                    <div v-if="!recentEvents" class="flex flex-col gap-2 p-4">
                        <Skeleton
                            v-for="index in 4"
                            :key="index"
                            class="h-8 w-full"
                        />
                    </div>
                    <p
                        v-else-if="recentEvents.unavailable"
                        class="p-4 text-xs text-muted-foreground"
                    >
                        Events are busy — retry in a moment.
                    </p>
                    <p
                        v-else-if="recentEvents.rows.length === 0"
                        class="p-4 text-xs text-muted-foreground"
                    >
                        No events within the last 30 days.
                    </p>
                    <div v-else class="overflow-x-auto">
                        <table class="w-full text-xs">
                            <thead class="text-left text-muted-foreground">
                                <tr class="border-b">
                                    <th class="px-4 py-2 font-normal">Time</th>
                                    <th class="px-2 py-2 font-normal">
                                        Service
                                    </th>
                                    <th class="px-2 py-2 font-normal">
                                        Release
                                    </th>
                                    <th class="px-2 py-2 font-normal">
                                        Environment
                                    </th>
                                    <th class="px-2 py-2 font-normal">User</th>
                                    <th class="px-4 py-2 font-normal">
                                        Message
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="summary in recentEvents.rows"
                                    :key="`${summary.timestamp}:${summary.eventKey}`"
                                    :class="
                                        cn(
                                            'border-b last:border-b-0',
                                            summary.timestamp ===
                                                row?.timestamp &&
                                                summary.eventKey ===
                                                    event.eventKey &&
                                                'bg-accent/60',
                                        )
                                    "
                                >
                                    <td
                                        class="px-4 py-1.5 font-mono whitespace-nowrap"
                                    >
                                        <Link
                                            :href="
                                                eventLink(
                                                    'at',
                                                    summary.timestamp,
                                                    summary.eventKey,
                                                )
                                            "
                                            :only="EVENT_PROPS"
                                            preserve-scroll
                                            class="underline-offset-4 hover:underline"
                                        >
                                            {{
                                                formatTimestamp(
                                                    summary.timestamp,
                                                )
                                            }}
                                        </Link>
                                    </td>
                                    <td class="px-2 py-1.5">
                                        {{ summary.serviceName || '—' }}
                                    </td>
                                    <td class="px-2 py-1.5 font-mono">
                                        {{ summary.release || '—' }}
                                    </td>
                                    <td class="px-2 py-1.5">
                                        {{ summary.environment || '—' }}
                                    </td>
                                    <td
                                        class="max-w-40 truncate px-2 py-1.5 font-mono"
                                        :title="summary.user"
                                    >
                                        {{ summary.user || '—' }}
                                    </td>
                                    <td
                                        class="max-w-80 truncate px-4 py-1.5"
                                        :title="summary.message"
                                    >
                                        {{ summary.message }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <aside class="flex min-w-0 flex-col gap-4">
                <IssueHistogram :histogram="histogram" />

                <section
                    class="flex flex-col gap-3 rounded-lg border bg-card p-4"
                >
                    <h2 class="text-sm font-semibold">Tags</h2>
                    <IssueTagBars :tags="tags" />
                </section>
            </aside>
        </div>
    </div>
</template>
