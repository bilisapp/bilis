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
import { formatBytes, formatRelativeTime } from '@/lib/logs';
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
          The digest only appears once logs are flowing: during onboarding a
          health summary of an empty store would say nothing true.
        -->
        <section
            v-if="digest && stage === 'ready'"
            class="flex max-w-2xl flex-col gap-4"
            aria-labelledby="dashboard-digest-heading"
            data-test="dashboard-digest"
        >
            <div class="flex items-center justify-between gap-2">
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

            <p v-if="digest.unavailable" class="text-sm text-muted-foreground">
                The last 24 hours are momentarily unavailable while the log
                store is busy. They will be back on the next visit.
            </p>

            <template v-else>
                <div class="grid gap-4 sm:grid-cols-2">
                    <Card data-test="dashboard-digest-logs">
                        <CardHeader>
                            <CardDescription>Logs · 24h</CardDescription>
                            <CardTitle class="font-mono text-2xl">
                                {{ digest.logs.current.toLocaleString() }}
                            </CardTitle>
                        </CardHeader>
                        <CardContent class="flex flex-col gap-2">
                            <DitherSparkline
                                :values="logsSeries"
                                tone="volume"
                            />
                            <p class="text-xs text-muted-foreground">
                                {{ deltaLabel(digest.logs) }}
                            </p>
                        </CardContent>
                    </Card>

                    <Card data-test="dashboard-digest-errors">
                        <CardHeader>
                            <CardDescription>Errors · 24h</CardDescription>
                            <CardTitle
                                class="font-mono text-2xl"
                                :class="
                                    digest.errors.current > 0
                                        ? 'text-severity-error'
                                        : ''
                                "
                            >
                                {{ digest.errors.current.toLocaleString() }}
                            </CardTitle>
                        </CardHeader>
                        <CardContent class="flex flex-col gap-2">
                            <DitherSparkline
                                :values="errorsSeries"
                                tone="error"
                            />
                            <p class="text-xs text-muted-foreground">
                                {{ deltaLabel(digest.errors) }}
                            </p>
                        </CardContent>
                    </Card>
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
                                    :href="logsIndex(teamSlug)"
                                    class="min-w-0 truncate font-mono text-xs hover:underline"
                                    :title="error.body"
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
                            When each shipper was last heard from. Quiet ones
                            have said nothing for over an hour.
                        </CardDescription>
                    </CardHeader>

                    <CardContent>
                        <ul class="flex flex-col gap-2">
                            <li
                                v-for="service in digest.services"
                                :key="service.name"
                                class="flex items-baseline justify-between gap-3 text-sm"
                                :data-quiet="service.quiet"
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
                                <span
                                    class="flex shrink-0 items-center gap-2 text-xs text-muted-foreground"
                                >
                                    <span
                                        v-if="service.quiet"
                                        class="rounded-full border border-border px-1.5 py-0.5 text-xs tracking-wide uppercase"
                                    >
                                        quiet
                                    </span>
                                    {{ formatRelativeTime(service.lastSeen) }}
                                </span>
                            </li>
                        </ul>
                    </CardContent>
                </Card>
            </template>
        </section>

        <!--
          Storage only appears once logs are flowing: during onboarding a card
          full of zeroes would just be noise next to the setup steps.
        -->
        <Card
            v-if="storage && stage === 'ready'"
            class="max-w-2xl"
            data-test="dashboard-storage"
        >
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
                    Compressed bytes on disk across the 30-day retention window.
                    The per-project split is an estimate.
                </CardDescription>
            </CardHeader>

            <CardContent>
                <p
                    v-if="storage.unavailable"
                    class="text-sm text-muted-foreground"
                >
                    Storage numbers are momentarily unavailable while the log
                    store is busy. They will be back on the next visit.
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
                                :href="projectShow([teamSlug, project.slug])"
                                class="truncate font-medium hover:underline"
                            >
                                {{ project.name }}
                            </Link>
                            <span
                                class="shrink-0 font-mono text-xs text-muted-foreground"
                            >
                                {{ project.rows.toLocaleString() }} logs ·
                                {{ formatBytes(project.bytes) }}
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
</template>
