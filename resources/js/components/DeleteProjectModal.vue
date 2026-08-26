<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { destroy } from '@/routes/projects';
import type { ProjectDetail } from '@/types';

type Props = {
    teamSlug: string;
    project: ProjectDetail;
    open: boolean;
};

const props = defineProps<Props>();
const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const confirmationName = ref('');
const formKey = ref(0);

const canDeleteProject = computed(
    () => confirmationName.value === props.project.name,
);

const handleOpenChange = (value: boolean) => {
    emit('update:open', value);

    if (!value) {
        confirmationName.value = '';
        formKey.value++;
    }
};
</script>

<template>
    <Dialog :open="props.open" @update:open="handleOpenChange">
        <DialogContent>
            <Form
                :key="formKey"
                v-bind="destroy.form([props.teamSlug, props.project.slug])"
                class="space-y-6"
                v-slot="{ processing }"
                @success="handleOpenChange(false)"
            >
                <DialogHeader>
                    <DialogTitle>Delete project</DialogTitle>
                    <DialogDescription>
                        This permanently deletes
                        <strong>"{{ props.project.name }}"</strong> and revokes
                        every API key issued for it. Logs already stored keep
                        their project id but become unreachable from the viewer.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="delete-project-name">
                        Type <strong>"{{ props.project.name }}"</strong> to
                        confirm
                    </Label>
                    <Input
                        id="delete-project-name"
                        data-test="delete-project-name"
                        v-model="confirmationName"
                        placeholder="Enter project name"
                        autocomplete="off"
                    />
                </div>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="secondary"> Cancel </Button>
                    </DialogClose>

                    <Button
                        type="submit"
                        data-test="delete-project-confirm"
                        variant="destructive"
                        :disabled="!canDeleteProject || processing"
                    >
                        Delete project
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
