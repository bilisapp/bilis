<script setup lang="ts">
import { Skeleton } from '@/components/ui/skeleton';
import { formatCount, TAG_LABEL } from '@/lib/issues';
import type { IssueTagResult } from '@/types';

/**
 * Who and where an issue happens to: the top values of each tag across its
 * newest events (the server reads a bounded sample and says how many), as
 * shares of the events that carried the tag.
 *
 * A share is a length, so the bar is drawn in the neutral ladder: it is not
 * a category (no chart series) and not a duration (no magnitude). The number
 * is always printed beside it, so nothing depends on reading the length.
 */
defineProps<{
    tags?: IssueTagResult;
}>();

const share = (count: number, total: number) =>
    total > 0 ? Math.round((count / total) * 100) : 0;
</script>

<template>
    <div data-test="issue-tags">
        <div v-if="!tags" class="flex flex-col gap-3">
            <Skeleton v-for="index in 4" :key="index" class="h-12 w-full" />
        </div>

        <p v-else-if="tags.unavailable" class="text-sm text-muted-foreground">
            Tag counts are busy — retry in a moment.
        </p>

        <p
            v-else-if="tags.tags.length === 0"
            class="text-sm text-muted-foreground"
        >
            These events carry none of the tracked tags.
        </p>

        <dl v-else class="flex flex-col gap-5">
            <div
                v-for="tag in tags.tags"
                :key="tag.key"
                :data-test="`issue-tag-${tag.key}`"
            >
                <dt
                    class="mb-1.5 flex items-baseline justify-between gap-2 eyebrow"
                >
                    <span>{{ TAG_LABEL[tag.key] ?? tag.key }}</span>
                    <span class="font-mono tracking-normal tabular-nums">{{
                        formatCount(tag.total)
                    }}</span>
                </dt>
                <dd class="flex flex-col gap-1">
                    <div
                        v-for="value in tag.values"
                        :key="value.value"
                        class="relative flex h-6 items-center justify-between gap-2 overflow-hidden rounded-md px-2 text-xs"
                        :title="`${value.value}: ${value.count.toLocaleString()} events`"
                    >
                        <span
                            class="absolute inset-y-0 left-0 bg-muted"
                            :style="{
                                width: `${share(value.count, tag.total)}%`,
                            }"
                            aria-hidden="true"
                        />
                        <span
                            class="relative min-w-0 truncate font-mono text-foreground/80"
                            >{{ value.value }}</span
                        >
                        <span
                            class="relative shrink-0 font-mono text-muted-foreground tabular-nums"
                        >
                            {{ share(value.count, tag.total) }}%
                        </span>
                    </div>
                </dd>
            </div>
        </dl>

        <p
            v-if="tags && !tags.unavailable && tags.sampled > 0"
            class="mt-4 text-xs text-muted-foreground"
            data-test="issue-tags-sampled"
        >
            From the newest {{ tags.sampled.toLocaleString() }} events.
        </p>
    </div>
</template>
