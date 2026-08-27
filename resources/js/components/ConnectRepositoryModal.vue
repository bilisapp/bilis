<script setup lang="ts">
import { useForm, useHttp } from '@inertiajs/vue3';
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
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { available, store } from '@/routes/projects/repository';
import type { AvailableInstallation, ProjectDetail } from '@/types';

/**
 * Pick the repository a project ships from.
 *
 * The list is fetched from GitHub when the dialog opens rather than shipped
 * with the page: it is a live call, and a project settings page must still
 * render when GitHub is having a bad day. The server checks the choice against
 * the same list, so a hand-edited form cannot reach a repository the team
 * never shared with the App.
 */
const props = defineProps<{
    teamSlug: string;
    project: ProjectDetail;
    open: boolean;
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();

const installations = ref<AvailableInstallation[]>([]);
const unavailable = ref(false);
const loading = ref(false);
const selected = ref<string>('');

const http = useHttp();

const form = useForm({
    installation_id: 0,
    repo_full_name: '',
});

/** Every granted repository, flattened, tagged with the account it sits on. */
const choices = computed(() =>
    installations.value.flatMap((installation) =>
        installation.repositories.map((repository) => ({
            key: `${installation.id}:${repository.full_name}`,
            installationId: installation.id,
            fullName: repository.full_name,
            accountLogin: installation.accountLogin,
            isPrivate: repository.private,
        })),
    ),
);

async function loadRepositories() {
    loading.value = true;
    unavailable.value = false;

    try {
        const result = (await http.get(
            available([props.teamSlug, props.project.slug]).url,
        )) as { installations: AvailableInstallation[]; unavailable: boolean };

        installations.value = result.installations ?? [];
        unavailable.value = result.unavailable === true;
    } catch {
        installations.value = [];
        unavailable.value = true;
    } finally {
        loading.value = false;
    }
}

watch(
    () => props.open,
    (open) => {
        if (open) {
            selected.value = '';
            form.reset();
            form.clearErrors();
            void loadRepositories();
        }
    },
);

function submit() {
    const choice = choices.value.find((entry) => entry.key === selected.value);

    if (!choice) {
        return;
    }

    form.installation_id = choice.installationId;
    form.repo_full_name = choice.fullName;

    form.post(store([props.teamSlug, props.project.slug]).url, {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Connect a repository</DialogTitle>
                <DialogDescription>
                    Autofix works on one repository per project. Only what you
                    granted the Bilis GitHub App is listed here.
                </DialogDescription>
            </DialogHeader>

            <div class="space-y-2">
                <p
                    v-if="loading"
                    class="text-sm text-muted-foreground"
                    data-test="connect-repository-loading"
                >
                    Asking GitHub which repositories you shared…
                </p>

                <p
                    v-else-if="unavailable"
                    class="text-sm text-muted-foreground"
                    data-test="connect-repository-unavailable"
                >
                    GitHub could not be reached. Close this and try again in a
                    moment.
                </p>

                <p
                    v-else-if="choices.length === 0"
                    class="text-sm text-muted-foreground"
                    data-test="connect-repository-none"
                >
                    The App is installed, but no repository has been shared with
                    it yet. Adjust the install on GitHub and reopen this dialog.
                </p>

                <Select v-else v-model="selected">
                    <SelectTrigger
                        class="w-full"
                        data-test="connect-repository-select"
                    >
                        <SelectValue placeholder="Choose a repository" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="choice in choices"
                            :key="choice.key"
                            :value="choice.key"
                        >
                            {{ choice.fullName }}
                        </SelectItem>
                    </SelectContent>
                </Select>

                <InputError :message="form.errors.repo_full_name" />
            </div>

            <DialogFooter class="gap-2">
                <DialogClose as-child>
                    <Button variant="secondary">Cancel</Button>
                </DialogClose>

                <Button
                    :disabled="selected === '' || form.processing"
                    data-test="connect-repository-submit"
                    @click="submit"
                >
                    Connect repository
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
