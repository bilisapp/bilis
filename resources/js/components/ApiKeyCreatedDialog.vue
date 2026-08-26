<script setup lang="ts">
import { Check, Copy, TriangleAlert } from '@lucide/vue';
import { useClipboard } from '@vueuse/core';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { NewProjectApiKey } from '@/types';

type Props = {
    apiKey: NewProjectApiKey | null;
    open: boolean;
};

const props = defineProps<Props>();
const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const { copy, copied } = useClipboard({ legacy: true });
</script>

<template>
    <Dialog :open="props.open" @update:open="emit('update:open', $event)">
        <DialogContent data-test="api-key-created-dialog">
            <DialogHeader>
                <DialogTitle> "{{ props.apiKey?.name }}" is ready </DialogTitle>
                <DialogDescription>
                    Send it as
                    <code class="font-mono"
                        >Authorization: Bearer &lt;key&gt;</code
                    >
                    from your collector.
                </DialogDescription>
            </DialogHeader>

            <div
                class="flex w-full items-stretch overflow-hidden rounded-lg border border-input bg-card"
            >
                <input
                    type="text"
                    readonly
                    data-test="api-key-plaintext"
                    :value="props.apiKey?.key"
                    class="w-full bg-transparent p-3 font-mono text-sm text-foreground outline-none"
                    @focus="($event.target as HTMLInputElement).select()"
                />
                <button
                    type="button"
                    data-test="api-key-copy"
                    class="block border-l border-input px-3 hover:bg-muted"
                    :aria-label="copied ? 'Copied' : 'Copy API key'"
                    @click="copy(props.apiKey?.key ?? '')"
                >
                    <Check v-if="copied" class="w-4 text-foreground" />
                    <Copy v-else class="w-4" />
                </button>
            </div>

            <p
                class="flex items-start gap-2 text-sm text-muted-foreground"
                data-test="api-key-warning"
            >
                <TriangleAlert class="text-crimson mt-0.5 size-4 shrink-0" />
                <span>
                    Copy it now — only a hash is stored, so this is the last
                    time Bilis can show you the key. Lose it and you will have
                    to issue a new one.
                </span>
            </p>

            <DialogFooter>
                <DialogClose as-child>
                    <Button data-test="api-key-created-done">
                        I've copied it
                    </Button>
                </DialogClose>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
