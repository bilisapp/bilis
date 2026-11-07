<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import DitherSparkline from '@/components/DitherSparkline.vue';
import IssueStatusBadge from '@/components/IssueStatusBadge.vue';
import { formatCount, isoFromMs, issueHref } from '@/lib/issues';
import { formatRelativeTime } from '@/lib/logs';
import { cn } from '@/lib/utils';
import type { IssueListItem } from '@/types';

/**
 * One issue in the list: what broke, where, how often, and lately.
 *
 * The left column is read — the exception type, then its message, then where
 * it was thrown. The right columns are scanned: a trend, the window's events
 * and users, and the age of the issue's last and first occurrences. A new
 * issue (first seen inside the window) and a regressed one say so in words,
 * since both are reasons to open it before anything else.
 */
const props = defineProps<{
    issue: IssueListItem;
    teamSlug: string;
    cursor?: boolean;
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

const firstSeen = computed(() =>
    formatRelativeTime(isoFromMs(props.issue.firstSeen)).replace(
        ' ago',
        ' old',
    ),
);
</script>

<template>
    <Link
        :href="href"
        :class="
            cn(
                'group/row grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-1 border-b px-4 py-3 text-xs transition-colors hover:bg-accent/60 focus-visible:bg-accent/60 focus-visible:outline-none md:grid-cols-[minmax(0,1fr)_7rem_4.5rem_4.5rem_6.5rem]',
                cursor && 'bg-accent/70 ring-1 ring-ring/60 ring-inset',
            )
        "
        :data-test="`issue-row-${issue.fingerprint}`"
        :data-cursor="cursor ? 'true' : undefined"
    >
        <div class="flex min-w-0 flex-col gap-1">
            <div class="flex min-w-0 items-center gap-2">
                <span
                    :class="
                        cn(
                            'size-1.5 shrink-0 rounded-full',
                            issue.status === 'resolved' ||
                                issue.status === 'ignored'
                                ? 'bg-muted-foreground/40'
                                : 'bg-severity-error',
                        )
                    "
                    aria-hidden="true"
                />
                <span
                    class="truncate font-mono text-sm font-medium"
                    :title="title"
                >
                    {{ title }}
                </span>
                <span
                    v-if="issue.isNew"
                    class="shrink-0 rounded-full border px-1.5 text-[11px] font-medium text-foreground"
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
                class="flex min-w-0 items-center gap-1.5 text-muted-foreground"
            >
                <span class="shrink-0">{{
                    issue.service || issue.projectName
                }}</span>
                <template v-if="issue.origin">
                    <span aria-hidden="true">·</span>
                    <span class="truncate font-mono" :title="issue.origin">
                        {{ issue.origin }}
                    </span>
                </template>
                <template v-if="issue.lastRelease">
                    <span aria-hidden="true">·</span>
                    <span class="shrink-0 font-mono">{{
                        issue.lastRelease
                    }}</span>
                </template>
            </div>
        </div>

        <div class="hidden md:block" aria-hidden="true">
            <DitherSparkline :values="issue.series" tone="error" :height="28" />
        </div>

        <div class="text-right font-mono tabular-nums md:text-left">
            <span class="text-sm text-foreground">{{
                formatCount(issue.windowEvents)
            }}</span>
            <span class="block text-[11px] text-muted-foreground md:hidden"
                >events</span
            >
        </div>

        <div class="hidden font-mono text-sm tabular-nums md:block">
            {{ formatCount(issue.windowUsers) }}
        </div>

        <div class="hidden flex-col text-muted-foreground md:flex">
            <span class="text-foreground">{{ lastSeen }}</span>
            <span>{{ firstSeen }}</span>
        </div>
    </Link>
</template>
