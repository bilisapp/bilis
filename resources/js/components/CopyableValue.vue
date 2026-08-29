<script setup lang="ts">
import { Check, Copy } from '@lucide/vue';
import { useClipboard } from '@vueuse/core';

type Props = {
    /** The value shown in the field and written to the clipboard. */
    value: string;
    /** What the copy button announces, e.g. "Copy DSN". */
    label: string;
};

const props = defineProps<Props>();

const { copy, copied } = useClipboard({ legacy: true });
</script>

<template>
    <div
        class="flex w-full items-stretch overflow-hidden rounded-lg border border-input bg-card"
        data-test="copyable-value"
    >
        <input
            type="text"
            readonly
            data-test="copyable-value-input"
            :value="props.value"
            :aria-label="props.label"
            class="w-full bg-transparent p-3 font-mono text-sm text-foreground outline-none"
            @focus="($event.target as HTMLInputElement).select()"
        />
        <button
            type="button"
            data-test="copyable-value-copy"
            class="block border-l border-input px-3 hover:bg-muted"
            :aria-label="copied ? 'Copied' : props.label"
            @click="copy(props.value)"
        >
            <Check v-if="copied" class="w-4 text-foreground" />
            <Copy v-else class="w-4" />
        </button>
    </div>
</template>
