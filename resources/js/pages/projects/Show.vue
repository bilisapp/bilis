<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { KeyRound, MoreHorizontal, Pencil, Plus, Trash2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import ApiKeyCreatedDialog from '@/components/ApiKeyCreatedDialog.vue';
import CreateApiKeyModal from '@/components/CreateApiKeyModal.vue';
import DeleteProjectModal from '@/components/DeleteProjectModal.vue';
import RenameProjectModal from '@/components/RenameProjectModal.vue';
import RevokeApiKeyModal from '@/components/RevokeApiKeyModal.vue';
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
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { formatDate, maskedKey } from '@/lib/projects';
import { index as projectsIndex, show as projectShow } from '@/routes/projects';
import type {
    NewProjectApiKey,
    ProjectApiKey,
    ProjectDetail,
    Team,
} from '@/types';

const props = defineProps<{
    project: ProjectDetail;
    apiKeys: ProjectApiKey[];
    teamSlug: string;
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

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <h1 class="sr-only">{{ project.name }}</h1>

        <div class="flex items-start justify-between gap-4">
            <div class="space-y-1">
                <div class="flex items-center gap-2">
                    <Badge variant="secondary" class="font-mono">
                        {{ project.slug }}
                    </Badge>
                    <span class="text-sm text-muted-foreground">
                        Created {{ formatDate(project.createdAt) }}
                    </span>
                </div>
                <p class="max-w-prose text-sm text-muted-foreground">
                    Send logs with
                    <code class="font-mono"
                        >Authorization: Bearer &lt;key&gt;</code
                    >
                    — the key decides which project the logs land in.
                </p>
            </div>

            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button
                        variant="ghost"
                        size="sm"
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
        </div>

        <Card>
            <CardHeader>
                <CardTitle>API keys</CardTitle>
                <CardDescription>
                    {{ keyCount }} {{ keyCount === 1 ? 'key' : 'keys' }} issued.
                    Only a hash is stored, so a key is shown once at creation.
                </CardDescription>

                <CardAction>
                    <CreateApiKeyModal :team-slug="teamSlug" :project="project">
                        <Button size="sm" data-test="api-keys-new-button">
                            <Plus /> New key
                        </Button>
                    </CreateApiKeyModal>
                </CardAction>
            </CardHeader>

            <CardContent>
                <ul
                    v-if="apiKeys.length > 0"
                    class="divide-y divide-border"
                    data-test="api-keys-list"
                >
                    <li
                        v-for="apiKey in apiKeys"
                        :key="apiKey.id"
                        class="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0"
                        data-test="api-key-row"
                    >
                        <div class="min-w-0 space-y-1">
                            <p class="truncate font-medium">
                                {{ apiKey.name }}
                            </p>
                            <p
                                class="font-mono text-xs text-muted-foreground"
                                data-test="api-key-prefix"
                            >
                                {{ maskedKey(apiKey.keyPrefix) }}
                            </p>
                        </div>

                        <div class="flex items-center gap-4">
                            <div
                                class="text-right text-xs text-muted-foreground"
                            >
                                <p data-test="api-key-last-used">
                                    {{
                                        apiKey.lastUsedForHumans
                                            ? `Last used ${apiKey.lastUsedForHumans}`
                                            : 'Never used'
                                    }}
                                </p>
                                <p>
                                    Created {{ formatDate(apiKey.createdAt) }}
                                </p>
                            </div>

                            <Button
                                variant="ghost"
                                size="sm"
                                data-test="api-key-revoke"
                                :aria-label="`Revoke ${apiKey.name}`"
                                @click="openRevokeDialog(apiKey)"
                            >
                                <Trash2 class="size-4" />
                            </Button>
                        </div>
                    </li>
                </ul>

                <div
                    v-else
                    class="flex flex-col items-center gap-3 py-8 text-center"
                    data-test="api-keys-empty"
                >
                    <span
                        class="flex size-11 items-center justify-center rounded-full bg-muted text-muted-foreground"
                    >
                        <KeyRound class="size-5" />
                    </span>

                    <div class="space-y-1">
                        <p class="font-medium">No API keys yet</p>
                        <p class="text-sm text-muted-foreground">
                            This project cannot receive logs until you issue a
                            key for it.
                        </p>
                    </div>

                    <CreateApiKeyModal :team-slug="teamSlug" :project="project">
                        <Button data-test="api-keys-empty-create">
                            <Plus /> Create an API key
                        </Button>
                    </CreateApiKeyModal>
                </div>
            </CardContent>
        </Card>
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
