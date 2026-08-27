<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { GitBranch, Wrench } from '@lucide/vue';
import { ref, watch } from 'vue';
import ConnectRepositoryModal from '@/components/ConnectRepositoryModal.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardAction,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index as autofixIndex } from '@/routes/autofix';
import { connect } from '@/routes/github/installations';
import { destroy, update } from '@/routes/projects/repository';
import type {
    GitHubInstallationSummary,
    ProjectAutofixConfig,
    ProjectDetail,
    ProjectRepository,
} from '@/types';

/**
 * The autofix half of a project's settings.
 *
 * Three states, in the order a team meets them: the App is not installed yet,
 * it is installed but this project has no repository, or a repository is
 * connected and its budgets can be tuned. `autofix_enabled` is the opt-in and
 * defaults off — connecting a repository grants read access, it does not start
 * anything.
 */
const props = defineProps<{
    teamSlug: string;
    project: ProjectDetail;
    repository: ProjectRepository | null;
    installations: GitHubInstallationSummary[];
    autofix: ProjectAutofixConfig;
}>();

const connectOpen = ref(false);

const settings = useForm({
    autofix_enabled: props.repository?.autofixEnabled ?? false,
    test_cmd: props.repository?.testCmd ?? '',
    max_concurrent: props.repository?.maxConcurrent ?? 1,
    daily_budget: props.repository?.dailyBudget ?? 5,
});

watch(
    () => props.repository,
    (repository) => {
        settings.defaults({
            autofix_enabled: repository?.autofixEnabled ?? false,
            test_cmd: repository?.testCmd ?? '',
            max_concurrent: repository?.maxConcurrent ?? 1,
            daily_budget: repository?.dailyBudget ?? 5,
        });
        settings.reset();
    },
);

const disconnectForm = useForm({});

function save() {
    settings
        .transform((data) => ({ ...data, test_cmd: data.test_cmd || null }))
        .patch(update([props.teamSlug, props.project.slug]).url, {
            preserveScroll: true,
        });
}

function disconnect() {
    disconnectForm.delete(destroy([props.teamSlug, props.project.slug]).url, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Card v-if="autofix.enabled" data-test="project-repository-card">
        <CardHeader>
            <CardTitle>Autofix</CardTitle>
            <CardDescription>
                When a production error keeps repeating, an agent reads it,
                writes a patch and opens a pull request. Nothing is merged for
                you, and
                <code class="font-mono">.github/</code> is never touched.
            </CardDescription>

            <CardAction v-if="repository">
                <Badge variant="secondary" class="font-mono">
                    {{ repository.repoFullName }}
                </Badge>
            </CardAction>
        </CardHeader>

        <CardContent>
            <!-- Nothing installed: the App has to be granted repositories first. -->
            <div
                v-if="installations.length === 0"
                class="flex flex-col items-center gap-3 py-6 text-center"
                data-test="project-repository-no-installation"
            >
                <span
                    class="flex size-11 items-center justify-center rounded-full bg-muted text-muted-foreground"
                >
                    <GitBranch class="size-5" />
                </span>

                <div class="space-y-1">
                    <p class="font-medium">Connect your repositories</p>
                    <p class="max-w-sm text-sm text-muted-foreground">
                        The same GitHub App you signed in with asks for
                        repository access separately. Grant it only the
                        repositories autofix should be able to read.
                    </p>
                </div>

                <Button
                    v-if="autofix.githubConfigured"
                    as-child
                    data-test="project-repository-install"
                >
                    <a
                        :href="
                            connect(teamSlug, {
                                query: { project: project.slug },
                            }).url
                        "
                    >
                        <GitBranch /> Connect repositories
                    </a>
                </Button>

                <p v-else class="text-sm text-muted-foreground">
                    This instance has no GitHub App configured yet.
                </p>
            </div>

            <!-- Installed, but this project has not chosen a repository. -->
            <div
                v-else-if="!repository"
                class="flex flex-col items-center gap-3 py-6 text-center"
                data-test="project-repository-empty"
            >
                <span
                    class="flex size-11 items-center justify-center rounded-full bg-muted text-muted-foreground"
                >
                    <Wrench class="size-5" />
                </span>

                <div class="space-y-1">
                    <p class="font-medium">No repository connected</p>
                    <p class="max-w-sm text-sm text-muted-foreground">
                        Pick the repository this project's code lives in. You
                        can change it later, and disconnecting keeps the history
                        of what was already attempted.
                    </p>
                </div>

                <Button
                    data-test="project-repository-choose"
                    @click="connectOpen = true"
                >
                    Choose repository
                </Button>
            </div>

            <!-- Connected: budgets, the opt-in, and the way out. -->
            <form v-else class="space-y-5" @submit.prevent="save">
                <div class="flex items-start gap-3">
                    <Checkbox
                        id="autofix-enabled"
                        :model-value="settings.autofix_enabled"
                        data-test="project-repository-enabled"
                        @update:model-value="
                            settings.autofix_enabled = $event === true
                        "
                    />
                    <div class="space-y-1">
                        <Label for="autofix-enabled" class="font-medium">
                            Let autofix open pull requests for this project
                        </Label>
                        <p class="text-sm text-muted-foreground">
                            Off by default. With it off, the repository stays
                            connected but nothing is ever dispatched.
                        </p>
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="autofix-test-cmd">Test command</Label>
                    <Input
                        id="autofix-test-cmd"
                        v-model="settings.test_cmd"
                        class="font-mono"
                        placeholder="php artisan test --compact"
                        data-test="project-repository-test-cmd"
                    />
                    <p class="text-xs text-muted-foreground">
                        Run inside the agent's sandbox before a diff is
                        accepted. Leave empty when the suite needs services the
                        sandbox has no access to — CI on the pull request then
                        does the proving.
                    </p>
                    <InputError :message="settings.errors.test_cmd" />
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="autofix-max-concurrent">
                            Concurrent jobs
                        </Label>
                        <Input
                            id="autofix-max-concurrent"
                            v-model.number="settings.max_concurrent"
                            type="number"
                            min="1"
                            max="10"
                            data-test="project-repository-max-concurrent"
                        />
                        <InputError :message="settings.errors.max_concurrent" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="autofix-daily-budget">Jobs per day</Label>
                        <Input
                            id="autofix-daily-budget"
                            v-model.number="settings.daily_budget"
                            type="number"
                            min="1"
                            max="100"
                            data-test="project-repository-daily-budget"
                        />
                        <InputError :message="settings.errors.daily_budget" />
                    </div>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-2">
                        <Button
                            type="submit"
                            :disabled="settings.processing"
                            data-test="project-repository-save"
                        >
                            Save settings
                        </Button>

                        <Button variant="ghost" as-child>
                            <Link
                                :href="
                                    autofixIndex(teamSlug, {
                                        query: { project: project.slug },
                                    })
                                "
                            >
                                View fix jobs
                            </Link>
                        </Button>
                    </div>

                    <Button
                        type="button"
                        variant="ghost"
                        class="text-destructive hover:text-destructive"
                        :disabled="disconnectForm.processing"
                        data-test="project-repository-disconnect"
                        @click="disconnect"
                    >
                        Disconnect
                    </Button>
                </div>
            </form>
        </CardContent>
    </Card>

    <ConnectRepositoryModal
        v-model:open="connectOpen"
        :team-slug="teamSlug"
        :project="project"
    />
</template>
