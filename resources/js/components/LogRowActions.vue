<script setup lang="ts">
import { Check, Copy, Waypoints, Wrench } from '@lucide/vue';
import { useClipboard } from '@vueuse/core';
import { computed } from 'vue';
import AskAiMenu from '@/components/AskAiMenu.vue';
import AutofixUpsellModal from '@/components/AutofixUpsellModal.vue';
import RunAutofixModal from '@/components/RunAutofixModal.vue';
import { formatLogEntry } from '@/lib/logRow';
import type { LogAutofixTarget, LogEntry, TeamLlmCredential } from '@/types';

/**
 * What a reader can do with one log line without leaving the stream.
 *
 * Four things, in the order they are reached for: take the line somewhere
 * else, preview the trace it belongs to, ask an assistant about it, or hand it
 * to the agent. The trace button only appears when the line actually carries a
 * TraceId — most do not, and an always-present dead button teaches nothing. It
 * opens a panel beside the stream rather than navigating, because a full page
 * would cost the reader their place in the log they were reading. The cluster is
 * quiet until the row is hovered or focused so it never competes with the log
 * text — every action still has a keyboard route through the row itself.
 *
 * The autofix button is present whether or not a repository is connected. Its
 * two states answer different questions — "run this" and "what would this
 * do?" — and a button that disappears teaches nobody the feature exists.
 */
const props = defineProps<{
    entry: LogEntry;
    teamSlug: string;
    /**
     * What this line resolves to. Null when autofix is switched off for the
     * deployment, which is the one case where no fix affordance is shown at
     * all: there is nothing to connect.
     */
    autofix?: LogAutofixTarget | null;
    credentials?: TeamLlmCredential[];
}>();

const emit = defineEmits<{
    (event: 'copied'): void;
    /**
     * Open this line's trace. The row asks; the page decides — it owns the
     * panel, so a row that navigated itself would take the reader out of the
     * stream it is trying to keep them in.
     */
    (event: 'trace', traceId: string): void;
}>();

const { copy, copied } = useClipboard({
    copiedDuring: 1_500,
    // navigator.clipboard needs a secure context; self-hosted installs often
    // run plain http, so fall back to the legacy execCommand path there.
    legacy: true,
});

const repository = computed(() => props.autofix?.repository ?? null);

/**
 * Whether this line names a trace worth offering.
 *
 * Most lines do not, and an always-present dead button teaches nothing.
 */
const hasTrace = computed(
    () => props.entry.traceId !== '' && props.teamSlug !== '',
);

function copyRow() {
    copy(formatLogEntry(props.entry));
    emit('copied');
}
</script>

<template>
    <!--
      Positioning is the caller's business: in a log row this floats above the
      line, in the styleguide it sits in the flow. It only owns its own layout.
    -->
    <div class="flex items-center gap-0.5" data-test="log-row-actions">
        <button
            type="button"
            class="inline-flex size-6 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
            :title="copied ? 'Copied' : 'Copy this line'"
            :aria-label="copied ? 'Copied' : 'Copy this line'"
            data-test="log-row-copy"
            @click="copyRow"
        >
            <component :is="copied ? Check : Copy" class="size-3.5" />
        </button>

        <button
            v-if="hasTrace"
            type="button"
            class="inline-flex size-6 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
            title="Preview the trace this line belongs to"
            aria-label="Preview the trace this line belongs to"
            data-test="log-row-trace"
            @click="emit('trace', entry.traceId)"
        >
            <Waypoints class="size-3.5" />
        </button>

        <AskAiMenu :entry="entry" />

        <template v-if="autofix">
            <RunAutofixModal
                v-if="repository"
                :entry="entry"
                :team-slug="teamSlug"
                :repository="repository"
                :credentials="credentials"
            >
                <button
                    type="button"
                    class="inline-flex items-center gap-1 rounded-md px-1.5 py-1 font-sans text-[11px] text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                    :title="`Ask the agent to fix this in ${repository}`"
                    data-test="log-row-autofix"
                >
                    <Wrench class="size-3.5" /> Fix
                </button>
            </RunAutofixModal>

            <AutofixUpsellModal
                v-else
                :entry="entry"
                :team-slug="teamSlug"
                :project-slug="autofix.project?.slug ?? null"
                :project-name="autofix.project?.name ?? null"
            >
                <button
                    type="button"
                    class="inline-flex items-center gap-1 rounded-md px-1.5 py-1 font-sans text-[11px] text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                    title="No repository is connected for this service yet"
                    data-test="log-row-autofix-upsell"
                >
                    <Wrench class="size-3.5" /> Fix
                </button>
            </AutofixUpsellModal>
        </template>
    </div>
</template>
