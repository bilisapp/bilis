<script setup lang="ts">
import { Layers } from '@lucide/vue';
import { computed } from 'vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    allowanceBarClass,
    allowanceLevel,
    allowancePercent,
    allowanceTextClass,
} from '@/lib/plans';
import { pricing } from '@/routes';
import { show as contactShow } from '@/routes/contact';
import type { PlanAllowanceLevel, PlanUsage } from '@/types';

const props = defineProps<{
    usage: PlanUsage;
}>();

type Meter = {
    key: string;
    label: string;
    used: number;
    limit: number;
    caption: string;
    level: PlanAllowanceLevel;
    unavailable: boolean;
};

const meters = computed<Meter[]>(() => {
    const warnAt = props.usage.warnAtPercent;
    const events = props.usage.events;

    return [
        {
            key: 'projects',
            label: 'Projects',
            used: props.usage.projects.used,
            limit: props.usage.projects.limit,
            caption: 'sources shipping into this team',
            level: allowanceLevel(
                props.usage.projects.used,
                props.usage.projects.limit,
                warnAt,
            ),
            unavailable: false,
        },
        {
            key: 'members',
            label: 'Members',
            used: props.usage.members.used,
            limit: props.usage.members.limit,
            caption: 'people in the team, the owner included',
            level: allowanceLevel(
                props.usage.members.used,
                props.usage.members.limit,
                warnAt,
            ),
            unavailable: false,
        },
        {
            key: 'events',
            label: 'Events today',
            used: events.used,
            limit: events.limit,
            caption: 'logs + spans since 00:00 UTC',
            level: allowanceLevel(events.used, events.limit, warnAt),
            unavailable: events.unavailable,
        },
    ];
});

/**
 * The worst state any meter is in, which decides what the card says at the
 * bottom. An unavailable event count is not a state: not knowing is not the
 * same as being over, and saying so would be inventing a number.
 */
const overall = computed<PlanAllowanceLevel>(() => {
    const levels = meters.value
        .filter((meter) => !meter.unavailable)
        .map((meter) => meter.level);

    if (levels.includes('over')) {
        return 'over';
    }

    return levels.includes('warn') ? 'warn' : 'ok';
});

const upgradeHref = computed(() =>
    contactShow.url({ query: { topic: 'upgrade' } }),
);
</script>

<template>
    <Card data-test="dashboard-plan-usage">
        <CardHeader>
            <CardTitle class="flex items-center justify-between gap-2">
                <span class="flex items-center gap-2">
                    <span
                        class="flex size-8 items-center justify-center rounded-full bg-muted text-muted-foreground"
                    >
                        <Layers class="size-4" />
                    </span>
                    Plan &amp; usage
                </span>
                <span
                    class="rounded-md border border-border px-1.5 py-0.5 font-mono text-[11px] tracking-[0.14em] text-muted-foreground uppercase"
                    data-test="dashboard-plan-badge"
                >
                    Free
                </span>
            </CardTitle>

            <CardDescription>
                What this team is using of the Free allowances.
                <!--
                  Said before the numbers rather than after them: the meters
                  are a report, not a gate, and a reader who does not know
                  that reads a full bar as an outage waiting to happen.
                -->
                Every limit here is soft — nothing is dropped and nothing is
                blocked when one is passed.
            </CardDescription>
        </CardHeader>

        <CardContent>
            <ul class="flex flex-col gap-3">
                <li
                    v-for="meter in meters"
                    :key="meter.key"
                    class="flex flex-col gap-1"
                    :data-test="`dashboard-plan-${meter.key}`"
                >
                    <div
                        class="flex items-baseline justify-between gap-2 text-sm"
                    >
                        <span class="min-w-0 truncate">
                            <span class="font-medium">{{ meter.label }}</span>
                            <span class="text-muted-foreground">
                                · {{ meter.caption }}
                            </span>
                        </span>
                        <span
                            class="shrink-0 font-mono text-xs tabular-nums"
                            :class="
                                meter.unavailable
                                    ? 'text-muted-foreground'
                                    : allowanceTextClass(meter.level)
                            "
                        >
                            <template v-if="meter.unavailable">
                                not measurable
                            </template>
                            <template v-else>
                                {{ meter.used.toLocaleString() }} /
                                {{ meter.limit.toLocaleString() }}
                            </template>
                        </span>
                    </div>
                    <div
                        class="h-1.5 overflow-hidden rounded-full bg-muted"
                        role="presentation"
                    >
                        <div
                            v-if="!meter.unavailable"
                            class="h-full rounded-full"
                            :class="allowanceBarClass(meter.level)"
                            :style="{
                                width: `${allowancePercent(meter.used, meter.limit)}%`,
                            }"
                        />
                    </div>
                </li>
            </ul>

            <p
                class="mt-3 text-xs text-muted-foreground"
                data-test="dashboard-plan-facts"
            >
                {{ usage.retentionDays }}-day retention ·
                {{ usage.requestsPerMinute.toLocaleString() }}/min per key
            </p>

            <p
                v-if="usage.events.unavailable"
                class="mt-2 text-xs text-muted-foreground"
                data-test="dashboard-plan-unavailable"
            >
                Today's event count could not be read from ClickHouse just now.
                The other meters are exact.
            </p>

            <p
                v-else-if="overall === 'over'"
                class="mt-2 text-xs text-muted-foreground"
                data-test="dashboard-plan-over"
            >
                <span class="text-severity-error"
                    >Over the Free allowance.</span
                >
                Nothing is being dropped and nothing is blocked — talk to us
                about a Team plan.
                <a
                    :href="upgradeHref"
                    class="underline underline-offset-2 hover:text-foreground"
                >
                    Get in touch
                </a>
                or
                <a
                    :href="pricing.url()"
                    class="underline underline-offset-2 hover:text-foreground"
                >
                    read the plans</a
                >.
            </p>

            <p
                v-else-if="overall === 'warn'"
                class="mt-2 text-xs text-muted-foreground"
                data-test="dashboard-plan-warn"
            >
                <span class="text-severity-warn">
                    Close to the Free allowance.
                </span>
                Passing it changes nothing on its own —
                <a
                    :href="upgradeHref"
                    class="underline underline-offset-2 hover:text-foreground"
                >
                    tell us what you run </a
                >when you want more room.
            </p>
        </CardContent>
    </Card>
</template>
