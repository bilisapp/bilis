<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AlertStateBadge from '@/components/AlertStateBadge.vue';
import {
    ALERT_SEVERITY_LABEL,
    formatLabels,
    formatReading,
    formatSeconds,
    sinceSeconds,
} from '@/lib/alerts';
import { cn } from '@/lib/utils';
import { edit as alertsEdit } from '@/routes/alerts';
import type { AlertInstanceSummary } from '@/types';

/**
 * One series that is firing, or waiting out its `for` — Prometheus' "active
 * alert".
 *
 * Read left to right as a sentence: this rule, for these labels, is at this
 * value against that threshold, and has been for this long. A firing row
 * carries a severity-error edge like a regressed issue does; a pending one
 * does not, because it has not happened yet. Muted rules still list their
 * series — muting silences notifications, not the page.
 */
const props = withDefaults(
    defineProps<{
        instance: AlertInstanceSummary;
        teamSlug: string;
        /** The clock "for 12m" counts from; the real page leaves it as now. */
        now?: number;
    }>(),
    { now: undefined },
);

const href = computed(
    () => alertsEdit([props.teamSlug, props.instance.ruleId]).url,
);

const since = computed(() =>
    sinceSeconds(
        props.instance.state === 'firing'
            ? props.instance.firedAt
            : props.instance.activeAt,
        props.now ?? Date.now(),
    ),
);

/** The value against the threshold, in the words `formatReading` uses everywhere. */
const reading = computed(() => formatReading(props.instance));
</script>

<template>
    <Link
        :href="href"
        :class="
            cn(
                'relative grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-x-5 gap-y-1 border-b border-border/70 px-4 py-3 transition-colors hover:bg-muted/50 focus-visible:bg-muted/60 focus-visible:outline-none md:grid-cols-[5.5rem_minmax(0,1fr)_9rem_6rem] md:px-10',
            )
        "
        :data-test="`alert-instance-${instance.id}`"
    >
        <span
            v-if="instance.state === 'firing'"
            class="absolute inset-y-2.5 left-0 w-[3px] rounded-r-sm bg-severity-error"
            aria-hidden="true"
        />

        <div>
            <AlertStateBadge :state="instance.state" />
        </div>

        <div class="flex min-w-0 flex-col gap-0.5">
            <div class="flex min-w-0 items-center gap-2">
                <span class="truncate text-sm font-medium text-foreground">{{
                    instance.ruleName
                }}</span>
                <span class="shrink-0 text-xs text-muted-foreground">{{
                    ALERT_SEVERITY_LABEL[instance.severity]
                }}</span>
                <AlertStateBadge v-if="instance.muted" state="muted" />
            </div>
            <span
                class="truncate font-mono text-xs text-foreground/75"
                :title="formatLabels(instance.labels)"
                >{{ formatLabels(instance.labels) }}</span
            >
        </div>

        <div class="text-right font-mono tabular-nums">
            <span class="text-sm font-medium text-foreground">{{
                reading.value
            }}</span>
            <span class="block text-[11px] text-muted-foreground">{{
                reading.threshold
            }}</span>
        </div>

        <div
            class="hidden text-right text-xs text-muted-foreground tabular-nums md:block"
        >
            <template v-if="since !== null">
                for
                {{
                    formatSeconds(since >= 3600 ? since - (since % 60) : since)
                }}
            </template>
        </div>
    </Link>
</template>
