<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowUpRight } from '@lucide/vue';
import { computed } from 'vue';
import AlertStateBadge from '@/components/AlertStateBadge.vue';
import SectionHeader from '@/components/SectionHeader.vue';
import { Skeleton } from '@/components/ui/skeleton';
import {
    ALERT_SEVERITY_LABEL,
    formatLabels,
    formatReading,
    formatSeconds,
    sinceSeconds,
} from '@/lib/alerts';
import { edit as alertsEdit, index as alertsIndex } from '@/routes/alerts';
import type { AlertAttentionItem, NeedsAttentionList } from '@/types';

/**
 * "Needs attention" on the dashboard (specs/alerting-defaults.md §5): what is
 * firing, then what is pending, most severe first — at most eight series.
 *
 * Each row is the AlertInstanceRow sentence, condensed: this rule, for these
 * labels, reads this against that threshold, and has for this long, with a
 * way into the explorer over that stretch. It does not depend on channels:
 * a team with nowhere to send alerts still sees here what matters.
 *
 * Deferred: a skeleton while it loads; nothing at all once it has loaded
 * empty, because "nothing needs attention" is the dashboard's normal state.
 */
const props = withDefaults(
    defineProps<{
        /** Deferred: undefined while it loads. */
        attention?: NeedsAttentionList;
        teamSlug: string;
        /** The clock "for 12m" counts from; the real page leaves it as now. */
        now?: number;
    }>(),
    { attention: undefined, now: undefined },
);

const note = computed(() => {
    const list = props.attention;

    if (!list) {
        return undefined;
    }

    // Counted over every series, so a cut list still says how many are pending.
    const shown = `${list.firing} firing · ${list.total - list.firing} pending`;

    return list.total > list.items.length
        ? `${shown} · ${list.items.length} of ${list.total}`
        : shown;
});

function since(item: AlertAttentionItem): string | null {
    const seconds = sinceSeconds(
        item.state === 'firing'
            ? (item.firedAt ?? item.activeAt)
            : item.activeAt,
        props.now ?? Date.now(),
    );

    if (seconds === null) {
        return null;
    }

    return formatSeconds(seconds >= 3600 ? seconds - (seconds % 60) : seconds);
}
</script>

<template>
    <section
        v-if="!attention || attention.items.length > 0"
        class="flex flex-col"
        aria-label="Needs attention"
        data-test="needs-attention"
    >
        <SectionHeader title="Needs attention">
            <template v-if="attention" #aside>
                <span>{{ note }}</span>
                <Link
                    :href="alertsIndex(teamSlug).url"
                    class="text-foreground underline-offset-4 hover:underline"
                    >All alerts</Link
                >
            </template>
        </SectionHeader>

        <div
            v-if="!attention"
            class="flex flex-col gap-2 py-3"
            data-test="needs-attention-loading"
        >
            <Skeleton v-for="index in 2" :key="index" class="h-10 w-full" />
        </div>

        <ul v-else class="flex flex-col">
            <li
                v-for="item in attention.items"
                :key="item.id"
                class="relative -mx-3 grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-x-4 gap-y-1 rounded-md border-b border-border/70 px-3 py-2.5 transition-colors last:border-b-0 hover:bg-muted/50 md:grid-cols-[5.5rem_minmax(0,1fr)_10rem_4.5rem_4.5rem]"
                :data-test="`needs-attention-${item.id}`"
                :data-state="item.state"
            >
                <span
                    v-if="item.state === 'firing'"
                    class="absolute inset-y-2 left-0 w-[3px] rounded-r-sm bg-severity-error"
                    aria-hidden="true"
                />

                <div>
                    <AlertStateBadge :state="item.state" />
                </div>

                <div class="flex min-w-0 flex-col gap-0.5">
                    <div class="flex min-w-0 items-center gap-2">
                        <Link
                            :href="alertsEdit([teamSlug, item.ruleId]).url"
                            class="truncate text-sm font-medium text-foreground hover:underline hover:underline-offset-4"
                            :title="item.ruleName"
                            >{{ item.ruleName }}</Link
                        >
                        <span class="shrink-0 text-xs text-muted-foreground">{{
                            ALERT_SEVERITY_LABEL[item.severity]
                        }}</span>
                        <AlertStateBadge v-if="item.muted" state="muted" />
                    </div>
                    <span
                        class="truncate font-mono text-xs text-foreground/75"
                        :title="formatLabels(item.labels)"
                        >{{ formatLabels(item.labels) }}</span
                    >
                </div>

                <div class="text-right font-mono tabular-nums">
                    <span class="text-sm font-medium text-foreground">{{
                        formatReading(item).value
                    }}</span>
                    <span class="block text-[11px] text-muted-foreground">{{
                        formatReading(item).threshold
                    }}</span>
                </div>

                <span
                    class="hidden text-right text-xs text-muted-foreground tabular-nums md:block"
                    >{{ since(item) ? `for ${since(item)}` : '' }}</span
                >

                <Link
                    v-if="item.explorerHref"
                    :href="item.explorerHref"
                    class="col-start-3 inline-flex items-center justify-end gap-1 text-xs text-muted-foreground transition-colors hover:text-foreground md:col-start-auto"
                    data-test="needs-attention-explore"
                >
                    Explore
                    <ArrowUpRight class="size-3" aria-hidden="true" />
                </Link>
                <span v-else class="hidden md:block" />
            </li>
        </ul>
    </section>
</template>
