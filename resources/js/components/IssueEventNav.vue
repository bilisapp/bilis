<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    ChevronFirst,
    ChevronLast,
    ChevronLeft,
    ChevronRight,
    ScrollText,
    Waypoints,
} from '@lucide/vue';
import { computed } from 'vue';
import type { Component } from 'vue';
import { Button } from '@/components/ui/button';
import { ISSUE_EVENT_PROPS, issueHref } from '@/lib/issues';
import { formatTimestamp, parseTimestamp } from '@/lib/logs';
import { traceHref } from '@/lib/traces';
import { cn } from '@/lib/utils';
import { index as logsIndex } from '@/routes/logs';
import type { IssueEvent } from '@/types';

/**
 * Which occurrence of an issue is on screen, the way out to its logs and its
 * trace, and the stepper through the others.
 *
 * Every step is a link, so an event is shareable, and every link reloads only
 * the event ({@link ISSUE_EVENT_PROPS}).
 */
const props = defineProps<{
    event: IssueEvent;
    teamSlug: string;
    projectSlug: string;
    fingerprint: string;
}>();

const row = computed(() => props.event.row);

type Position = 'oldest' | 'older' | 'newer' | 'latest';

const href = (position: Position): string =>
    issueHref(
        props.teamSlug,
        props.projectSlug,
        props.fingerprint,
        position === 'latest'
            ? {}
            : {
                  position,
                  event: row.value?.timestamp ?? null,
                  eventKey: props.event.eventKey,
              },
    );

const steps = computed<
    { position: Position; icon: Component; label: string; enabled: boolean }[]
>(() => [
    {
        position: 'oldest',
        icon: ChevronFirst,
        label: 'Oldest event',
        enabled: props.event.hasOlder,
    },
    {
        position: 'older',
        icon: ChevronLeft,
        label: 'Older event',
        enabled: props.event.hasOlder,
    },
    {
        position: 'newer',
        icon: ChevronRight,
        label: 'Newer event',
        enabled: props.event.hasNewer,
    },
    {
        position: 'latest',
        icon: ChevronLast,
        label: 'Latest event',
        enabled: props.event.hasNewer,
    },
]);

/**
 * The log stream around this event: the same service, a minute either side,
 * so the lines that led up to the exception are on screen.
 */
const logsHref = computed(() => {
    if (!row.value) {
        return null;
    }

    const at = parseTimestamp(row.value.timestamp).getTime();

    return logsIndex(props.teamSlug, {
        query: {
            project: props.projectSlug,
            service: row.value.serviceName || undefined,
            from: new Date(at - 60_000).toISOString(),
            to: new Date(at + 60_000).toISOString(),
        },
    }).url;
});

const traceLink = computed(() =>
    row.value?.traceId
        ? traceHref(props.teamSlug, row.value.traceId, {
              ts: row.value.timestamp,
              span: row.value.spanId || null,
          })
        : null,
);

/** The stepper's segments: a link when there is somewhere to go. */
const STEP =
    'inline-flex size-[26px] items-center justify-center rounded-[5px] transition-colors';
const STEP_ON = 'text-foreground hover:bg-card hover:ring-1 hover:ring-border';
const STEP_OFF = 'text-muted-foreground/40';
</script>

<template>
    <div
        class="flex flex-wrap items-center justify-between gap-3 border-b bg-muted/40 px-4 py-2.5 md:px-10"
        data-test="issue-event-nav"
    >
        <p class="min-w-0 truncate text-xs text-muted-foreground">
            <span class="font-medium text-foreground">Event</span>
            <template v-if="row">
                ·
                <span class="font-mono text-foreground/80">{{
                    formatTimestamp(row.timestamp)
                }}</span>
                <template v-if="row.logAttributes['event.id']">
                    ·
                    <span class="font-mono">{{
                        row.logAttributes['event.id'].slice(0, 12)
                    }}</span>
                </template>
            </template>
            <template v-else> · —</template>
        </p>

        <div class="flex flex-wrap items-center gap-1">
            <Button
                v-if="logsHref"
                as-child
                size="sm"
                variant="ghost"
                class="text-muted-foreground"
            >
                <Link :href="logsHref" data-test="issue-event-logs">
                    <ScrollText class="size-3.5" /> Logs around it
                </Link>
            </Button>
            <Button
                v-if="traceLink"
                as-child
                size="sm"
                variant="ghost"
                class="text-muted-foreground"
            >
                <Link :href="traceLink" data-test="issue-event-trace">
                    <Waypoints class="size-3.5" /> Trace
                </Link>
            </Button>

            <div
                class="ml-1 inline-flex h-8 items-center gap-0.5 rounded-md bg-muted p-[3px]"
            >
                <component
                    :is="step.enabled ? Link : 'span'"
                    v-for="step in steps"
                    :key="step.position"
                    v-bind="
                        step.enabled
                            ? {
                                  href: href(step.position),
                                  preserveScroll: true,
                                  only: ISSUE_EVENT_PROPS,
                              }
                            : {}
                    "
                    :class="cn(STEP, step.enabled ? STEP_ON : STEP_OFF)"
                    :aria-label="step.label"
                    :title="step.label"
                    :data-test="`issue-event-${step.position}`"
                >
                    <component :is="step.icon" class="size-4" />
                </component>
            </div>
        </div>
    </div>
</template>
