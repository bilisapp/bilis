<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import BarSparkline from '@/components/BarSparkline.vue';
import IssueStatusBadge from '@/components/IssueStatusBadge.vue';
import {
    displayLocation,
    formatCount,
    isoFromMs,
    issueHref,
} from '@/lib/issues';
import { formatRelativeTime } from '@/lib/logs';
import { cn } from '@/lib/utils';
import type { IssueListItem } from '@/types';

/**
 * One issue in the list: what broke, where, how often, and lately.
 *
 * The left column is read — the exception type, then its message, then where
 * it was thrown and by which release. The right columns are scanned: a trend,
 * the window's events and users, and when it was last and first seen.
 *
 * Colour is spent once per row at most. A regressed issue — fixed, and back —
 * gets a severity edge down its left side and a tinted badge, because that is
 * the one status worth seeing before it is read. Every other row is ink.
 */
const props = defineProps<{
    issue: IssueListItem;
    teamSlug: string;
    cursor?: boolean;
    /** The tallest bucket across the visible rows, so sparklines compare. */
    seriesMax?: number;
}>();

const href = computed(() =>
    issueHref(props.teamSlug, props.issue.projectSlug, props.issue.fingerprint),
);

const title = computed(
    () => props.issue.type || props.issue.message || 'Unknown exception',
);

const lastSeen = computed(() =>
    formatRelativeTime(isoFromMs(props.issue.lastSeen)),
);

const firstSeen = computed(
    () => `first ${formatRelativeTime(isoFromMs(props.issue.firstSeen))}`,
);

const regressed = computed(() => props.issue.status === 'regressed');

const quiet = computed(
    () => props.issue.status === 'resolved' || props.issue.status === 'ignored',
);
</script>

<template>
    <Link
        :href="href"
        :class="
            cn(
                'group/row relative grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-6 gap-y-1 border-b border-border/70 px-4 py-4 transition-colors hover:bg-muted/50 focus-visible:bg-muted/60 focus-visible:outline-none md:grid-cols-[minmax(0,1fr)_10.5rem_4rem_4rem_6rem] md:px-10',
                cursor && 'bg-muted/70 ring-1 ring-ring/50 ring-inset',
            )
        "
        :data-test="`issue-row-${issue.fingerprint}`"
        :data-cursor="cursor ? 'true' : undefined"
    >
        <span
            v-if="regressed"
            class="absolute inset-y-3 left-0 w-[3px] rounded-r-sm bg-severity-error"
            aria-hidden="true"
        />

        <div class="flex min-w-0 flex-col gap-1">
            <div class="flex min-w-0 items-center gap-2">
                <span
                    :class="
                        cn(
                            'truncate font-mono text-sm font-semibold',
                            quiet ? 'text-muted-foreground' : 'text-foreground',
                        )
                    "
                    :title="title"
                >
                    {{ title }}
                </span>
                <span
                    v-if="issue.isNew"
                    class="inline-flex h-5 shrink-0 items-center rounded-full px-2 text-[11px] font-medium text-foreground/80 ring-1 ring-border ring-inset"
                    data-test="issue-row-new"
                >
                    New
                </span>
                <IssueStatusBadge
                    v-if="issue.status !== 'unresolved'"
                    :status="issue.status"
                />
            </div>

            <p
                v-if="issue.message && issue.type"
                class="truncate text-sm text-foreground/80"
                :title="issue.message"
            >
                {{ issue.message }}
            </p>

            <div
                class="flex min-w-0 items-center gap-1.5 font-mono text-xs text-muted-foreground"
            >
                <template v-if="issue.origin">
                    <span
                        class="truncate text-foreground/75"
                        :title="issue.origin"
                    >
                        {{ displayLocation(issue.origin) }}
                    </span>
                    <span class="text-input" aria-hidden="true">·</span>
                </template>
                <span class="shrink-0">{{
                    issue.service || issue.projectName
                }}</span>
                <template v-if="issue.lastRelease">
                    <span class="text-input" aria-hidden="true">·</span>
                    <span class="shrink-0">{{ issue.lastRelease }}</span>
                </template>
            </div>
        </div>

        <div class="hidden md:block" aria-hidden="true">
            <BarSparkline
                :values="issue.series"
                tone="error"
                :max="seriesMax"
                :height="32"
            />
        </div>

        <div class="text-right font-mono tabular-nums">
            <span class="text-sm font-medium text-foreground">{{
                formatCount(issue.windowEvents)
            }}</span>
            <span class="block text-[11px] text-muted-foreground md:hidden"
                >events</span
            >
        </div>

        <div
            class="hidden text-right font-mono text-sm text-foreground/75 tabular-nums md:block"
        >
            {{ formatCount(issue.windowUsers) }}
        </div>

        <div class="hidden flex-col items-end gap-0.5 md:flex">
            <span class="text-sm font-medium text-foreground">{{
                lastSeen
            }}</span>
            <span class="text-xs text-muted-foreground">{{ firstSeen }}</span>
        </div>
    </Link>
</template>
