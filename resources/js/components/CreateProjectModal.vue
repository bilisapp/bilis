<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
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
import { show as contactShow } from '@/routes/contact';
import type { PlanAllowance } from '@/types';

type Props = {
    teamSlug: string;
    /**
     * How many of the Free plan's projects are spent. Optional, and never a
     * gate: the caption below appears once the allowance is used up and the
     * submit button carries on working exactly as before.
     */
    allowance?: PlanAllowance;
};

const props = defineProps<Props>();

const atAllowance = computed(
    () =>
        !!props.allowance &&
        props.allowance.limit > 0 &&
        props.allowance.used >= props.allowance.limit,
);

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

                <p
                    v-if="atAllowance && props.allowance"
                    class="text-xs leading-relaxed text-muted-foreground"
                    data-test="create-project-allowance"
                >
                    This team already has
                    {{ props.allowance.used }} of the
                    {{ props.allowance.limit }} projects the Free plan
                    publishes. You can still create one — nothing is blocked —
                    but it is time to
                    <a
                        :href="contactShow.url({ query: { topic: 'upgrade' } })"
                        class="underline underline-offset-2 hover:text-foreground"
                        >talk about a Team plan</a
                    >.
                </p>

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
