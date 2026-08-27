<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
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
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { store } from '@/routes/autofix';
import type { LogProject } from '@/types';

/**
 * Ask the agent for something that is not a production error.
 *
 * The picker only ever lists projects whose repository has opted into autofix,
 * which is the same gate the endpoint enforces — the dialog cannot offer a
 * choice the server would refuse. The budgets can still refuse it: those are
 * per repository and shared with the scheduled scan, so a refusal comes back
 * as a field error naming which limit was hit rather than as a toast.
 */
const props = defineProps<{
    teamSlug: string;
    projects: LogProject[];
}>();

/** Kept in step with `CreateFixJobRequest`. */
const MIN_LENGTH = 10;
const MAX_LENGTH = 10000;

const open = ref(false);

const form = useForm({
    project: props.projects.length === 1 ? props.projects[0].slug : '',
    instructions: '',
});

const remaining = computed(() => MAX_LENGTH - form.instructions.length);
const short = computed(
    () =>
        form.instructions.trim().length > 0 &&
        form.instructions.trim().length < MIN_LENGTH,
);

const canSubmit = computed(
    () =>
        !form.processing &&
        form.project !== '' &&
        form.instructions.trim().length >= MIN_LENGTH &&
        form.instructions.length <= MAX_LENGTH,
);

watch(open, (value) => {
    if (value) {
        form.reset();
        form.clearErrors();
        form.project =
            props.projects.length === 1 ? props.projects[0].slug : '';
    }
});

function submit() {
    form.post(store(props.teamSlug).url, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <slot />
        </DialogTrigger>

        <DialogContent class="sm:max-w-xl">
            <form class="space-y-6" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>New job</DialogTitle>
                    <DialogDescription>
                        Describe a change and the agent works on it in the
                        connected repository. It comes back as a pull request —
                        the same one an error-triggered fix would open, with the
                        same checks in front of it.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="fix-job-project">Project</Label>
                    <Select v-model="form.project">
                        <SelectTrigger
                            id="fix-job-project"
                            class="w-full"
                            data-test="create-fix-job-project"
                        >
                            <SelectValue placeholder="Pick a project" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="project in projects"
                                :key="project.slug"
                                :value="project.slug"
                            >
                                {{ project.name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.project" />
                </div>

                <div class="grid gap-2">
                    <Label for="fix-job-instructions">Instructions</Label>
                    <textarea
                        id="fix-job-instructions"
                        v-model="form.instructions"
                        rows="7"
                        :maxlength="MAX_LENGTH"
                        class="min-h-32 w-full resize-y rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-input/30"
                        placeholder="Upgrade guzzlehttp/guzzle to the latest 7.x release and make sure the suite still passes."
                        data-test="create-fix-job-instructions"
                    />

                    <div
                        class="flex items-baseline justify-between gap-3 text-xs text-muted-foreground"
                    >
                        <span class="max-w-sm">
                            Be specific about the outcome you want. The agent
                            makes the smallest change it can and stops rather
                            than guess.
                        </span>
                        <span
                            class="shrink-0 font-mono tabular-nums"
                            :class="short ? 'text-severity-warn' : undefined"
                            data-test="create-fix-job-counter"
                        >
                            {{
                                short
                                    ? `${MIN_LENGTH} chars min`
                                    : `${remaining} left`
                            }}
                        </span>
                    </div>

                    <InputError :message="form.errors.instructions" />
                </div>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button type="button" variant="secondary">
                            Cancel
                        </Button>
                    </DialogClose>

                    <Button
                        type="submit"
                        :disabled="!canSubmit"
                        data-test="create-fix-job-submit"
                    >
                        Start job
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
