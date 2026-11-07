<script setup lang="ts">
import { computed } from 'vue';
import PageSection from '@/components/PageSection.vue';
import StackTrace from '@/components/StackTrace.vue';
import { eventCauses, eventFrames } from '@/lib/issues';
import type { LogEntry } from '@/types';

/**
 * One event's exception as a page section: the stack, most recent call
 * first, then each cause behind it, nearest first.
 */
const props = defineProps<{
    row: LogEntry;
    /** The printed stack, used when the event carried no structured frames. */
    stacktrace: string;
    class?: string;
}>();

const frames = computed(() => eventFrames(props.row, props.stacktrace));

const causes = computed(() => eventCauses(props.row, props.stacktrace));
</script>

<template>
    <PageSection
        title="Stack trace"
        note="most recent call first"
        :class="props.class"
    >
        <StackTrace :frames="frames" :raw="stacktrace" />

        <div
            v-for="(cause, index) in causes"
            :key="index"
            class="mt-6 flex flex-col gap-1.5 border-t pt-4"
            data-test="issue-cause"
        >
            <p class="eyebrow">Caused by</p>
            <p class="font-mono text-sm font-semibold">
                {{ cause.type || 'Exception' }}
            </p>
            <p
                v-if="cause.message"
                class="text-sm break-words text-foreground/80"
            >
                {{ cause.message }}
            </p>
            <p
                v-if="cause.origin"
                class="font-mono text-xs text-muted-foreground"
            >
                {{ cause.origin }}
            </p>
            <pre
                v-if="cause.stacktrace"
                class="mt-1.5 max-h-48 overflow-auto rounded-lg border bg-muted/40 p-3 font-mono text-xs whitespace-pre"
                >{{ cause.stacktrace }}</pre>
        </div>
    </PageSection>
</template>
