<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { GitBranch, Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
import ConnectRepositoryModal from '@/components/ConnectRepositoryModal.vue';
import Heading from '@/components/Heading.vue';
import RepositoryAutofixSettings from '@/components/RepositoryAutofixSettings.vue';
import { Button } from '@/components/ui/button';
import { index as autofixIndex } from '@/routes/autofix';
import { connect } from '@/routes/github/installations';
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
 * it is installed but this project has no repository, or one or more are
 * connected and each can be tuned. `autofix_enabled` is the opt-in and defaults
 * off — connecting a repository grants read access, it does not start anything.
 *
 * A project may hold several repositories, because a project ships several
 * services and they need not share a codebase. Which one fixes a given error is
 * decided by the service claims on each, edited per repository below.
 */
const props = defineProps<{
    teamSlug: string;
    project: ProjectDetail;
    repositories: ProjectRepository[];
    observedServices: string[];
    installations: GitHubInstallationSummary[];
    autofix: ProjectAutofixConfig;
}>();

const connectOpen = ref(false);

/**
 * Services this project has logged that no repository answers for.
 *
 * Worth saying out loud: an unclaimed service is one whose errors nothing will
 * ever fix, and the only clue is a scan that quietly does nothing.
 */
const unclaimedServices = computed(() => {
    if (props.repositories.some((repository) => repository.isCatchAll)) {
        return [];
    }

    const claimed = new Set(
        props.repositories.flatMap((repository) => repository.services),
    );

    return props.observedServices.filter((service) => !claimed.has(service));
});
</script>

<template>
    <section
        v-if="autofix.enabled"
        class="flex flex-col gap-5"
        data-test="project-repository-card"
    >
        <Heading
            variant="small"
            title="Autofix"
            description="When a production error keeps repeating, an agent reads it, writes a patch and opens a pull request. Nothing is merged for you, and .github/ is never touched."
        >
            <template v-if="repositories.length" #actions>
                <Button
                    variant="outline"
                    size="sm"
                    data-test="project-repository-connect-another"
                    @click="connectOpen = true"
                >
                    <Plus /> Connect another
                </Button>
            </template>
        </Heading>

        <!-- Nothing installed: the App has to be granted repositories first. -->
        <div
            v-if="installations.length === 0"
            class="flex flex-col items-start gap-3"
            data-test="project-repository-no-installation"
        >
            <div class="flex flex-col gap-1">
                <p class="text-sm font-medium text-foreground">
                    Connect your repositories
                </p>
                <p class="max-w-prose text-sm text-muted-foreground">
                    The same GitHub App you signed in with asks for repository
                    access separately. Grant it only the repositories autofix
                    should be able to read.
                </p>
            </div>

            <Button
                v-if="autofix.githubConfigured"
                variant="outline"
                size="sm"
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
            v-else-if="repositories.length === 0"
            class="flex flex-col items-start gap-3"
            data-test="project-repository-empty"
        >
            <div class="flex flex-col gap-1">
                <p class="text-sm font-medium text-foreground">
                    No repository connected
                </p>
                <p class="max-w-prose text-sm text-muted-foreground">
                    Pick the repository this project's code lives in. You can
                    change it later, and disconnecting keeps the history of what
                    was already attempted.
                </p>
            </div>

            <Button
                variant="outline"
                size="sm"
                data-test="project-repository-choose"
                @click="connectOpen = true"
            >
                Choose repository
            </Button>
        </div>

        <!-- Connected: one panel per repository. -->
        <div v-else class="flex flex-col gap-4">
            <p
                v-if="unclaimedServices.length"
                class="text-sm text-muted-foreground"
                data-test="project-repository-unclaimed"
            >
                <span class="font-medium text-severity-warn"
                    >Some services have no repository.</span
                >
                Nothing will fix errors from
                <span class="font-mono text-foreground">{{
                    unclaimedServices.join(', ')
                }}</span>
                until a repository claims them.
            </p>

            <RepositoryAutofixSettings
                v-for="repository in repositories"
                :key="repository.id"
                :team-slug="teamSlug"
                :project="project"
                :repository="repository"
                :observed-services="observedServices"
            />

            <Button
                variant="ghost"
                size="sm"
                class="w-fit text-muted-foreground"
                as-child
            >
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
    </section>

    <ConnectRepositoryModal
        v-model:open="connectOpen"
        :team-slug="teamSlug"
        :project="project"
    />
</template>
