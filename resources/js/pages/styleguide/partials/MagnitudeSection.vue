<script setup lang="ts">
import {
    durationClass,
    formatDuration,
    MAGNITUDE_BG_CLASS,
    MAGNITUDE_TEXT_CLASS,
    magnitudeLevel,
} from '@/lib/traces';
import { cn } from '@/lib/utils';

/** One row per step, with the boundary that puts a value in it. */
const STEPS = [
    {
        level: 1 as const,
        range: 'under 50 ms',
        meaning: 'Local work — a cache hit, a lookup, a function call.',
        sample: 4.2,
    },
    {
        level: 2 as const,
        range: '50 ms – 500 ms',
        meaning: 'A call that still feels instant to the person who made it.',
        sample: 252,
    },
    {
        level: 3 as const,
        range: '500 ms – 5 s',
        meaning: 'A request somebody is waiting through.',
        sample: 3110,
    },
    {
        level: 4 as const,
        range: '5 s and up',
        meaning: 'A request somebody has given up on.',
        sample: 235893,
    },
];

/** A ladder of durations, to show the ramp doing its actual job. */
const LADDER = [0.6, 4.2, 38, 252, 1877, 3110, 13780, 235893];
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="overflow-x-auto rounded-lg border bg-card">
            <table class="w-full text-sm">
                <thead
                    class="border-b bg-muted/40 text-left text-xs text-muted-foreground"
                >
                    <tr>
                        <th class="px-4 py-2 font-medium">Step</th>
                        <th class="px-4 py-2 font-medium">Sample</th>
                        <th class="px-4 py-2 font-medium">Range</th>
                        <th class="px-4 py-2 font-medium">Utility</th>
                        <th class="px-4 py-2 font-medium">Means</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="step in STEPS"
                        :key="step.level"
                        class="border-b last:border-b-0"
                    >
                        <td class="px-4 py-3">
                            <span
                                :class="
                                    cn(
                                        'block h-3 w-8 rounded-sm',
                                        MAGNITUDE_BG_CLASS[step.level],
                                    )
                                "
                            />
                        </td>
                        <td class="px-4 py-3">
                            <span
                                :class="
                                    cn(
                                        'font-mono text-xs tabular-nums',
                                        MAGNITUDE_TEXT_CLASS[step.level],
                                    )
                                "
                            >
                                {{ formatDuration(step.sample) }}
                            </span>
                        </td>
                        <td
                            class="px-4 py-3 font-mono text-xs text-muted-foreground"
                        >
                            {{ step.range }}
                        </td>
                        <td
                            class="px-4 py-3 font-mono text-xs text-muted-foreground"
                        >
                            text-magnitude-{{ step.level }}
                        </td>
                        <td class="px-4 py-3 text-xs text-muted-foreground">
                            {{ step.meaning }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!--
          The ramp in the form it is actually used in: a column of durations,
          right-aligned and tabular, where the slow ones surface without being
          hunted for.
        -->
        <div class="max-w-xs rounded-lg border bg-card p-4">
            <p class="mb-2 text-xs text-muted-foreground">
                A column of durations, as the trace list draws it
            </p>
            <dl class="flex flex-col gap-1">
                <div
                    v-for="value in LADDER"
                    :key="value"
                    class="flex items-baseline justify-between gap-4"
                >
                    <dt class="font-mono text-xs text-muted-foreground">
                        level {{ magnitudeLevel(value) }}
                    </dt>
                    <dd
                        :class="
                            cn(
                                'font-mono text-xs tabular-nums',
                                durationClass(value),
                            )
                        "
                    >
                        {{ formatDuration(value) }}
                    </dd>
                </div>
            </dl>
        </div>
    </div>
</template>
