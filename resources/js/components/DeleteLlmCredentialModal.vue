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
import { destroy } from '@/routes/teams/llm-credentials';
import type { TeamLlmCredential } from '@/types';

/**
 * Remove one of a team's model API keys.
 *
 * Removing a key here does not revoke it at the provider — say so plainly,
 * because a customer who believes otherwise will leave a live key behind.
 * Jobs that already ran on it keep their history either way.
 */
type Props = {
    teamSlug: string;
    credential: TeamLlmCredential | null;
    open: boolean;
};

const props = defineProps<Props>();
const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const processing = ref(false);

const deleteCredential = () => {
    if (!props.credential) {
        return;
    }

    router.visit(destroy([props.teamSlug, props.credential.id]), {
        onStart: () => (processing.value = true),
        onFinish: () => (processing.value = false),
        onSuccess: () => emit('update:open', false),
    });
};
</script>

<template>
    <Dialog :open="props.open" @update:open="emit('update:open', $event)">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Remove model API key</DialogTitle>
                <DialogDescription>
                    <strong>{{ props.credential?.label }}</strong> is removed
                    from this team and no new job can run on it. Jobs that
                    already used it keep their history. This does not revoke the
                    key at
                    {{ props.credential?.providerLabel ?? 'the provider' }} —
                    do that there if the key has leaked.
                </DialogDescription>
            </DialogHeader>

            <DialogFooter class="gap-2">
                <DialogClose as-child>
                    <Button variant="secondary"> Cancel </Button>
                </DialogClose>

                <Button
                    data-test="delete-llm-credential-confirm"
                    variant="destructive"
                    :disabled="processing"
                    @click="deleteCredential"
                >
                    Remove key
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
