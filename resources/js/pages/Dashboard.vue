<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Activity, ArrowRight, Database } from '@lucide/vue';
import { computed } from 'vue';
import DitherSparkline from '@/components/DitherSparkline.vue';
import GetStartedPanel from '@/components/GetStartedPanel.vue';
import PendingInvitationsModal from '@/components/PendingInvitationsModal.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { formatBytes, formatRelativeTime, parseTimestamp } from '@/lib/logs';
import { dashboard } from '@/routes';
import { index as logsIndex } from '@/routes/logs';
import { show as projectShow } from '@/routes/projects';
import type {
    DashboardInvitation,
    LogDigest,
    LogDigestCounts,
    LogOnboarding,
    LogProject,
    LogStorageProject,
    LogStorageSummary,
    Team,
} from '@/types';

const props = defineProps<{
    pendingInvitations?: DashboardInvitation[];
    onboarding: LogOnboarding;
    projects: LogProject[];
    storage: LogStorageSummary | null;
    digest: LogDigest | null;
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

const pad = (value: number): string => String(value).padStart(2, '0');

/**
 * One `HH:MM` label per hourly point, in UTC — the same clock the log
 * viewer's histogram axis is labelled with, so a time read on the dashboard
 * means the same thing one click later.
 *
 * Shared by both tiles and by every service row: all of them are drawn over
 * the digest's single set of buckets.
 */
const hourLabels = computed(() =>
    (props.digest?.series ?? []).map((point) => {
        const date = parseTimestamp(point.bucket);

        return Number.isNaN(date.getTime())
            ? ''
            : `${pad(date.getUTCHours())}:${pad(date.getUTCMinutes())}`;
    }),
);

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

/** The Errors tile's caption: the share first, then the change. */
const errorsCaption = computed(() => {
    if (!props.digest) {
        return '';
    }

    const delta = deltaLabel(props.digest.errors);

    return errorRate.value === null
        ? delta
        : `${errorRate.value}% of all logs · ${delta}`;
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

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <h1 class="sr-only">Dashboard</h1>

        <!--
          Until logs flow, the dashboard IS onboarding: the same panel the
          logs page shows, snippets and all, rather than a card echoing it.
          Once the team is ready the panel disappears for good — the health
          digest below is the dashboard's real content.
        -->
        <GetStartedPanel
            v-if="stage !== 'ready'"
            :stage="stage"
            :projects="projects"
            :team-slug="teamSlug"
            data-test="dashboard-onboarding"
            :data-stage="stage"
        />

        <!--
          Once logs flow the page is a health board, not a column: the tiles,
          the two lists and storage share one grid so a wide screen is read
          in two columns instead of leaving half of itself empty.
        -->
        <div
            v-else
            class="grid w-full max-w-6xl gap-4 xl:grid-cols-2"
            data-test="dashboard-health"
        >
            <!--
              The digest only appears once logs are flowing: during onboarding
              a health summary of an empty store would say nothing true.
            -->
            <section
                v-if="digest"
                class="grid gap-4 xl:col-span-2 xl:grid-cols-2"
                aria-labelledby="dashboard-digest-heading"
                data-test="dashboard-digest"
            >
                <div
                    class="flex flex-wrap items-center justify-between gap-2 xl:col-span-2"
                >
                    <h2
                        id="dashboard-digest-heading"
                        class="flex items-center gap-2 text-sm font-medium"
                    >
                        <span
                            class="flex size-8 items-center justify-center rounded-full bg-muted text-muted-foreground"
                        >
                            <Activity class="size-4" />
                        </span>
                        System health
                        <span
                            v-if="!digest.unavailable && measuredAgo"
                            class="text-xs font-normal text-muted-foreground"
                            data-test="dashboard-digest-measured"
                        >
                            as of {{ measuredAgo }}
                        </span>
                    </h2>

                    <Button
                        variant="ghost"
                        size="sm"
                        as-child
                        data-test="dashboard-logs-link"
                    >
                        <Link :href="logsIndex(teamSlug)">
                            Open the log viewer
                            <ArrowRight />
                        </Link>
                    </Button>
                </div>

                <p
                    v-if="digest.unavailable"
                    class="text-sm text-muted-foreground xl:col-span-2"
                >
                    The last 24 hours are momentarily unavailable while the log
                    store is busy. They will be back on the next visit.
                </p>

                <template v-else>
                    <div class="grid gap-4 sm:grid-cols-2 xl:col-span-2">
                        <Link
                            :href="logsLink"
                            class="rounded-xl transition-colors focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                            data-test="dashboard-digest-logs-link"
                        >
                            <Card
                                class="h-full hover:border-ring/60"
                                data-test="dashboard-digest-logs"
                            >
                                <CardHeader>
                                    <CardDescription>
                                        Logs · 24h
                                    </CardDescription>
                                    <CardTitle class="font-mono text-2xl">
                                        {{
                                            digest.logs.current.toLocaleString()
                                        }}
                                    </CardTitle>
                                </CardHeader>
                                <CardContent class="flex flex-col gap-2">
                                    <DitherSparkline
                                        :values="logsSeries"
                                        :labels="hourLabels"
                                        tone="volume"
                                        unit="logs"
                                    />
                                    <div
                                        class="flex items-baseline justify-between gap-2 text-xs text-muted-foreground"
                                    >
                                        <span>24h ago</span>
                                        <span>now</span>
                                    </div>
                                    <p class="text-xs text-muted-foreground">
                                        {{ deltaLabel(digest.logs) }}
                                    </p>
                                </CardContent>
                            </Card>
                        </Link>

                        <Link
                            :href="errorsLink"
                            class="rounded-xl transition-colors focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                            data-test="dashboard-digest-errors-link"
                        >
                            <Card
                                class="h-full hover:border-ring/60"
                                data-test="dashboard-digest-errors"
                            >
                                <CardHeader>
                                    <CardDescription>
                                        Errors · 24h
                                    </CardDescription>
                                    <CardTitle
                                        class="font-mono text-2xl"
                                        :class="
                                            digest.errors.current > 0
                                                ? 'text-severity-error'
                                                : ''
                                        "
                                    >
                                        {{
                                            digest.errors.current.toLocaleString()
                                        }}
                                    </CardTitle>
                                </CardHeader>
                                <CardContent class="flex flex-col gap-2">
                                    <DitherSparkline
                                        :values="errorsSeries"
                                        :labels="hourLabels"
                                        tone="error"
                                        unit="errors"
                                    />
                                    <div
                                        class="flex items-baseline justify-between gap-2 text-xs text-muted-foreground"
                                    >
                                        <span>24h ago</span>
                                        <span>now</span>
                                    </div>
                                    <p class="text-xs text-muted-foreground">
                                        {{ errorsCaption }}
                                    </p>
                                    <!--
                                      The verdict the number alone does not
                                      give. Severity hue only when errors are
                                      the majority of the day — the rest is
                                      an observation, not an alarm.
                                    -->
                                    <p
                                        v-if="errorVerdict"
                                        class="text-xs"
                                        :class="
                                            errorVerdict.severe
                                                ? 'text-severity-error'
                                                : 'text-muted-foreground'
                                        "
                                        data-test="dashboard-digest-verdict"
                                    >
                                        {{ errorVerdict.text }}
                                    </p>
                                </CardContent>
                            </Card>
                        </Link>
                    </div>

                    <Card data-test="dashboard-digest-top-errors">
                        <CardHeader>
                            <CardTitle class="text-sm">
                                Top errors (24h)
                            </CardTitle>
                            <CardDescription>
                                The failures repeating most often, by log body.
                            </CardDescription>
                        </CardHeader>

                        <CardContent>
                            <p
                                v-if="digest.topErrors.length === 0"
                                class="text-sm text-muted-foreground"
                            >
                                No errors in the last 24 hours.
                            </p>

                            <ul v-else class="flex flex-col gap-2">
                                <li
                                    v-for="error in digest.topErrors"
                                    :key="error.body"
                                    class="flex items-baseline justify-between gap-3 text-sm"
                                >
                                    <Link
                                        :href="topErrorLink(error.body)"
                                        class="min-w-0 truncate font-mono text-xs hover:underline"
                                        :title="error.body"
                                        data-test="dashboard-digest-error-link"
                                    >
                                        {{ error.body }}
                                    </Link>
                                    <span
                                        class="shrink-0 font-mono text-xs text-muted-foreground"
                                    >
                                        {{ error.total.toLocaleString() }}×
                                    </span>
                                </li>
                            </ul>
                        </CardContent>
                    </Card>

                    <Card
                        v-if="digest.services.length > 0"
                        data-test="dashboard-digest-services"
                    >
                        <CardHeader>
                            <CardTitle class="text-sm">Services</CardTitle>
                            <CardDescription>
                                When each shipper was last heard from. Quiet
                                ones have said nothing for over an hour.
                            </CardDescription>
                        </CardHeader>

                        <CardContent>
                            <ul class="flex flex-col gap-2">
                                <li
                                    v-for="service in digest.services"
                                    :key="service.name"
                                    :data-quiet="service.quiet"
                                >
                                    <Link
                                        :href="serviceLink(service.name)"
                                        class="flex items-center justify-between gap-3 text-sm hover:underline"
                                        :title="`Show the last 7 days of ${service.name}`"
                                        data-test="dashboard-digest-service-link"
                                    >
                                        <span
                                            class="min-w-0 truncate font-mono text-xs"
                                            :class="
                                                service.quiet
                                                    ? 'text-muted-foreground'
                                                    : ''
                                            "
                                        >
                                            {{ service.name }}
                                        </span>
                                        <!--
                                          The row's own 24 hours. A shipper
                                          that is alive shows texture; a dead
                                          one shows the flat baseline, which
                                          is what makes "quiet" visible
                                          rather than merely labelled.
                                        -->
                                        <span
                                            class="ml-auto w-24 shrink-0"
                                            data-test="dashboard-digest-service-series"
                                        >
                                            <DitherSparkline
                                                :values="service.series"
                                                :labels="hourLabels"
                                                :height="20"
                                                tone="volume"
                                                unit="logs"
                                            />
                                        </span>
                                        <span
                                            class="flex shrink-0 items-center gap-2 text-xs text-muted-foreground"
                                        >
                                            <span
                                                v-if="service.quiet"
                                                class="rounded-full border border-border px-1.5 py-0.5 text-xs tracking-wide uppercase"
                                            >
                                                quiet
                                            </span>
                                            {{
                                                formatRelativeTime(
                                                    service.lastSeen,
                                                )
                                            }}
                                        </span>
                                    </Link>
                                </li>
                            </ul>
                        </CardContent>
                    </Card>
                </template>
            </section>

            <!--
              Storage only appears once logs are flowing: during onboarding a
              card full of zeroes would just be noise next to the setup steps.
            -->
            <Card v-if="storage" data-test="dashboard-storage">
                <CardHeader>
                    <CardTitle class="flex items-center justify-between gap-2">
                        <span class="flex items-center gap-2">
                            <span
                                class="flex size-8 items-center justify-center rounded-full bg-muted text-muted-foreground"
                            >
                                <Database class="size-4" />
                            </span>
                            Storage
                        </span>
                        <span
                            v-if="!storage.unavailable"
                            class="font-mono text-sm text-muted-foreground"
                            data-test="dashboard-storage-total"
                        >
                            {{ formatBytes(storage.totalBytes) }}
                        </span>
                    </CardTitle>

                    <CardDescription>
                        Compressed bytes on disk across the 30-day retention
                        window. The per-project split is an estimate.
                    </CardDescription>
                </CardHeader>

                <CardContent>
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
                            class="flex flex-col gap-1"
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
                                  tiles above count the last 24 hours, this
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
                                class="h-1.5 overflow-hidden rounded-full bg-muted"
                                role="presentation"
                            >
                                <div
                                    class="h-full rounded-full bg-foreground/60"
                                    :style="{
                                        width: `${storageBarWidth(project)}%`,
                                    }"
                                />
                            </div>
                        </li>
                    </ul>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
