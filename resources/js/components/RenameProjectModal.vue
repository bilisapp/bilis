<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
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
import { update } from '@/routes/projects';
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

const name = ref(props.project.name);
const formKey = ref(0);

watch(
    () => props.project.name,
    (value) => (name.value = value),
);

const handleOpenChange = (value: boolean) => {
    emit('update:open', value);

    if (!value) {
        name.value = props.project.name;
        formKey.value++;
    }
};
</script>

<template>
    <Dialog :open="props.open" @update:open="handleOpenChange">
        <DialogContent>
            <Form
                :key="formKey"
                v-bind="update.form([props.teamSlug, props.project.slug])"
                class="space-y-6"
                v-slot="{ errors, processing }"
                @success="handleOpenChange(false)"
            >
                <DialogHeader>
                    <DialogTitle>Rename project</DialogTitle>
                    <DialogDescription>
                        The slug stays the same, so ingest keeps working.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="rename-project-name">Project name</Label>
                    <Input
                        id="rename-project-name"
                        name="name"
                        data-test="rename-project-name"
                        v-model="name"
                        required
                    />
                    <InputError :message="errors.name" />
                </div>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="secondary"> Cancel </Button>
                    </DialogClose>

                    <Button
                        type="submit"
                        data-test="rename-project-submit"
                        :disabled="processing"
                    >
                        Save name
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
