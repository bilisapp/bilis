<script setup lang="ts">
import { Check, Copy } from '@lucide/vue';
import { useClipboard } from '@vueuse/core';

defineProps<{
    label: string;
    value: string;
    /** A second, quieter line under the value — a version, a count, a hint. */
    detail?: string;
    /** Machine-authored text (ids, versions) sets in the mono face. */
    mono?: boolean;
    /** Offer a copy button; the value is what lands on the clipboard. */
    copyable?: boolean;
}>();

const { copy, copied } = useClipboard({ copiedDuring: 1_500, legacy: true });
</script>

<template>
    <!--
      One cell of the header's fact grid: a quiet label over a loud value. The
      label is what you scan for and the value is what you read, so the contrast
      between them does the work that a heavier grid or a box would otherwise do.
    -->
    <div class="flex min-w-0 flex-col gap-0.5">
        <dt class="text-xs text-muted-foreground">{{ label }}</dt>
        <dd class="flex min-w-0 items-center gap-1.5">
            <span
                :class="['min-w-0 truncate text-sm', mono ? 'font-mono' : '']"
                :title="value"
            >
                <slot>{{ value }}</slot>
            </span>

            <button
                v-if="copyable"
                type="button"
                class="inline-flex size-5 shrink-0 items-center justify-center rounded text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                :aria-label="copied ? 'Copied' : `Copy ${label}`"
                :title="copied ? 'Copied' : `Copy ${label}`"
                @click="copy(value)"
            >
                <component :is="copied ? Check : Copy" class="size-3" />
            </button>
        </dd>
        <p v-if="detail" class="truncate text-xs text-muted-foreground">
            {{ detail }}
        </p>
    </div>
</template>
