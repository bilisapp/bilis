<script setup lang="ts">
import { ChevronRight, Copy, Check } from '@lucide/vue';
import { useClipboard } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import CodeCanvas from '@/components/CodeCanvas.vue';
import {
    displayLocation,
    frameLocation,
    groupFrames,
    hasContext,
} from '@/lib/issues';
import { cn } from '@/lib/utils';
import type { StackFrame } from '@/types';

/**
 * A stack trace read the way a person debugs one: the application's own
 * frames first-class, everybody else's folded away until asked for.
 *
 * Frames are most recent call first. A frame with source context (an SDK
 * that sends it) opens to the lines around the call, rendered through
 * `CodeCanvas` with real line numbers and the failing line selected; the
 * first such application frame starts open, because that is the line the
 * reader came for. "Raw" shows the trace exactly as it was received, which is
 * what gets pasted into a ticket.
 */
const props = withDefaults(
    defineProps<{
        frames: StackFrame[];
        /** The trace as received, for the raw view and the copy button. */
        raw?: string;
        /** Start folded library frames open. */
        expandAll?: boolean;
    }>(),
    { raw: '', expandAll: false },
);

const view = ref<'app' | 'full' | 'raw'>(props.expandAll ? 'full' : 'app');

const groups = computed(() => groupFrames(props.frames));

const openGroups = ref(new Set<number>());

const firstWithContext = computed(() =>
    props.frames.findIndex((frame) => frame.inApp && hasContext(frame)),
);

const openFrames = ref(new Set<number>());

watch(
    () => props.frames,
    () => {
        openGroups.value = new Set();
        openFrames.value = new Set(
            firstWithContext.value >= 0 ? [firstWithContext.value] : [],
        );
    },
    { immediate: true },
);

function toggleFrame(index: number) {
    const next = new Set(openFrames.value);

    if (next.has(index)) {
        next.delete(index);
    } else {
        next.add(index);
    }

    openFrames.value = next;
}

function toggleGroup(index: number) {
    const next = new Set(openGroups.value);

    if (next.has(index)) {
        next.delete(index);
    } else {
        next.add(index);
    }

    openGroups.value = next;
}

const appFrameCount = computed(
    () => props.frames.filter((frame) => frame.inApp).length,
);

const rawText = computed(
    () =>
        props.raw ||
        props.frames
            .map((frame, index) =>
                `#${index} ${frameLocation(frame)} ${frame.function}`.trim(),
            )
            .join('\n'),
);

const excerpt = (frame: StackFrame) =>
    [...frame.preContext, frame.contextLine ?? '', ...frame.postContext].join(
        '\n',
    );

const excerptStart = (frame: StackFrame) =>
    frame.lineno === null
        ? 1
        : Math.max(1, frame.lineno - frame.preContext.length);

const basename = (path: string) => path.split(/[\\/]/).pop() || 'file.txt';

const { copy, copied } = useClipboard({ copiedDuring: 1_500, legacy: true });
</script>

<template>
    <div class="flex flex-col" data-test="stack-trace">
        <div class="flex items-center justify-between gap-2 pb-3">
            <p class="text-xs text-muted-foreground">
                {{ frames.length }} frames<span
                    v-if="appFrameCount > 0 && appFrameCount < frames.length"
                    >, {{ appFrameCount }} in your code</span
                >
            </p>

            <div class="flex items-center gap-1">
                <div
                    class="inline-flex h-7 items-center gap-0.5 rounded-md bg-muted p-[3px] text-xs"
                    role="tablist"
                    aria-label="Stack view"
                >
                    <button
                        v-for="option in [
                            { value: 'app', label: 'App' },
                            { value: 'full', label: 'Full' },
                            { value: 'raw', label: 'Raw' },
                        ] as const"
                        :key="option.value"
                        type="button"
                        role="tab"
                        :aria-selected="view === option.value"
                        :data-test="`stack-view-${option.value}`"
                        :class="
                            cn(
                                'inline-flex h-full items-center rounded-[5px] px-2 font-medium transition-colors focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none',
                                view === option.value
                                    ? 'bg-card text-foreground ring-1 ring-border'
                                    : 'text-muted-foreground hover:text-foreground',
                            )
                        "
                        @click="view = option.value"
                    >
                        {{ option.label }}
                    </button>
                </div>
                <button
                    type="button"
                    class="inline-flex size-7 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                    :aria-label="copied ? 'Copied' : 'Copy stack trace'"
                    :title="copied ? 'Copied' : 'Copy stack trace'"
                    data-test="stack-copy"
                    @click="copy(rawText)"
                >
                    <component :is="copied ? Check : Copy" class="size-3.5" />
                </button>
            </div>
        </div>

        <pre
            v-if="view === 'raw' || frames.length === 0"
            class="max-h-[32rem] overflow-auto rounded-lg border bg-muted/40 p-3 font-mono text-xs leading-relaxed whitespace-pre"
            data-test="stack-raw"
            >{{ rawText || 'No stack trace was sent with this event.' }}</pre>

        <ol v-else class="border-t" data-test="stack-frames">
            <template v-for="(group, groupIndex) in groups" :key="groupIndex">
                <li
                    v-if="
                        group.kind === 'collapsed' &&
                        view === 'app' &&
                        !openGroups.has(groupIndex)
                    "
                    class="border-b border-border/60"
                >
                    <button
                        type="button"
                        class="flex h-9 w-full items-center gap-2 px-2 text-left text-xs text-muted-foreground transition-colors hover:bg-muted/50 focus-visible:bg-muted/60 focus-visible:outline-none"
                        data-test="stack-collapsed"
                        @click="toggleGroup(groupIndex)"
                    >
                        <ChevronRight class="size-3" />
                        {{ group.frames.length }} library frames
                    </button>
                </li>

                <template v-else>
                    <li
                        v-for="item in group.kind === 'frame'
                            ? [{ frame: group.frame, index: group.index }]
                            : group.frames"
                        :key="item.index"
                        :class="
                            cn(
                                'border-b border-border/60',
                                !item.frame.inApp && 'bg-muted/30',
                            )
                        "
                        :data-test="
                            item.frame.inApp
                                ? 'stack-frame-app'
                                : 'stack-frame-lib'
                        "
                    >
                        <component
                            :is="hasContext(item.frame) ? 'button' : 'div'"
                            :type="
                                hasContext(item.frame) ? 'button' : undefined
                            "
                            :class="
                                cn(
                                    'flex min-h-11 w-full min-w-0 items-center gap-2 px-2 py-2 text-left font-mono text-xs',
                                    hasContext(item.frame) &&
                                        'transition-colors hover:bg-muted/50 focus-visible:bg-muted/60 focus-visible:outline-none',
                                )
                            "
                            :aria-expanded="
                                hasContext(item.frame)
                                    ? openFrames.has(item.index)
                                    : undefined
                            "
                            @click="
                                hasContext(item.frame) &&
                                toggleFrame(item.index)
                            "
                        >
                            <ChevronRight
                                :class="
                                    cn(
                                        'size-3 shrink-0 text-muted-foreground transition-transform',
                                        !hasContext(item.frame) && 'invisible',
                                        openFrames.has(item.index) &&
                                            'rotate-90',
                                    )
                                "
                            />
                            <span
                                :class="
                                    cn(
                                        'min-w-0 truncate',
                                        item.frame.inApp
                                            ? 'text-foreground'
                                            : 'text-muted-foreground',
                                    )
                                "
                                :title="frameLocation(item.frame)"
                            >
                                {{ displayLocation(frameLocation(item.frame)) }}
                            </span>
                            <span
                                v-if="item.frame.function"
                                class="min-w-0 shrink truncate text-muted-foreground"
                                :title="item.frame.function"
                            >
                                in {{ item.frame.function }}
                            </span>
                            <span
                                v-if="item.frame.inApp"
                                class="ml-auto inline-flex h-5 shrink-0 items-center rounded-full px-2 font-sans text-[11px] font-medium text-foreground/80 ring-1 ring-border ring-inset"
                            >
                                app
                            </span>
                        </component>

                        <div
                            v-if="
                                openFrames.has(item.index) &&
                                hasContext(item.frame)
                            "
                            class="px-2 pb-3"
                        >
                            <CodeCanvas
                                :code="excerpt(item.frame)"
                                :filename="basename(item.frame.filename)"
                                :start-line="excerptStart(item.frame)"
                                :highlight-line="item.frame.lineno"
                                max-height="20rem"
                                hide-header
                            />
                        </div>
                    </li>
                </template>
            </template>
        </ol>
    </div>
</template>
