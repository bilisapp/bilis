<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { KeyRound, MoreHorizontal, Pencil, Plus, Trash2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import ApiKeyCreatedDialog from '@/components/ApiKeyCreatedDialog.vue';
import BrowserOriginsCard from '@/components/BrowserOriginsCard.vue';
import CopyableValue from '@/components/CopyableValue.vue';
import CreateApiKeyModal from '@/components/CreateApiKeyModal.vue';
import DeleteProjectModal from '@/components/DeleteProjectModal.vue';
import ErrorMonitoringSetup from '@/components/ErrorMonitoringSetup.vue';
import Heading from '@/components/Heading.vue';
import PageHeader from '@/components/PageHeader.vue';
import ProjectRepositoryCard from '@/components/ProjectRepositoryCard.vue';
import RenameProjectModal from '@/components/RenameProjectModal.vue';
import RevokeApiKeyModal from '@/components/RevokeApiKeyModal.vue';
import ServerInstallCommand from '@/components/ServerInstallCommand.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { formatDate, maskedKey } from '@/lib/projects';
import { cn } from '@/lib/utils';
import { index as projectsIndex, show as projectShow } from '@/routes/projects';
import type {
    GitHubInstallationSummary,
    NewProjectApiKey,
    ProjectApiKey,
    ProjectAutofixConfig,
    ProjectDetail,
    ProjectRepository,
    Team,
} from '@/types';

const props = defineProps<{
    project: ProjectDetail;
    apiKeys: ProjectApiKey[];
    teamSlug: string;
    /** Owner or admin: may rename or delete the project, revoke keys and change origins and repositories. */
    canManage: boolean;
    repositories: ProjectRepository[];
    observedServices: string[];
    installations: GitHubInstallationSummary[];
    autofix: ProjectAutofixConfig;
}>();

defineOptions({
    layout: (layoutProps: {
        currentTeam?: Team | null;
        project: ProjectDetail;
    }) => ({
        breadcrumbs: [
            {
                title: 'Projects',
                href: layoutProps.currentTeam
                    ? projectsIndex(layoutProps.currentTeam.slug)
                    : '/',
            },
            {
                title: layoutProps.project.name,
                href: layoutProps.currentTeam
                    ? projectShow([
                          layoutProps.currentTeam.slug,
                          layoutProps.project.slug,
                      ])
                    : '/',
            },
        ],
    }),
});

const page = usePage();

const renameOpen = ref(false);
const deleteOpen = ref(false);
const revokeOpen = ref(false);
const apiKeyRevoking = ref<ProjectApiKey | null>(null);

const createdApiKey = ref<NewProjectApiKey | null>(null);
const createdApiKeyOpen = ref(false);

const keyCount = computed(() => props.apiKeys.length);

/** The key heard from most recently, for the facts strip. */
const lastUsedKey = computed(
    () =>
        props.apiKeys
            .filter((apiKey) => apiKey.lastUsedAt && apiKey.lastUsedForHumans)
            .sort(
                (a, b) =>
                    new Date(b.lastUsedAt ?? 0).getTime() -
                    new Date(a.lastUsedAt ?? 0).getTime(),
            )[0] ?? null,
);

/** One grid for the key table's header and rows, so the lanes line up. */
const KEY_LANES =
    'grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-6 gap-y-3 md:grid-cols-[minmax(0,1fr)_10rem_8rem_2.25rem]';

/** The newest key's DSN, so the SDK snippets are ready to paste. */
const snippetDsn = computed(
    () => props.apiKeys.find((apiKey) => apiKey.dsn)?.dsn ?? null,
);

watch(
    () => page.flash.newApiKey,
    (apiKey) => {
        if (!apiKey) {
            return;
        }

        createdApiKey.value = apiKey;
        createdApiKeyOpen.value = true;
    },
    { immediate: true },
);

watch(createdApiKeyOpen, (open) => {
    if (!open) {
        createdApiKey.value = null;
    }
});

const openRevokeDialog = (apiKey: ProjectApiKey) => {
    apiKeyRevoking.value = apiKey;
    revokeOpen.value = true;
};
</script>

<template>
    <Head :title="project.name" />

    <div class="flex flex-1 flex-col">
        <PageHeader :title="project.name">
            <p>
                Send with
                <code class="font-mono text-xs text-foreground"
                    >Authorization: Bearer &lt;key&gt;</code
                >
                — the key decides which project the data lands in.
            </p>

            <template #actions>
                <CreateApiKeyModal :team-slug="teamSlug" :project="project">
                    <Button data-test="api-keys-new-button">
                        <Plus /> New key
                    </Button>
                </CreateApiKeyModal>

                <DropdownMenu v-if="canManage">
                    <DropdownMenuTrigger as-child>
                        <Button
                            variant="ghost"
                            size="icon"
                            class="text-muted-foreground"
                            data-test="project-actions"
                            aria-label="Project actions"
                        >
                            <MoreHorizontal class="size-4" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem
                            data-test="project-rename"
                            @select="renameOpen = true"
                        >
                            <Pencil /> Rename project
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            data-test="project-delete"
                            variant="destructive"
                            @select="deleteOpen = true"
                        >
                            <Trash2 /> Delete project
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </template>
        </PageHeader>

        <!--
          The facts, on the surface: one strip, hairlines between, the label
          small and the value in the data face.
        -->
        <div class="px-4 md:px-10">
            <dl
                class="grid grid-cols-2 border-y md:grid-cols-4"
                data-test="project-facts"
            >
                <div
                    class="flex flex-col gap-1 border-b py-4 pr-6 md:border-r md:border-b-0"
                >
                    <dt class="text-xs font-medium text-muted-foreground">
                        Slug
                    </dt>
                    <dd class="truncate font-mono text-sm text-foreground">
                        {{ project.slug }}
                    </dd>
                </div>
                <div
                    class="flex flex-col gap-1 border-b py-4 pl-6 md:border-r md:border-b-0"
                >
                    <dt class="text-xs font-medium text-muted-foreground">
                        API keys
                    </dt>
                    <dd class="font-mono text-sm text-foreground tabular-nums">
                        {{ keyCount }}
                    </dd>
                </div>
                <div class="flex flex-col gap-1 py-4 pr-6 md:border-r md:pl-6">
                    <dt class="text-xs font-medium text-muted-foreground">
                        Last key use
                    </dt>
                    <dd class="truncate text-sm text-foreground">
                        {{ lastUsedKey?.lastUsedForHumans ?? 'Never' }}
                    </dd>
                </div>
                <div class="flex flex-col gap-1 py-4 pl-6">
                    <dt class="text-xs font-medium text-muted-foreground">
                        Created
                    </dt>
                    <dd class="text-sm text-foreground">
                        {{ formatDate(project.createdAt) }}
                    </dd>
                </div>
            </dl>
        </div>

        <div class="flex flex-col gap-12 px-4 pt-8 pb-16 md:px-10">
            <!-- API keys -->
            <section class="flex flex-col" data-test="api-keys-section">
                <Heading variant="small" title="API keys">
                    <template #actions>
                        <span class="text-xs text-muted-foreground"
                            >only a hash is stored · a key is shown once</span
                        >
                    </template>
                </Heading>

                <template v-if="apiKeys.length > 0">
                    <div :class="cn(KEY_LANES, 'border-b py-2.5 eyebrow')">
                        <span>Key</span>
                        <span class="hidden text-right md:block"
                            >Last used</span
                        >
                        <span class="text-right">Created</span>
                        <span class="hidden md:block" aria-hidden="true" />
                    </div>

                    <ul data-test="api-keys-list">
                        <li
                            v-for="apiKey in apiKeys"
                            :key="apiKey.id"
                            :class="
                                cn(
                                    KEY_LANES,
                                    'border-b border-border/60 py-3.5',
                                )
                            "
                            data-test="api-key-row"
                        >
                            <div class="flex min-w-0 flex-col gap-0.5">
                                <span class="truncate text-sm font-medium">
                                    {{ apiKey.name }}
                                </span>
                                <span
                                    class="truncate font-mono text-xs text-muted-foreground"
                                    data-test="api-key-prefix"
                                >
                                    {{ maskedKey(apiKey.keyPrefix) }}
                                </span>
                            </div>

                            <span
                                :class="
                                    cn(
                                        'hidden text-right text-sm md:block',
                                        apiKey.lastUsedForHumans
                                            ? 'text-foreground'
                                            : 'text-muted-foreground',
                                    )
                                "
                                data-test="api-key-last-used"
                            >
                                {{ apiKey.lastUsedForHumans ?? 'Never used' }}
                            </span>

                            <span class="text-right text-sm text-foreground/75">
                                {{ formatDate(apiKey.createdAt) }}
                            </span>

                            <div class="hidden justify-end md:flex">
                                <Button
                                    v-if="canManage"
                                    variant="ghost"
                                    size="icon"
                                    class="size-8 text-muted-foreground hover:text-destructive"
                                    data-test="api-key-revoke"
                                    :aria-label="`Revoke ${apiKey.name}`"
                                    @click="openRevokeDialog(apiKey)"
                                >
                                    <Trash2 class="size-4" />
                                </Button>
                            </div>

                            <!--
                              The public half of the pair, and the only
                              credential here that is meant to be read twice:
                              a client configured with a URL cannot be handed
                              a token it can only see once.
                            -->
                            <div
                                v-if="apiKey.dsn"
                                class="col-span-full flex flex-col gap-1.5"
                                data-test="api-key-dsn"
                            >
                                <span class="eyebrow">DSN</span>
                                <CopyableValue
                                    :value="apiKey.dsn"
                                    :label="`Copy the DSN for ${apiKey.name}`"
                                />
                            </div>

                            <Button
                                v-if="canManage"
                                variant="ghost"
                                size="sm"
                                class="col-span-full w-fit text-muted-foreground md:hidden"
                                :aria-label="`Revoke ${apiKey.name}`"
                                @click="openRevokeDialog(apiKey)"
                            >
                                <Trash2 class="size-4" /> Revoke
                            </Button>
                        </li>
                    </ul>

                    <p class="pt-3 text-xs text-muted-foreground">
                        Bilis accepts requests from Sentry-compatible SDKs, so
                        pointing one at a DSN ships its exceptions here as error
                        logs.
                        <a
                            href="/docs/ingestion/sentry"
                            class="underline underline-offset-4 hover:text-foreground"
                            >Read more</a
                        >.
                    </p>
                </template>

                <div
                    v-else
                    class="flex flex-col items-start gap-3 py-8"
                    data-test="api-keys-empty"
                >
                    <div class="flex flex-col gap-1">
                        <p class="text-sm font-medium text-foreground">
                            No API keys yet
                        </p>
                        <p class="text-sm text-muted-foreground">
                            This project cannot receive logs until you issue a
                            key for it.
                        </p>
                    </div>

                    <CreateApiKeyModal :team-slug="teamSlug" :project="project">
                        <Button
                            variant="outline"
                            size="sm"
                            data-test="api-keys-empty-create"
                        >
                            <Plus /> Create an API key
                        </Button>
                    </CreateApiKeyModal>
                </div>
            </section>

            <!-- A Linux host: the one-line agent installer. -->
            <section class="flex flex-col gap-5" data-test="server-agent-card">
                <Heading
                    variant="small"
                    title="Monitor a server"
                    description="Run this on a Linux host to send its metrics, system logs and container stats to this project."
                />

                <ServerInstallCommand />

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="max-w-prose text-xs text-muted-foreground">
                        Replace
                        <code class="font-mono">bilis_YOUR_API_KEY</code>
                        with one of this project's keys. A key is shown only
                        when it is created — the dialog then fills this command
                        in for you.
                    </p>
                    <CreateApiKeyModal :team-slug="teamSlug" :project="project">
                        <Button
                            variant="outline"
                            size="sm"
                            data-test="server-agent-create-key"
                        >
                            <KeyRound /> Create a key with the command filled in
                        </Button>
                    </CreateApiKeyModal>
                </div>
            </section>

            <!-- Exceptions from Workers or a Sentry-compatible SDK. -->
            <section
                class="flex flex-col gap-5"
                data-test="error-monitoring-card"
            >
                <Heading
                    variant="small"
                    title="Monitor errors"
                    description="Ship exceptions from Cloudflare Workers or any app with a Sentry-compatible SDK. They land as error logs next to everything else this project sends."
                />

                <ErrorMonitoringSetup :dsn="snippetDsn" />
            </section>

            <ProjectRepositoryCard
                v-if="canManage"
                :team-slug="teamSlug"
                :project="project"
                :repositories="repositories"
                :observed-services="observedServices"
                :installations="installations"
                :autofix="autofix"
            />

            <BrowserOriginsCard
                v-if="canManage"
                :team-slug="teamSlug"
                :project="project"
            />
        </div>
    </div>

    <RenameProjectModal
        v-model:open="renameOpen"
        :team-slug="teamSlug"
        :project="project"
    />

    <DeleteProjectModal
        v-model:open="deleteOpen"
        :team-slug="teamSlug"
        :project="project"
    />

    <RevokeApiKeyModal
        v-model:open="revokeOpen"
        :team-slug="teamSlug"
        :project="project"
        :api-key="apiKeyRevoking"
    />

    <ApiKeyCreatedDialog
        v-model:open="createdApiKeyOpen"
        :api-key="createdApiKey"
    />
</template>
