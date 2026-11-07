<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowRight } from '@lucide/vue';
import { computed } from 'vue';
import BarSparkline from '@/components/BarSparkline.vue';
import GetStartedPanel from '@/components/GetStartedPanel.vue';
import IngestRateCard from '@/components/IngestRateCard.vue';
import PageHeader from '@/components/PageHeader.vue';
import PendingInvitationsModal from '@/components/PendingInvitationsModal.vue';
import PlanUsageCard from '@/components/PlanUsageCard.vue';
import SectionHeader from '@/components/SectionHeader.vue';
import { Button } from '@/components/ui/button';
import {
    formatBytes,
    formatHourLabel,
    formatRelativeTime,
    formatUtcHourLabel,
    formatUtcTimestamp,
    parseTimestamp,
    timeZoneNotice,
} from '@/lib/logs';
import { splitExceptionHeadline } from '@/lib/logs';
import { dashboard } from '@/routes';
import { index as issuesIndex } from '@/routes/issues';
import { index as logsIndex } from '@/routes/logs';
import { show as projectShow } from '@/routes/projects';
import type {
    DashboardInvitation,
    IngestRateUsage,
    LogDigest,
    LogDigestCounts,
    LogOnboarding,
    LogProject,
    LogStorageProject,
    LogStorageSummary,
    PlanUsage,
    Team,
} from '@/types';

const props = defineProps<{
    pendingInvitations?: DashboardInvitation[];
    onboarding: LogOnboarding;
    projects: LogProject[];
    storage: LogStorageSummary | null;
    digest: LogDigest | null;
    ingestRate: IngestRateUsage | null;
    planUsage: PlanUsage;
}>();

defineOptions({
    layout: (layoutProps: { currentTeam?: Team | null }) => ({
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: layoutProps.currentTeam
                    ? dashboard(layoutProps.currentTeam.slug)
                    : '/',
            },
        ],
    }),
});

const page = usePage();

const teamSlug = computed(() => page.props.currentTeam?.slug ?? '');

const stage = computed(() => props.onboarding.stage);

/** The hourly trends behind the two headline numbers, oldest hour first. */
const logsSeries = computed(() =>
    (props.digest?.series ?? []).map((point) => point.total),
);

const errorsSeries = computed(() =>
    (props.digest?.series ?? []).map((point) => point.errors),
);

/**
 * One `HH:MM` label per hourly point, in the reader's timezone — the same
 * clock the log viewer's histogram axis and rows are labelled with, so a time
 * read on the dashboard means the same thing one click later.
 *
 * Shared by both tiles and by every service row: all of them are drawn over
 * the digest's single set of buckets.
 */
const hourLabels = computed(() =>
    (props.digest?.series ?? []).map((point) => formatHourLabel(point.bucket)),
);

/** The same buckets in UTC, for the second half of each tooltip line. */
const utcHourLabels = computed(() =>
    (props.digest?.series ?? []).map((point) =>
        formatUtcHourLabel(point.bucket),
    ),
);

/**
 * Which clock the section is showing, said once beside the heading rather
 * than on each of the numbers below it.
 */
const timeZone = timeZoneNotice();

const MINUTE_MS = 60_000;
const HOUR_MS = 60 * MINUTE_MS;

/**
 * The window the digest's numbers describe, in the shape the viewer reads.
 *
 * Anchored to the digest's own measure time rather than to the clock, so the
 * drill-down lands on exactly the rows that were counted. The viewer resolves
 * it back to its "Last 24 hours" preset (`presetForRange` tolerates an end a
 * few minutes shy of now, which is all the cache can be behind).
 */
const digestWindow = computed((): { from: string; to: string } => {
    const measured = props.digest
        ? parseTimestamp(props.digest.generatedAt)
        : new Date();
    const to = Number.isNaN(measured.getTime()) ? new Date() : measured;

    return {
        from: new Date(to.getTime() - 24 * HOUR_MS).toISOString(),
        to: to.toISOString(),
    };
});

/**
 * A service's window is the digest's own lookback, not the last 24 hours:
 * the row exists precisely to surface a shipper that went quiet, and a quiet
 * service in a 24 hour window renders an empty viewer.
 */
const serviceWindow = computed((): { from: string; to: string } => {
    const to = new Date();

    return {
        from: new Date(to.getTime() - 7 * 24 * HOUR_MS).toISOString(),
        to: to.toISOString(),
    };
});

const logsLink = computed(() =>
    logsIndex(teamSlug.value, { query: { ...digestWindow.value } }),
);

const errorsLink = computed(() =>
    logsIndex(teamSlug.value, {
        query: { ...digestWindow.value, severity: ['error', 'fatal'] },
    }),
);

/**
 * How much of an error body is handed to the viewer's search box.
 *
 * The body arrives already truncated for display; the trailing ellipsis is
 * not part of any log line, so it has to come off before the term is used.
 * A long prefix is enough: a term that is not a single word falls back to a
 * contains-match on the server, which the full body still satisfies.
 */
const SEARCH_TERM_LENGTH = 80;

function searchTermFor(body: string): string {
    return body.replace(/…+$/, '').trim().slice(0, SEARCH_TERM_LENGTH);
}

function topErrorLink(body: string) {
    return logsIndex(teamSlug.value, {
        query: { ...digestWindow.value, search: searchTermFor(body) },
    });
}

function serviceLink(name: string) {
    return logsIndex(teamSlug.value, {
        query: { ...serviceWindow.value, service: name },
    });
}

/**
 * The change vs the prior day, as neutral prose.
 *
 * Deliberately uncoloured: a drop in volume is not obviously good and a rise
 * is not obviously bad, so the number states the fact and leaves the reading
 * to whoever knows the system.
 */
function deltaLabel(counts: LogDigestCounts): string {
    if (counts.deltaPercent === null) {
        return 'no prior data';
    }

    const sign = counts.deltaPercent > 0 ? '+' : '';

    return `${sign}${counts.deltaPercent}% vs prior day`;
}

/**
 * What share of the last 24 hours was an error, as a whole percentage.
 *
 * Derived from the two numbers already on the page — no extra query — and
 * null when nothing was logged at all, where the share is unanswerable
 * rather than zero.
 */
const errorRate = computed<number | null>(() => {
    if (!props.digest || props.digest.logs.current <= 0) {
        return null;
    }

    return Math.round(
        (props.digest.errors.current / props.digest.logs.current) * 100,
    );
});

/**
 * The plain-language reading of the error rate.
 *
 * Only two states say anything: a majority of errors is alarming and gets the
 * severity hue, a noticeable minority is worth stating in muted text, and
 * anything under 5% is normal operation and says nothing at all.
 */
const errorVerdict = computed<{ text: string; severe: boolean } | null>(() => {
    const rate = errorRate.value;

    if (rate === null || rate < 5) {
        return null;
    }

    if (rate >= 50) {
        return {
            text: 'Most of what you logged in the last 24 hours is errors.',
            severe: true,
        };
    }

    return {
        text: `Errors are ${rate}% of the last 24 hours.`,
        severe: false,
    };
});

/** How long ago the digest's numbers were measured. */
const measuredAgo = computed(() =>
    props.digest ? formatRelativeTime(props.digest.generatedAt) : null,
);

/**
 * A project's bar as a share of the whole table, floored so the smallest
 * project with any data still shows a visible sliver.
 */
/** The rail's own count, read here too: one number for "what needs looking at". */
const issueBadge = computed(() => page.props.issueBadge ?? null);

const servicesReporting = computed(
    () => props.digest?.services.filter((service) => !service.quiet) ?? [],
);

/** The most recently heard-from service, for the KPI's caption. */
const lastHeard = computed(() => {
    const services = props.digest?.services ?? [];

    if (services.length === 0) {
        return null;
    }

    const newest = services.reduce((latest, service) =>
        parseTimestamp(service.lastSeen).getTime() >
        parseTimestamp(latest.lastSeen).getTime()
            ? service
            : latest,
    );

    return formatRelativeTime(newest.lastSeen);
});

const serviceSeriesMax = computed(() =>
    Math.max(
        1,
        ...(props.digest?.services ?? []).flatMap((service) => service.series),
    ),
);

function storageBarWidth(project: LogStorageProject): number {
    if (!props.storage || props.storage.totalBytes <= 0 || project.bytes <= 0) {
        return 0;
    }

    return Math.max(2, (project.bytes / props.storage.totalBytes) * 100);
}
</script>

<template>
    <Head title="Dashboard" />

    <PendingInvitationsModal
        v-if="pendingInvitations && pendingInvitations.length > 0"
        :invitations="pendingInvitations"
    />

    <div class="flex flex-1 flex-col">
        <PageHeader
            title="Dashboard"
            :eyebrow="[page.props.currentTeam?.name ?? 'Team', 'Last 24 hours']"
        >
            <p v-if="stage === 'ready' && digest && !digest.unavailable">
                <span
                    v-if="errorVerdict"
                    :class="
                        errorVerdict.severe
                            ? 'font-medium text-severity-error'
                            : 'text-foreground'
                    "
                    data-test="dashboard-digest-verdict"
                    >{{ errorVerdict.text }}</span
                >
                <span v-if="measuredAgo" data-test="dashboard-digest-measured">
                    Updated {{ measuredAgo }}</span
                ><span class="hidden sm:inline" data-test="dashboard-timezone">
                    · {{ timeZone }}</span
                >
            </p>
            <p v-else-if="stage !== 'ready'">
                Two steps between you and your first line.
            </p>

            <template v-if="stage === 'ready'" #actions>
                <Button variant="outline" as-child>
                    <Link :href="issuesIndex(teamSlug)">
                        Open issues
                        <ArrowRight />
                    </Link>
                </Button>
                <Button as-child data-test="dashboard-logs-link">
                    <Link :href="logsIndex(teamSlug)">
                        Open the log viewer
                        <ArrowRight />
                    </Link>
                </Button>
            </template>
        </PageHeader>

        <!--
          Until logs flow, the dashboard IS onboarding: the same panel the
          logs page shows, snippets and all, rather than a card echoing it.
          Once the team is ready the panel disappears for good — the health
          digest below is the dashboard's real content.
        -->
        <div v-if="stage !== 'ready'" class="px-4 pb-10 md:px-10">
            <GetStartedPanel
                :stage="stage"
                :projects="projects"
                :team-slug="teamSlug"
                data-test="dashboard-onboarding"
                :data-stage="stage"
            />
        </div>

        <div
            v-else
            class="flex flex-col px-4 pb-10 md:px-10"
            data-test="dashboard-health"
        >
            <!--
              The digest only appears once logs are flowing: during onboarding
              a health summary of an empty store would say nothing true.
            -->
            <section
                v-if="digest"
                class="flex flex-col"
                aria-label="System health"
                data-test="dashboard-digest"
            >
                <p
                    v-if="digest.unavailable"
                    class="border-y py-6 text-sm text-muted-foreground"
                >
                    The last 24 hours are momentarily unavailable while the log
                    store is busy. They will be back on the next visit.
                </p>

                <template v-else>
                    <!--
                      The numbers, on the surface rather than in five cards:
                      one strip, hairlines between, the figure large and its
                      context small. Only the error figure carries a hue.
                    -->
                    <div
                        class="grid grid-cols-2 border-y md:grid-cols-3 xl:grid-cols-5"
                        data-test="dashboard-kpis"
                    >
                        <Link
                            :href="logsLink"
                            class="group -ml-3 flex flex-col gap-1.5 border-b py-5 pr-6 pl-3 transition-colors hover:bg-muted/40 md:border-b-0 xl:border-r"
                            data-test="dashboard-digest-logs-link"
                        >
                            <span
                                class="text-xs font-medium text-muted-foreground"
                                data-test="dashboard-digest-logs"
                                >Logs</span
                            >
                            <span
                                class="text-3xl leading-9 font-semibold tracking-tight tabular-nums"
                                >{{
                                    digest.logs.current.toLocaleString()
                                }}</span
                            >
                            <span class="text-xs text-muted-foreground">{{
                                deltaLabel(digest.logs)
                            }}</span>
                        </Link>

                        <Link
                            :href="errorsLink"
                            class="flex flex-col gap-1.5 border-b py-5 pr-6 pl-6 transition-colors hover:bg-muted/40 md:border-b-0 xl:border-r"
                            data-test="dashboard-digest-errors-link"
                        >
                            <span
                                class="text-xs font-medium text-muted-foreground"
                                data-test="dashboard-digest-errors"
                                >Errors</span
                            >
                            <span class="flex items-baseline gap-2">
                                <span
                                    class="text-3xl leading-9 font-semibold tracking-tight tabular-nums"
                                    :class="
                                        digest.errors.current > 0
                                            ? 'text-severity-error'
                                            : ''
                                    "
                                    >{{
                                        digest.errors.current.toLocaleString()
                                    }}</span
                                >
                                <span
                                    v-if="errorRate !== null"
                                    class="font-mono text-xs text-muted-foreground"
                                    >{{ errorRate }}%</span
                                >
                            </span>
                            <span class="text-xs text-muted-foreground">{{
                                errorRate === null
                                    ? deltaLabel(digest.errors)
                                    : `of all logs · ${deltaLabel(digest.errors)}`
                            }}</span>
                        </Link>

                        <Link
                            :href="issuesIndex(teamSlug)"
                            class="-ml-3 flex flex-col gap-1.5 py-5 pr-6 pl-3 transition-colors hover:bg-muted/40 md:ml-0 md:border-l md:pl-6 xl:border-r xl:border-l-0"
                            data-test="dashboard-issues"
                        >
                            <span
                                class="text-xs font-medium text-muted-foreground"
                                >Unresolved issues</span
                            >
                            <span
                                class="text-3xl leading-9 font-semibold tracking-tight tabular-nums"
                                >{{
                                    issueBadge
                                        ? issueBadge.unresolved.toLocaleString()
                                        : '—'
                                }}</span
                            >
                            <span class="text-xs text-muted-foreground">
                                <template v-if="issueBadge === null"
                                    >last 7 days</template
                                >
                                <template v-else-if="issueBadge.regressed > 0">
                                    <span
                                        class="font-medium text-severity-error"
                                        >{{
                                            issueBadge.regressed
                                        }}
                                        regressed</span
                                    >
                                    · last 7 days
                                </template>
                                <template v-else>last 7 days</template>
                            </span>
                        </Link>

                        <div
                            class="flex flex-col gap-1.5 border-t py-5 pr-6 pl-6 md:border-t-0 md:border-l md:pl-6 xl:border-r xl:border-l-0"
                        >
                            <span
                                class="text-xs font-medium text-muted-foreground"
                                >Services reporting</span
                            >
                            <span
                                class="text-3xl leading-9 font-semibold tracking-tight tabular-nums"
                                >{{ servicesReporting.length }}
                                <span
                                    v-if="
                                        digest.services.length >
                                        servicesReporting.length
                                    "
                                    class="text-base font-medium text-muted-foreground"
                                    >/ {{ digest.services.length }}</span
                                ></span
                            >
                            <span class="text-xs text-muted-foreground">{{
                                lastHeard
                                    ? `last heard ${lastHeard}`
                                    : 'nothing heard yet'
                            }}</span>
                        </div>

                        <div
                            class="flex flex-col gap-1.5 border-t py-5 pr-6 md:pl-6 xl:border-t-0 xl:pl-6"
                            data-test="dashboard-stored"
                        >
                            <span
                                class="text-xs font-medium text-muted-foreground"
                                >Stored</span
                            >
                            <span
                                class="text-3xl leading-9 font-semibold tracking-tight tabular-nums"
                                >{{
                                    storage && !storage.unavailable
                                        ? formatBytes(storage.totalBytes)
                                        : '—'
                                }}</span
                            >
                            <span class="text-xs text-muted-foreground"
                                >compressed · on disk now</span
                            >
                        </div>
                    </div>

                    <!--
                      The day, hour by hour: volume in the chart colour, the
                      part of each hour that broke drawn over it in red.
                    -->
                    <section
                        class="flex flex-col gap-2 py-7"
                        data-test="dashboard-volume"
                    >
                        <p class="text-xs text-muted-foreground">
                            <span class="font-medium text-foreground"
                                >Logs per hour</span
                            >
                            · errors drawn over the volume
                        </p>
                        <BarSparkline
                            :values="logsSeries"
                            :error-values="errorsSeries"
                            :labels="hourLabels"
                            :utc-labels="utcHourLabels"
                            :bar-max-width="28"
                            tone="volume"
                            unit="logs"
                            :height="128"
                        />
                        <div
                            class="flex justify-between font-mono text-[11px] text-muted-foreground"
                        >
                            <span>{{ hourLabels[0] ?? '24h ago' }}</span>
                            <span>{{
                                hourLabels[Math.floor(hourLabels.length / 2)] ??
                                ''
                            }}</span>
                            <span class="text-foreground">now</span>
                        </div>
                    </section>

                    <div class="grid gap-x-12 gap-y-8 pb-8 xl:grid-cols-5">
                        <section
                            class="flex flex-col xl:col-span-3"
                            data-test="dashboard-digest-top-errors"
                        >
                            <SectionHeader
                                title="Top errors"
                                note="repeating most often · 24h"
                            />
                            <p
                                v-if="digest.topErrors.length === 0"
                                class="py-4 text-sm text-muted-foreground"
                            >
                                No errors in the last 24 hours.
                            </p>
                            <ul v-else>
                                <li
                                    v-for="error in digest.topErrors"
                                    :key="error.body"
                                    class="border-b border-border/60 last:border-b-0"
                                >
                                    <Link
                                        :href="topErrorLink(error.body)"
                                        class="-mx-3 flex h-11 items-center gap-4 rounded-md px-3 text-sm transition-colors hover:bg-muted/40"
                                        :title="error.body"
                                        data-test="dashboard-digest-error-link"
                                    >
                                        <span
                                            class="min-w-0 flex-1 truncate font-mono text-xs text-foreground/80"
                                        >
                                            <span
                                                v-if="
                                                    splitExceptionHeadline(
                                                        error.body,
                                                    ).head
                                                "
                                                class="font-semibold text-foreground"
                                                >{{
                                                    splitExceptionHeadline(
                                                        error.body,
                                                    ).head
                                                }}&nbsp;</span
                                            >{{
                                                splitExceptionHeadline(
                                                    error.body,
                                                ).rest
                                            }}
                                        </span>
                                        <span
                                            class="w-12 shrink-0 text-right font-mono text-sm font-medium tabular-nums"
                                        >
                                            {{ error.total.toLocaleString() }}
                                        </span>
                                    </Link>
                                </li>
                            </ul>
                        </section>

                        <section
                            v-if="digest.services.length > 0"
                            class="flex flex-col xl:col-span-2"
                            data-test="dashboard-digest-services"
                        >
                            <SectionHeader
                                title="Services"
                                note="quiet = nothing for over an hour"
                            />
                            <ul>
                                <li
                                    v-for="service in digest.services"
                                    :key="service.name"
                                    :data-quiet="service.quiet"
                                    class="border-b border-border/60 last:border-b-0"
                                >
                                    <!--
                                      A fixed column template, identical on
                                      every row, so the charts share one
                                      x-origin, one right edge and one scale.
                                    -->
                                    <Link
                                        :href="serviceLink(service.name)"
                                        class="-mx-3 grid h-11 grid-cols-[0.5rem_minmax(0,1fr)_6rem_4.5rem] items-center gap-3 rounded-md px-3 text-sm transition-colors hover:bg-muted/40"
                                        :title="`Show the last 7 days of ${service.name}`"
                                        data-test="dashboard-digest-service-link"
                                    >
                                        <span
                                            class="size-2 rounded-full"
                                            :class="
                                                service.quiet
                                                    ? 'ring-1 ring-muted-foreground/60 ring-inset'
                                                    : 'bg-foreground'
                                            "
                                            aria-hidden="true"
                                        />
                                        <span
                                            class="min-w-0 truncate font-mono text-xs font-medium"
                                            :class="
                                                service.quiet
                                                    ? 'text-muted-foreground'
                                                    : ''
                                            "
                                            :title="service.name"
                                        >
                                            {{ service.name }}
                                            <span
                                                v-if="service.quiet"
                                                class="ml-1 font-sans text-[11px] font-normal tracking-wide uppercase"
                                                >quiet</span
                                            >
                                        </span>
                                        <span
                                            class="min-w-0"
                                            data-test="dashboard-digest-service-series"
                                        >
                                            <BarSparkline
                                                :values="service.series"
                                                :error-values="
                                                    service.errorSeries
                                                "
                                                :labels="hourLabels"
                                                :utc-labels="utcHourLabels"
                                                :max="serviceSeriesMax"
                                                :height="24"
                                                tone="volume"
                                                unit="logs"
                                            />
                                        </span>
                                        <span
                                            class="text-right text-xs text-muted-foreground tabular-nums"
                                            :title="
                                                formatUtcTimestamp(
                                                    service.lastSeen,
                                                )
                                            "
                                        >
                                            {{
                                                formatRelativeTime(
                                                    service.lastSeen,
                                                )
                                            }}
                                        </span>
                                    </Link>
                                </li>
                            </ul>
                        </section>
                    </div>
                </template>
            </section>

            <!--
              Capacity: what is on disk, how close the shippers are to being
              turned away, and where the team stands on its plan. Three reads
              of headroom side by side, below the health of the day.
            -->
            <div
                class="grid gap-x-12 gap-y-10 border-t pt-7 lg:grid-cols-2 xl:grid-cols-3"
                data-test="dashboard-capacity"
            >
                <section
                    v-if="storage"
                    class="flex flex-col gap-4"
                    data-test="dashboard-storage"
                >
                    <header class="flex flex-col gap-1.5">
                        <h3
                            class="flex items-center justify-between gap-2 text-base font-semibold tracking-tight"
                        >
                            Storage
                            <span
                                v-if="!storage.unavailable"
                                class="font-mono text-sm font-normal text-muted-foreground"
                                data-test="dashboard-storage-total"
                            >
                                {{ formatBytes(storage.totalBytes) }}
                            </span>
                        </h3>
                        <p class="text-sm text-muted-foreground">
                            Compressed bytes on disk across the 30-day retention
                            window. The per-project split is an estimate.
                        </p>
                    </header>

                    <p
                        v-if="storage.unavailable"
                        class="text-sm text-muted-foreground"
                    >
                        Storage numbers are momentarily unavailable while the
                        log store is busy. They will be back on the next visit.
                    </p>

                    <ul v-else class="flex flex-col gap-3">
                        <li
                            v-for="project in storage.projects"
                            :key="project.slug"
                            class="flex flex-col gap-1.5"
                        >
                            <div
                                class="flex items-baseline justify-between gap-2 text-sm"
                            >
                                <Link
                                    :href="
                                        projectShow([teamSlug, project.slug])
                                    "
                                    class="truncate font-medium hover:underline"
                                >
                                    {{ project.name }}
                                </Link>
                                <!--
                                  "stored" and "retained" are load-bearing: the
                                  numbers above count the last 24 hours, this
                                  counts everything still on disk.
                                -->
                                <span
                                    class="shrink-0 font-mono text-xs text-muted-foreground"
                                >
                                    {{ project.rows.toLocaleString() }} logs
                                    stored · {{ formatBytes(project.bytes) }}
                                </span>
                            </div>
                            <div
                                class="h-1 overflow-hidden rounded-full bg-muted"
                                role="presentation"
                            >
                                <div
                                    class="h-full rounded-full bg-foreground/70"
                                    :style="{
                                        width: `${storageBarWidth(project)}%`,
                                    }"
                                />
                            </div>
                        </li>
                    </ul>
                </section>

                <IngestRateCard v-if="ingestRate" :usage="ingestRate" />

                <PlanUsageCard :usage="planUsage" />
            </div>
        </div>
    </div>
</template>
