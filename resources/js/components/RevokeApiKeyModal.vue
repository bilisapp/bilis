<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
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
import { destroy } from '@/routes/projects/api-keys';
import type { ProjectApiKey, ProjectDetail } from '@/types';

type Props = {
    teamSlug: string;
    project: ProjectDetail;
    apiKey: ProjectApiKey | null;
    open: boolean;
};

const props = defineProps<Props>();
const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const processing = ref(false);

const revokeApiKey = () => {
    if (!props.apiKey) {
        return;
    }

    router.visit(
        destroy([props.teamSlug, props.project.slug, props.apiKey.id]),
        {
            onStart: () => (processing.value = true),
            onFinish: () => (processing.value = false),
            onSuccess: () => emit('update:open', false),
        },
    );
};
</script>

<template>
    <Dialog :open="props.open" @update:open="emit('update:open', $event)">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Revoke API key</DialogTitle>
                <DialogDescription>
                    <strong>{{ props.apiKey?.name }}</strong> stops working
                    immediately and anything still sending with it will be
                    rejected. This cannot be undone.
                </DialogDescription>
            </DialogHeader>

            <DialogFooter class="gap-2">
                <DialogClose as-child>
                    <Button variant="secondary"> Cancel </Button>
                </DialogClose>

                <Button
                    data-test="revoke-api-key-confirm"
                    variant="destructive"
                    :disabled="processing"
                    @click="revokeApiKey"
                >
                    Revoke key
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
