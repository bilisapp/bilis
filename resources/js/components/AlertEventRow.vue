<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowUpRight } from '@lucide/vue';
import { computed } from 'vue';
import {
    ALERT_EVENT_LABEL,
    formatAlertValue,
    formatLabels,
} from '@/lib/alerts';
import { cn } from '@/lib/utils';
import { edit as alertsEdit } from '@/routes/alerts';
import type { AlertEventSummary } from '@/types';

/**
 * One line of alert history: when, what happened, to which series, and the
 * sentence the notification said.
 *
 * Only firing takes a hue — the severity-error dot — because it is the one
 * event that meant something broke. Resolved is the same dot hollowed, a
 * rule error is ink, and issue events are neutral: the history reads as a
 * column of red marks where the trouble was. The time is the evaluated
 * instant, in the reader's zone with UTC on hover, and "Explore" opens the
 * window the rule read.
 */
const props = defineProps<{
    event: AlertEventSummary;
    teamSlug: string;
}>();

const occurred = computed(() => new Date(props.event.occurredAt));

const time = computed(() =>
    occurred.value.toLocaleString(undefined, {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }),
);

const utc = computed(
    () => `${occurred.value.toISOString().replace('T', ' ').slice(0, 16)} UTC`,
);

const ruleHref = computed(() =>
    props.event.ruleId !== null
        ? alertsEdit([props.teamSlug, props.event.ruleId]).url
        : null,
);

const value = computed(() =>
    props.event.source && props.event.query
        ? formatAlertValue(
              props.event.value,
              props.event.source,
              props.event.query.aggregation,
              props.event.query.compare,
              props.event.query.metric,
          )
        : null,
);

const isRuleEvent = computed(() =>
    ['firing', 'resolved', 'rule_error'].includes(props.event.type),
);
</script>

<template>
    <div
        class="grid grid-cols-[6.5rem_minmax(0,1fr)_auto] items-start gap-x-5 gap-y-1 border-b border-border/70 px-4 py-3 md:grid-cols-[8rem_7.5rem_minmax(0,1fr)_6rem_5rem] md:px-10"
        :data-test="`alert-event-${event.id}`"
    >
        <time
            class="pt-0.5 text-xs text-muted-foreground tabular-nums"
            :datetime="event.occurredAt"
            :title="utc"
            >{{ time }}</time
        >

        <span
            class="hidden items-center gap-1.5 pt-0.5 text-xs font-medium text-foreground md:inline-flex"
        >
            <span
                :class="
                    cn(
                        'size-2 shrink-0 rounded-full',
                        event.type === 'firing' && 'bg-severity-error',
                        event.type === 'resolved' &&
                            'ring-1 ring-severity-error ring-inset',
                        event.type === 'rule_error' && 'bg-foreground',
                        (event.type === 'issue_new' ||
                            event.type === 'issue_regressed') &&
                            'bg-muted-foreground/50',
                    )
                "
                aria-hidden="true"
            />
            {{ ALERT_EVENT_LABEL[event.type] }}
        </span>

        <div class="flex min-w-0 flex-col gap-0.5">
            <div class="flex min-w-0 items-center gap-2">
                <span class="text-xs font-medium text-foreground md:hidden">{{
                    ALERT_EVENT_LABEL[event.type]
                }}</span>
                <Link
                    v-if="ruleHref"
                    :href="ruleHref"
                    class="truncate text-sm font-medium text-foreground hover:underline hover:underline-offset-4"
                    >{{ event.ruleName }}</Link
                >
                <span v-else class="truncate text-sm text-muted-foreground">{{
                    event.ruleName ?? (isRuleEvent ? 'Deleted rule' : 'Issue')
                }}</span>
                <span
                    v-if="Object.keys(event.labels).length"
                    class="truncate font-mono text-xs text-foreground/75"
                    >{{ formatLabels(event.labels) }}</span
                >
            </div>
            <p class="line-clamp-2 text-xs text-muted-foreground">
                {{ event.headline }}
            </p>
        </div>

        <span
            class="hidden pt-0.5 text-right font-mono text-sm text-foreground tabular-nums md:block"
            >{{ value ?? '' }}</span
        >

        <a
            v-if="event.href"
            :href="event.href"
            class="inline-flex items-center justify-end gap-1 pt-0.5 text-xs text-muted-foreground transition-colors hover:text-foreground"
            data-test="alert-event-explore"
        >
            Explore
            <ArrowUpRight class="size-3" aria-hidden="true" />
        </a>
        <span v-else />
    </div>
</template>
