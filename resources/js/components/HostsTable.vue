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

/**
 * One grid for the column header and every row, so the lanes line up: host,
 * three meters, then the right-aligned figures.
 */
const LANES =
    'grid grid-cols-[minmax(8rem,1fr)_6.5rem_6.5rem_8.5rem_3.5rem_5.5rem_5.5rem] items-center gap-x-5 px-4 md:px-10';
</script>

<template>
    <section
        class="flex flex-col border-t"
        aria-label="Hosts"
        data-test="hosts-table"
    >
        <div class="overflow-x-auto">
            <div class="min-w-[56.5rem]">
                <header :class="cn(LANES, 'border-b column-header py-2.5')">
                    <span>Host</span>
                    <span>CPU</span>
                    <span>Memory</span>
                    <span>Disk</span>
                    <span class="text-right">Load</span>
                    <span class="text-right">Containers</span>
                    <span class="text-right">Last seen</span>
                </header>

                <div
                    v-if="!hosts"
                    class="flex flex-col gap-3 px-4 py-4 md:px-10"
                >
                    <Skeleton class="h-9 w-full animate-pulse" />
                    <Skeleton class="h-9 w-full animate-pulse" />
                </div>

                <p
                    v-else-if="hosts.unavailable"
                    class="px-4 py-8 text-sm text-muted-foreground md:px-10"
                    data-test="hosts-unavailable"
                >
                    Metric storage is busy and could not answer in time. Nothing
                    is lost — retry in a moment.
                </p>

                <p
                    v-else-if="hosts.hosts.length === 0"
                    class="px-4 py-8 text-sm text-muted-foreground md:px-10"
                    data-test="hosts-empty-window"
                >
                    No host reported in this window. Widen it, or check the
                    project filter.
                </p>

                <template v-else>
                    <div
                        v-for="host in hosts.hosts"
                        :key="host.name"
                        :class="
                            cn(
                                LANES,
                                'cursor-pointer border-b border-border/70 py-3.5 text-sm transition-colors hover:bg-muted/50',
                                isSelected(host) &&
                                    'bg-muted/70 hover:bg-muted/70',
                            )
                        "
                        :aria-selected="isSelected(host)"
                        :data-test="`hosts-row-${host.name}`"
                        @click="emit('select', host.name)"
                    >
                        <button
                            type="button"
                            class="min-w-0 justify-self-start truncate rounded-sm text-left font-mono text-sm font-medium text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                            :aria-pressed="isSelected(host)"
                            @click.stop="emit('select', host.name)"
                        >
                            {{ hostLabel(host) }}
                        </button>

                        <div
                            v-for="meter in meters(host)"
                            :key="meter.id"
                            class="flex min-w-0 flex-col gap-1.5"
                            :data-test="`hosts-${meter.id}`"
                        >
                            <div
                                class="flex items-baseline justify-between gap-2"
                            >
                                <span class="font-mono text-sm tabular-nums">
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

                        <span class="text-right font-mono tabular-nums">
                            {{
                                host.load === null ? '—' : host.load.toFixed(2)
                            }}
                        </span>
                        <span class="text-right font-mono tabular-nums">
                            {{ host.containers > 0 ? host.containers : '—' }}
                        </span>
                        <span
                            :class="
                                cn(
                                    'text-right font-mono text-xs tabular-nums',
                                    isQuiet(host)
                                        ? 'font-medium text-foreground'
                                        : 'text-muted-foreground',
                                )
                            "
                            :title="host.lastSeen"
                            data-test="hosts-last-seen"
                        >
                            {{ formatRelativeTime(host.lastSeen) }}
                        </span>
                    </div>
                </template>
            </div>
        </div>
    </section>
</template>
