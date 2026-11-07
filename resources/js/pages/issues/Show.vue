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
import PageHeader from '@/components/PageHeader.vue';
import RunAutofixModal from '@/components/RunAutofixModal.vue';
import StackTrace from '@/components/StackTrace.vue';
import TraceFact from '@/components/TraceFact.vue';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import {
    contextSections,
    DECISION_LABEL,
    displayLocation,
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
    /*
     * Issues / project / this issue. The page header prints every crumb but
     * the last as its eyebrow, so the way back is the list and the list
     * narrowed to this issue's project.
     */
    layout: (layoutProps: {
        currentTeam?: Team | null;
        issue?: IssueDetail;
    }) => ({
        breadcrumbs: [
            {
                title: 'Issues',
                href: layoutProps.currentTeam
                    ? issuesIndex(layoutProps.currentTeam.slug)
                    : '/',
            },
            ...(layoutProps.issue
                ? [
                      {
                          title: layoutProps.issue.projectName,
                          href: layoutProps.currentTeam
                              ? issuesIndex(layoutProps.currentTeam.slug, {
                                    query: {
                                        project: layoutProps.issue.projectSlug,
                                    },
                                })
                              : '/',
                      },
                  ]
                : []),
            {
                title: layoutProps.issue?.type || 'Issue',
                href: '#',
            },
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

/** A section heading on the surface: type over a hairline, a note at right. */
const SECTION_HEADER =
    'flex items-baseline justify-between gap-3 border-b pb-2.5';
const SECTION_TITLE = 'text-base font-semibold tracking-tight';
const LABEL = 'eyebrow';

/** The event stepper's segments: a link when there is somewhere to go. */
const STEP =
    'inline-flex size-[26px] items-center justify-center rounded-[5px] transition-colors';
const STEP_ON = 'text-foreground hover:bg-card hover:ring-1 hover:ring-border';
const STEP_OFF = 'text-muted-foreground/40';
</script>

<template>
    <Head :title="title" />

    <div class="flex flex-1 flex-col">
        <!-- What broke, how often, and what to do about it. -->
        <div data-test="issue-header">
            <PageHeader :title="title">
                <template #title>
                    <span class="flex min-w-0 items-center gap-3">
                        <span class="truncate font-mono" :title="title">{{
                            title
                        }}</span>
                        <IssueStatusBadge :status="issue.status" />
                        <span
                            v-if="handled === 'false'"
                            class="inline-flex h-5 shrink-0 items-center rounded-full bg-severity-error/10 px-2 font-sans text-[11px] font-medium tracking-normal text-severity-error"
                            :title="
                                mechanism ? `Caught by ${mechanism}` : undefined
                            "
                            data-test="issue-unhandled"
                        >
                            Unhandled
                        </span>
                    </span>
                </template>

                <div class="flex max-w-4xl flex-col gap-1.5">
                    <p
                        v-if="message"
                        class="break-words whitespace-pre-wrap text-foreground/80"
                        data-test="issue-message"
                    >
                        {{ message }}
                    </p>
                    <p data-test="issue-summary">
                        <span class="font-medium text-foreground"
                            >{{ formatCount(issue.events) }}
                            {{ issue.events === 1 ? 'event' : 'events' }}</span
                        >
                        <template v-if="issue.users > 0">
                            · {{ formatCount(issue.users) }}
                            {{ issue.users === 1 ? 'user' : 'users' }}
                        </template>
                        · first seen {{ firstSeen
                        }}<template v-if="issue.firstRelease">
                            in
                            <span class="font-mono">{{
                                issue.firstRelease
                            }}</span></template
                        >
                        · last seen {{ lastSeen
                        }}<template v-if="issue.lastRelease">
                            in
                            <span class="font-mono">{{
                                issue.lastRelease
                            }}</span></template
                        >
                    </p>
                    <p
                        v-if="issue.status === 'regressed' && issue.resolvedAt"
                        data-test="issue-regressed-note"
                    >
                        Resolved {{ formatRelativeTime(issue.resolvedAt)
                        }}<template v-if="issue.statusChangedBy">
                            by {{ issue.statusChangedBy }}</template
                        >, and
                        <span class="font-medium text-severity-error"
                            >seen again {{ lastSeen }}</span
                        >.
                    </p>
                    <p
                        v-else-if="
                            issue.statusChangedBy &&
                            issue.statusChangedAt &&
                            issue.status !== 'unresolved'
                        "
                    >
                        Marked {{ issue.status }} by
                        {{ issue.statusChangedBy }}
                        {{ formatRelativeTime(issue.statusChangedAt) }}.
                    </p>
                </div>

                <template #actions>
                    <template v-if="isOpen(issue.status)">
                        <Button
                            type="button"
                            :disabled="deciding !== null"
                            data-test="issue-resolve"
                            @click="decide('resolved')"
                        >
                            {{ DECISION_LABEL.resolved }}
                        </Button>
                        <Button
                            type="button"
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
                                variant="outline"
                                data-test="issue-autofix-upsell"
                            >
                                <Wrench class="size-3.5" /> Fix with Autofix
                            </Button>
                        </AutofixUpsellModal>
                    </template>
                </template>
            </PageHeader>

            <!-- Where it lives: one hairline-bounded strip of facts. -->
            <dl
                class="grid grid-cols-2 gap-x-8 gap-y-4 border-y px-4 py-4 sm:grid-cols-3 md:px-10 lg:grid-cols-5"
                data-test="issue-facts"
            >
                <TraceFact label="Project" :value="issue.projectName" />
                <TraceFact
                    label="Service"
                    :value="issue.services.join(', ') || issue.service || '—'"
                    mono
                />
                <TraceFact
                    label="Origin"
                    :value="issue.origin ? displayLocation(issue.origin) : '—'"
                    mono
                />
                <TraceFact
                    label="Environments"
                    :value="issue.environments.join(', ') || '—'"
                    mono
                />
                <TraceFact
                    label="Fingerprint"
                    :value="issue.fingerprint.slice(0, 12)"
                    mono
                    copyable
                />
            </dl>
        </div>

        <!-- How often: the issue's own volume, by the hour or the day. -->
        <div class="px-4 py-5 md:px-10">
            <IssueHistogram :histogram="histogram" />
        </div>

        <div
            class="grid flex-1 grid-cols-1 border-t xl:grid-cols-[minmax(0,1fr)_22rem]"
        >
            <div class="flex min-w-0 flex-col pb-10">
                <!-- Which occurrence this is, and how to step through the others. -->
                <div
                    class="flex flex-wrap items-center justify-between gap-3 border-b bg-muted/40 px-4 py-2.5 md:px-10"
                    data-test="issue-event-nav"
                >
                    <p class="min-w-0 truncate text-xs text-muted-foreground">
                        <span class="font-medium text-foreground">Event</span>
                        <template v-if="row">
                            ·
                            <span class="font-mono text-foreground/80">{{
                                formatTimestamp(row.timestamp)
                            }}</span>
                            <template v-if="row.logAttributes['event.id']">
                                ·
                                <span class="font-mono">{{
                                    row.logAttributes['event.id'].slice(0, 12)
                                }}</span>
                            </template>
                        </template>
                        <template v-else> · —</template>
                    </p>

                    <div class="flex flex-wrap items-center gap-1">
                        <Button
                            v-if="logsHref"
                            as-child
                            size="sm"
                            variant="ghost"
                            class="text-muted-foreground"
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
                            class="text-muted-foreground"
                        >
                            <Link
                                :href="traceLink"
                                data-test="issue-event-trace"
                            >
                                <Waypoints class="size-3.5" /> Trace
                            </Link>
                        </Button>

                        <div
                            class="ml-1 inline-flex h-8 items-center gap-0.5 rounded-md bg-muted p-[3px]"
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
                                    cn(STEP, step.enabled ? STEP_ON : STEP_OFF)
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
                                        STEP,
                                        event.hasNewer ? STEP_ON : STEP_OFF,
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
                </div>

                <p
                    v-if="event.unavailable"
                    class="px-4 py-8 text-sm text-muted-foreground md:px-10"
                >
                    Event storage is busy and could not answer in time — retry
                    in a moment.
                </p>
                <div
                    v-else-if="event.expired"
                    class="flex flex-col gap-2 px-4 py-8 md:px-10"
                    data-test="issue-events-expired"
                >
                    <p class="text-sm font-medium text-foreground">
                        These events have expired
                    </p>
                    <p class="max-w-prose text-sm text-muted-foreground">
                        This issue's events are older than the 30 days events
                        are kept, so there is no stack trace or context to show.
                        Its summary above — type, message, origin, counts and
                        releases — is kept for as long as the issue exists.
                    </p>
                </div>

                <template v-if="row">
                    <section
                        class="flex flex-col px-4 pt-8 md:px-10"
                        data-test="issue-exception"
                    >
                        <header :class="SECTION_HEADER">
                            <h2 :class="SECTION_TITLE">Stack trace</h2>
                            <span class="text-xs text-muted-foreground"
                                >most recent call first</span
                            >
                        </header>
                        <div class="pt-3">
                            <StackTrace :frames="frames" :raw="stacktrace" />
                        </div>

                        <div
                            v-for="(cause, index) in causes"
                            :key="index"
                            class="mt-6 flex flex-col gap-1.5 border-t pt-4"
                            data-test="issue-cause"
                        >
                            <p :class="LABEL">Caused by</p>
                            <p class="font-mono text-sm font-semibold">
                                {{ cause.type || 'Exception' }}
                            </p>
                            <p
                                v-if="cause.message"
                                class="text-sm break-words text-foreground/80"
                            >
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
                                class="mt-1.5 max-h-48 overflow-auto rounded-lg border bg-muted/40 p-3 font-mono text-xs whitespace-pre"
                                >{{ cause.stacktrace }}</pre>
                        </div>
                    </section>

                    <section class="flex flex-col px-4 pt-10 md:px-10">
                        <header :class="SECTION_HEADER">
                            <h2 :class="SECTION_TITLE">Breadcrumbs</h2>
                            <span
                                v-if="breadcrumbs.length > 0"
                                class="text-xs text-muted-foreground"
                                >{{ breadcrumbs.length }} · most recent
                                first</span
                            >
                        </header>
                        <BreadcrumbList :breadcrumbs="breadcrumbs" />
                    </section>

                    <section
                        v-if="sections.length > 0 || tagsOnEvent.length > 0"
                        class="flex flex-col px-4 pt-10 md:px-10"
                        data-test="issue-context"
                    >
                        <header :class="SECTION_HEADER">
                            <h2 :class="SECTION_TITLE">Context</h2>
                            <span class="text-xs text-muted-foreground"
                                >this event</span
                            >
                        </header>
                        <div
                            class="grid grid-cols-1 gap-x-10 gap-y-6 pt-5 md:grid-cols-2"
                        >
                            <div
                                v-for="section in sections"
                                :key="section.title"
                                class="min-w-0"
                            >
                                <h3 :class="cn(LABEL, 'pb-1')">
                                    {{ section.title }}
                                </h3>
                                <dl class="flex flex-col text-xs">
                                    <div
                                        v-for="[key, value] in section.values"
                                        :key="key"
                                        class="grid grid-cols-[9rem_minmax(0,1fr)] gap-3 border-b border-border/60 py-2"
                                    >
                                        <dt
                                            class="truncate text-muted-foreground"
                                            :title="key"
                                        >
                                            {{ key }}
                                        </dt>
                                        <dd
                                            class="font-mono break-all text-foreground/80"
                                        >
                                            {{ value }}
                                        </dd>
                                    </div>
                                </dl>
                            </div>
                            <div v-if="tagsOnEvent.length > 0" class="min-w-0">
                                <h3 :class="cn(LABEL, 'pb-2')">Tags</h3>
                                <div class="flex flex-wrap gap-1.5">
                                    <span
                                        v-for="[key, value] in tagsOnEvent"
                                        :key="key"
                                        class="inline-flex h-6 max-w-full items-center overflow-hidden rounded-md text-xs ring-1 ring-border ring-inset"
                                    >
                                        <span
                                            class="flex h-full items-center bg-muted px-2 text-muted-foreground"
                                            >{{ key }}</span
                                        >
                                        <span
                                            class="truncate px-2 font-mono text-foreground/80"
                                            >{{ value }}</span
                                        >
                                    </span>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section
                        v-if="attributes.length > 0"
                        class="flex flex-col px-4 pt-10 md:px-10"
                    >
                        <details class="group">
                            <summary
                                :class="
                                    cn(
                                        SECTION_HEADER,
                                        'cursor-pointer list-none [&::-webkit-details-marker]:hidden',
                                    )
                                "
                            >
                                <h2 :class="SECTION_TITLE">All attributes</h2>
                                <span
                                    class="inline-flex items-center gap-1 text-xs text-muted-foreground"
                                >
                                    <span class="font-mono tabular-nums">{{
                                        attributes.length
                                    }}</span>
                                    <ChevronRight
                                        class="size-3.5 transition-transform group-open:rotate-90"
                                    />
                                </span>
                            </summary>
                            <dl class="flex flex-col text-xs">
                                <div
                                    v-for="[key, value] in attributes"
                                    :key="key"
                                    class="grid grid-cols-[12rem_minmax(0,1fr)] gap-3 border-b border-border/60 py-2"
                                >
                                    <dt
                                        class="truncate text-muted-foreground"
                                        :title="key"
                                    >
                                        {{ key }}
                                    </dt>
                                    <dd
                                        class="font-mono break-all whitespace-pre-wrap text-foreground/80"
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
                    class="flex flex-col pt-10"
                    data-test="issue-recent-events"
                >
                    <header
                        class="flex items-baseline justify-between gap-3 px-4 pb-2.5 md:px-10"
                    >
                        <h2 :class="SECTION_TITLE">Recent events</h2>
                        <span
                            v-if="recentEvents && recentEvents.rows.length > 0"
                            class="text-xs text-muted-foreground"
                            >newest {{ recentEvents.rows.length }}</span
                        >
                    </header>
                    <div
                        v-if="!recentEvents"
                        class="flex flex-col gap-2 border-t px-4 py-4 md:px-10"
                    >
                        <Skeleton
                            v-for="index in 4"
                            :key="index"
                            class="h-8 w-full"
                        />
                    </div>
                    <p
                        v-else-if="recentEvents.unavailable"
                        class="border-t px-4 py-8 text-sm text-muted-foreground md:px-10"
                    >
                        Events are busy — retry in a moment.
                    </p>
                    <p
                        v-else-if="recentEvents.rows.length === 0"
                        class="border-t px-4 py-8 text-sm text-muted-foreground md:px-10"
                    >
                        No events within the last 30 days.
                    </p>
                    <div v-else class="overflow-x-auto">
                        <table class="w-full text-xs">
                            <thead
                                class="text-left text-label tracking-label text-muted-foreground uppercase"
                            >
                                <tr class="border-y bg-muted/40">
                                    <th
                                        class="py-2.5 pr-3 pl-4 font-medium md:pl-10"
                                    >
                                        Time
                                    </th>
                                    <th class="px-3 py-2.5 font-medium">
                                        Service
                                    </th>
                                    <th class="px-3 py-2.5 font-medium">
                                        Release
                                    </th>
                                    <th class="px-3 py-2.5 font-medium">
                                        Environment
                                    </th>
                                    <th class="px-3 py-2.5 font-medium">
                                        User
                                    </th>
                                    <th
                                        class="py-2.5 pr-4 pl-3 font-medium md:pr-10"
                                    >
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
                                            'h-11 border-b border-border/60 transition-colors hover:bg-muted/50',
                                            summary.timestamp ===
                                                row?.timestamp &&
                                                summary.eventKey ===
                                                    event.eventKey &&
                                                'bg-muted/70',
                                        )
                                    "
                                >
                                    <td
                                        class="pr-3 pl-4 font-mono whitespace-nowrap md:pl-10"
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
                                            class="font-medium text-foreground underline-offset-4 hover:underline"
                                        >
                                            {{
                                                formatTimestamp(
                                                    summary.timestamp,
                                                )
                                            }}
                                        </Link>
                                    </td>
                                    <td
                                        class="px-3 font-mono whitespace-nowrap text-foreground/80"
                                    >
                                        {{ summary.serviceName || '—' }}
                                    </td>
                                    <td
                                        class="px-3 font-mono whitespace-nowrap text-foreground/80"
                                    >
                                        {{ summary.release || '—' }}
                                    </td>
                                    <td
                                        class="px-3 whitespace-nowrap text-foreground/80"
                                    >
                                        {{ summary.environment || '—' }}
                                    </td>
                                    <td
                                        class="max-w-40 truncate px-3 font-mono text-foreground/80"
                                        :title="summary.user"
                                    >
                                        {{ summary.user || '—' }}
                                    </td>
                                    <td
                                        class="max-w-80 truncate pr-4 pl-3 text-muted-foreground md:pr-10"
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

            <!-- Who and where it happens to, across the newest events. -->
            <aside
                class="flex min-w-0 flex-col border-t px-4 pt-8 pb-10 md:px-10 xl:border-t-0 xl:border-l xl:pl-8"
            >
                <header :class="SECTION_HEADER">
                    <h2 :class="SECTION_TITLE">Tags</h2>
                    <span class="text-xs text-muted-foreground"
                        >share of events</span
                    >
                </header>
                <div class="pt-4">
                    <IssueTagBars :tags="tags" />
                </div>
            </aside>
        </div>
    </div>
</template>
