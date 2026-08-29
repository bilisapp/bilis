<script setup lang="ts">
import { TriangleAlert } from '@lucide/vue';
import CopyableValue from '@/components/CopyableValue.vue';
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

            <CopyableValue
                data-test="api-key-plaintext"
                :value="props.apiKey?.key ?? ''"
                label="Copy API key"
            />

            <div v-if="props.apiKey?.dsn" class="space-y-2">
                <p class="text-sm font-medium">DSN</p>
                <CopyableValue
                    data-test="api-key-dsn"
                    :value="props.apiKey.dsn"
                    label="Copy DSN"
                />
                <p class="text-xs text-muted-foreground">
                    For clients configured with a URL instead of a header: Bilis
                    accepts requests from Sentry-compatible SDKs, so pointing
                    one at this DSN ships its exceptions here. It holds the
                    public half of the pair, so it stays visible on the project
                    page.
                </p>
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
