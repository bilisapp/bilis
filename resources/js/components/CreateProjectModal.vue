<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { ref } from 'vue';
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
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { store } from '@/routes/projects';

type Props = {
    teamSlug: string;
};

const props = defineProps<Props>();

const open = ref(false);
const formKey = ref(0);

const handleOpenChange = (value: boolean) => {
    open.value = value;

    if (!value) {
        formKey.value++;
    }
};
</script>

<template>
    <Dialog :open="open" @update:open="handleOpenChange">
        <DialogTrigger as-child>
            <slot />
        </DialogTrigger>
        <DialogContent>
            <Form
                :key="formKey"
                v-bind="store.form(props.teamSlug)"
                class="space-y-6"
                v-slot="{ errors, processing }"
                @success="open = false"
            >
                <DialogHeader>
                    <DialogTitle>Create a project</DialogTitle>
                    <DialogDescription>
                        A project groups the logs of one application. Its slug
                        is generated from the name.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="project-name">Project name</Label>
                    <Input
                        id="project-name"
                        name="name"
                        data-test="create-project-name"
                        placeholder="Checkout API"
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
                        data-test="create-project-submit"
                        :disabled="processing"
                    >
                        Create project
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
