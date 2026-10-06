<script setup lang="ts">
import { Skeleton } from '@/components/ui/skeleton';
import { formatRelativeTime } from '@/lib/logs';
import { formatRatio, ratioMagnitude } from '@/lib/metrics';
import { MAGNITUDE_BG_CLASS } from '@/lib/traces';
import { cn } from '@/lib/utils';
import type { HostList, HostRow } from '@/types';

/**
 * Every machine reporting host metrics, one row each, with how it is doing
 * now: CPU, memory and the fullest disk as meters, load, containers, and
 * when it was last heard from.
 *
 * The meters spend the magnitude ramp on fixed thresholds — size, not
 * meaning — so a host at 92 % disk reads the same on every visit. The row
 * names a host; picking it is the page's business, so the table only emits.
 */
const props = defineProps<{
    /** Deferred: absent while the list loads. */
    hosts?: HostList;
    /** The host whose charts are drawn below. */
    selected: string | null;
}>();

const emit = defineEmits<{
    (event: 'select', host: string): void;
}>();

/** The meters a row draws, in column order. */
function meters(
    host: HostRow,
): { id: string; label: string; value: number | null; note: string | null }[] {
    return [
        { id: 'cpu', label: 'CPU', value: host.cpu, note: null },
        { id: 'memory', label: 'Memory', value: host.memory, note: null },
        { id: 'disk', label: 'Disk', value: host.disk, note: host.diskMount },
    ];
}

/** A host silent for the last few minutes of the window has no "now". */
function isQuiet(host: HostRow): boolean {
    return host.cpu === null && host.memory === null && host.load === null;
}

function hostLabel(host: HostRow): string {
    return host.name === '' ? '(no host.name)' : host.name;
}

const isSelected = (host: HostRow) => host.name === props.selected;
</script>

<template>
    <section
        class="flex flex-col rounded-lg border bg-card"
        aria-label="Hosts"
        data-test="hosts-table"
    >
        <div v-if="!hosts" class="flex flex-col gap-2 p-4">
            <Skeleton class="h-10 w-full animate-pulse" />
            <Skeleton class="h-10 w-full animate-pulse" />
        </div>

        <p
            v-else-if="hosts.unavailable"
            class="p-4 text-sm text-muted-foreground"
            data-test="hosts-unavailable"
        >
            Metric storage is busy and could not answer in time. Nothing is lost
            — retry in a moment.
        </p>

        <p
            v-else-if="hosts.hosts.length === 0"
            class="p-4 text-sm text-muted-foreground"
            data-test="hosts-empty-window"
        >
            No host reported in this window. Widen it, or check the project
            filter.
        </p>

        <div v-else class="overflow-x-auto">
            <table class="w-full min-w-[44rem] text-sm">
                <thead>
                    <tr
                        class="border-b text-left text-xs text-muted-foreground"
                    >
                        <th class="px-4 py-2 font-medium">Host</th>
                        <th class="px-3 py-2 font-medium">CPU</th>
                        <th class="px-3 py-2 font-medium">Memory</th>
                        <th class="px-3 py-2 font-medium">Disk</th>
                        <th class="px-3 py-2 text-right font-medium">Load</th>
                        <th class="px-3 py-2 text-right font-medium">
                            Containers
                        </th>
                        <th class="px-4 py-2 text-right font-medium">
                            Last seen
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="host in hosts.hosts"
                        :key="host.name"
                        :class="
                            cn(
                                'cursor-pointer border-b transition-colors last:border-0 hover:bg-accent/50',
                                isSelected(host) && 'bg-accent',
                            )
                        "
                        :aria-selected="isSelected(host)"
                        :data-test="`hosts-row-${host.name}`"
                        @click="emit('select', host.name)"
                    >
                        <td class="px-4 py-2.5">
                            <button
                                type="button"
                                class="truncate rounded-sm font-mono text-sm font-medium focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                                :aria-pressed="isSelected(host)"
                                @click.stop="emit('select', host.name)"
                            >
                                {{ hostLabel(host) }}
                            </button>
                        </td>
                        <td
                            v-for="meter in meters(host)"
                            :key="meter.id"
                            class="px-3 py-2.5"
                            :data-test="`hosts-${meter.id}`"
                        >
                            <div class="flex w-32 flex-col gap-1">
                                <div
                                    class="flex items-baseline justify-between gap-2"
                                >
                                    <span class="tabular-nums">
                                        {{ formatRatio(meter.value) }}
                                    </span>
                                    <span
                                        v-if="meter.note"
                                        class="truncate font-mono text-xs text-muted-foreground"
                                        :title="meter.note"
                                    >
                                        {{ meter.note }}
                                    </span>
                                </div>
                                <div
                                    class="h-1 w-full overflow-hidden rounded-full bg-muted"
                                    role="meter"
                                    :aria-label="`${meter.label} ${formatRatio(meter.value)}`"
                                    aria-valuemin="0"
                                    aria-valuemax="100"
                                    :aria-valuenow="
                                        meter.value === null
                                            ? undefined
                                            : Math.round(meter.value * 100)
                                    "
                                >
                                    <div
                                        v-if="meter.value !== null"
                                        :class="
                                            cn(
                                                'h-full rounded-full',
                                                MAGNITUDE_BG_CLASS[
                                                    ratioMagnitude(meter.value)
                                                ],
                                            )
                                        "
                                        :style="{
                                            width: `${Math.max(2, meter.value * 100)}%`,
                                        }"
                                    />
                                </div>
                            </div>
                        </td>
                        <td class="px-3 py-2.5 text-right tabular-nums">
                            {{
                                host.load === null ? '—' : host.load.toFixed(2)
                            }}
                        </td>
                        <td class="px-3 py-2.5 text-right tabular-nums">
                            {{ host.containers > 0 ? host.containers : '—' }}
                        </td>
                        <td
                            :class="
                                cn(
                                    'px-4 py-2.5 text-right text-xs tabular-nums',
                                    isQuiet(host)
                                        ? 'font-medium text-foreground'
                                        : 'text-muted-foreground',
                                )
                            "
                            :title="host.lastSeen"
                            data-test="hosts-last-seen"
                        >
                            {{ formatRelativeTime(host.lastSeen) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
