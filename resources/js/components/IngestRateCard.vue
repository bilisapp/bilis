<script setup lang="ts">
import { Gauge } from '@lucide/vue';
import { computed } from 'vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type { IngestRateKey, IngestRateUsage } from '@/types';

const props = defineProps<{
    usage: IngestRateUsage;
}>();

/**
 * How full a key's per-minute budget is, as a percentage of the limit.
 *
 * Floored at a visible sliver for any key that spent anything at all — a
 * single request against a 1200/min budget is 0.08% and would otherwise draw
 * nothing, and "it is being used" is the first thing the row has to say.
 */
function usedPercent(key: IngestRateKey): number {
    if (props.usage.disabled || props.usage.limit <= 0) {
        return 0;
    }

    const share = (key.attempts / props.usage.limit) * 100;

    if (share <= 0) {
        return 0;
    }

    return Math.min(100, Math.max(2, share));
}

/**
 * The bar's hue.
 *
 * Throughput is data, so the resting bar takes a chart token. The two upper
 * states borrow the severity ramp deliberately: a key at its ceiling is being
 * answered with 429s, and the ramp is the same one the rejected client's own
 * log lines will arrive on. Everything below 80% stays neutral-of-meaning.
 */
function barClass(key: IngestRateKey): string {
    if (props.usage.disabled || props.usage.limit <= 0) {
        return 'bg-muted-foreground/40';
    }

    const share = (key.attempts / props.usage.limit) * 100;

    if (share >= 100) {
        return 'bg-severity-error';
    }

    if (share >= 80) {
        return 'bg-severity-warn';
    }

    return 'bg-chart-2';
}

const totalAttempts = computed(() =>
    props.usage.keys.reduce((total, key) => total + key.attempts, 0),
);
</script>

<template>
    <Card data-test="dashboard-ingest-rate">
        <CardHeader>
            <CardTitle class="flex items-center justify-between gap-2">
                <span class="flex items-center gap-2">
                    <span
                        class="flex size-8 items-center justify-center rounded-full bg-muted text-muted-foreground"
                    >
                        <Gauge class="size-4" />
                    </span>
                    Ingest rate limit
                </span>
                <span
                    v-if="!usage.disabled"
                    class="font-mono text-sm text-muted-foreground"
                    data-test="dashboard-ingest-rate-limit"
                >
                    {{ usage.limit.toLocaleString() }}/min per key
                </span>
            </CardTitle>

            <CardDescription>
                Ingest requests each API key has spent in the current minute.
                <!--
                  Said plainly, because the number is a rolling one-minute
                  counter that resets under the reader: it is a throughput
                  reading, not a total, and it is never charted over time.
                -->
                The window is the limiter's own rolling minute, so these numbers
                rise and reset as you watch.
            </CardDescription>
        </CardHeader>

        <CardContent>
            <p
                v-if="usage.keys.length === 0"
                class="text-sm text-muted-foreground"
                data-test="dashboard-ingest-rate-empty"
            >
                No API keys yet. Create one on a project and the shipper it
                belongs to shows up here.
            </p>

            <template v-else>
                <p
                    v-if="usage.disabled"
                    class="mb-3 text-sm text-muted-foreground"
                    data-test="dashboard-ingest-rate-disabled"
                >
                    The ingest limiter is turned off (<span class="font-mono"
                        >BILIS_INGEST_RATE_LIMIT=0</span
                    >), so nothing is counted and no request is ever throttled.
                </p>

                <ul class="flex flex-col gap-3">
                    <li
                        v-for="key in usage.keys"
                        :key="`${key.projectSlug}:${key.keyPrefix}`"
                        class="flex flex-col gap-1"
                        data-test="dashboard-ingest-rate-key"
                    >
                        <div
                            class="flex items-baseline justify-between gap-2 text-sm"
                        >
                            <span class="min-w-0 truncate">
                                <span class="font-medium">
                                    {{ key.project }}
                                </span>
                                <span class="text-muted-foreground">
                                    · {{ key.name }}
                                </span>
                            </span>
                            <span
                                class="shrink-0 font-mono text-xs text-muted-foreground tabular-nums"
                            >
                                <template v-if="usage.disabled">
                                    no limit
                                </template>
                                <template v-else>
                                    {{ key.attempts.toLocaleString() }} /
                                    {{ usage.limit.toLocaleString() }} this
                                    minute
                                </template>
                            </span>
                        </div>
                        <div
                            class="h-1.5 overflow-hidden rounded-full bg-muted"
                            role="presentation"
                        >
                            <div
                                class="h-full rounded-full"
                                :class="barClass(key)"
                                :style="{ width: `${usedPercent(key)}%` }"
                            />
                        </div>
                    </li>
                </ul>

                <p
                    v-if="!usage.disabled"
                    class="mt-3 text-xs text-muted-foreground"
                    data-test="dashboard-ingest-rate-total"
                >
                    {{ totalAttempts.toLocaleString() }} ingest
                    {{ totalAttempts === 1 ? 'request' : 'requests' }} this
                    minute across every key. A key that reaches its ceiling is
                    answered with a retryable 429, never a rejection of its
                    payload.
                </p>
            </template>
        </CardContent>
    </Card>
</template>
