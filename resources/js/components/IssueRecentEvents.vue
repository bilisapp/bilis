<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import PageSection from '@/components/PageSection.vue';
import StorageBusy from '@/components/StorageBusy.vue';
import SurfaceMessage from '@/components/SurfaceMessage.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { ISSUE_EVENT_PROPS, issueHref } from '@/lib/issues';
import { formatTimestamp } from '@/lib/logs';
import { cn } from '@/lib/utils';
import type { IssueEventList } from '@/types';

/**
 * An issue's newest occurrences as a table, each timestamp a link that opens
 * that event in place (only the event reloads). The one on screen is shaded.
 */
const props = defineProps<{
    /** Deferred: undefined while it loads. */
    events?: IssueEventList;
    teamSlug: string;
    projectSlug: string;
    fingerprint: string;
    /** The event on screen, to mark its row. */
    current: { timestamp: string; eventKey: string | null } | null;
}>();

const eventHref = (timestamp: string, eventKey: string): string =>
    issueHref(props.teamSlug, props.projectSlug, props.fingerprint, {
        position: 'at',
        event: timestamp,
        eventKey,
    });

const isCurrent = (timestamp: string, eventKey: string): boolean =>
    props.current?.timestamp === timestamp &&
    props.current.eventKey === eventKey;

const COLUMN_HEADER =
    'text-left text-label tracking-label text-muted-foreground uppercase';
</script>

<template>
    <PageSection
        title="Recent events"
        :note="
            events && events.rows.length > 0
                ? `newest ${events.rows.length}`
                : undefined
        "
        flush
        data-test="issue-recent-events"
    >
        <div v-if="!events" class="flex flex-col gap-2 px-4 py-4 md:px-10">
            <Skeleton v-for="index in 4" :key="index" class="h-8 w-full" />
        </div>
        <StorageBusy v-else-if="events.unavailable" store="Event storage" />
        <SurfaceMessage v-else-if="events.rows.length === 0">
            No events within the last 30 days.
        </SurfaceMessage>
        <div v-else class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead :class="COLUMN_HEADER">
                    <tr class="border-b bg-muted/40">
                        <th class="py-2.5 pr-3 pl-4 font-medium md:pl-10">
                            Time
                        </th>
                        <th class="px-3 py-2.5 font-medium">Service</th>
                        <th class="px-3 py-2.5 font-medium">Release</th>
                        <th class="px-3 py-2.5 font-medium">Environment</th>
                        <th class="px-3 py-2.5 font-medium">User</th>
                        <th class="py-2.5 pr-4 pl-3 font-medium md:pr-10">
                            Message
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="summary in events.rows"
                        :key="`${summary.timestamp}:${summary.eventKey}`"
                        :class="
                            cn(
                                'h-11 border-b border-border/60 transition-colors hover:bg-muted/50',
                                isCurrent(
                                    summary.timestamp,
                                    summary.eventKey,
                                ) && 'bg-muted/70',
                            )
                        "
                    >
                        <td
                            class="pr-3 pl-4 font-mono whitespace-nowrap md:pl-10"
                        >
                            <Link
                                :href="
                                    eventHref(
                                        summary.timestamp,
                                        summary.eventKey,
                                    )
                                "
                                :only="ISSUE_EVENT_PROPS"
                                preserve-scroll
                                class="font-medium text-foreground underline-offset-4 hover:underline"
                            >
                                {{ formatTimestamp(summary.timestamp) }}
                            </Link>
                        </td>
                        <td
                            class="px-3 font-mono whitespace-nowrap text-foreground/80"
                        >
                            {{ summary.serviceName || '—' }}
                        </td>
                        <td
                            class="px-3 font-mono whitespace-nowrap text-foreground/80"
                        >
                            {{ summary.release || '—' }}
                        </td>
                        <td class="px-3 whitespace-nowrap text-foreground/80">
                            {{ summary.environment || '—' }}
                        </td>
                        <td
                            class="max-w-40 truncate px-3 font-mono text-foreground/80"
                            :title="summary.user"
                        >
                            {{ summary.user || '—' }}
                        </td>
                        <td
                            class="max-w-80 truncate pr-4 pl-3 text-muted-foreground md:pr-10"
                            :title="summary.message"
                        >
                            {{ summary.message }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </PageSection>
</template>
