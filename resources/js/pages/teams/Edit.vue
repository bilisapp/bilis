<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import { ChevronDown, Mail, UserPlus, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import CancelInvitationModal from '@/components/CancelInvitationModal.vue';
import DeleteLlmCredentialModal from '@/components/DeleteLlmCredentialModal.vue';
import DeleteTeamModal from '@/components/DeleteTeamModal.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import InviteMemberModal from '@/components/InviteMemberModal.vue';
import RemoveMemberModal from '@/components/RemoveMemberModal.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useInitials } from '@/composables/useInitials';
import { edit, index, update } from '@/routes/teams';
import {
    store as storeLlmCredential,
    update as makeLlmCredentialDefault,
} from '@/routes/teams/llm-credentials';
import { update as updateMember } from '@/routes/teams/members';
import type {
    LlmProviderOption,
    RoleOption,
    Team,
    TeamInvitation,
    TeamLlmCredential,
    TeamMember,
    TeamPermissions,
} from '@/types';

type Props = {
    team: Team;
    members: TeamMember[];
    invitations: TeamInvitation[];
    permissions: TeamPermissions;
    availableRoles: RoleOption[];
    llmCredentials: TeamLlmCredential[];
    llmProviders: LlmProviderOption[];
};

const props = defineProps<Props>();

/** Which provider the add-a-key form is currently for. */
const newKeyProvider = ref<string>(props.llmProviders[0]?.value ?? 'anthropic');

const newKeyPlaceholder = computed(
    () =>
        props.llmProviders.find(
            (provider) => provider.value === newKeyProvider.value,
        )?.placeholder ?? 'sk-...',
);

const credentialToDelete = ref<TeamLlmCredential | null>(null);
const deleteCredentialOpen = ref(false);

const confirmDeleteCredential = (credential: TeamLlmCredential) => {
    credentialToDelete.value = credential;
    deleteCredentialOpen.value = true;
};

const makeDefault = (credential: TeamLlmCredential) => {
    router.visit(makeLlmCredentialDefault([props.team.slug, credential.id]), {
        preserveScroll: true,
    });
};

/** The day a key was added, in the viewer's locale. */
const addedOn = (credential: TeamLlmCredential) =>
    credential.createdAt
        ? new Date(credential.createdAt).toLocaleDateString()
        : null;

defineOptions({
    layout: (props: { team: Team }) => ({
        breadcrumbs: [
            {
                title: 'Teams',
                href: index(),
            },
            {
                title: props.team.name,
                href: edit(props.team.slug),
            },
        ],
    }),
});

const { getInitials } = useInitials();

const inviteDialogOpen = ref(false);
const deleteDialogOpen = ref(false);
const removeMemberDialogOpen = ref(false);
const memberToRemove = ref<TeamMember | null>(null);
const cancelInvitationDialogOpen = ref(false);
const invitationToCancel = ref<TeamInvitation | null>(null);

const pageTitle = computed(() =>
    props.permissions.canUpdateTeam
        ? `Edit ${props.team.name}`
        : `View ${props.team.name}`,
);

const updateMemberRole = (member: TeamMember, newRole: string) => {
    router.visit(updateMember([props.team.slug, member.id]), {
        data: { role: newRole },
        preserveScroll: true,
    });
};

const confirmRemoveMember = (member: TeamMember) => {
    memberToRemove.value = member;
    removeMemberDialogOpen.value = true;
};

const confirmCancelInvitation = (invitation: TeamInvitation) => {
    invitationToCancel.value = invitation;
    cancelInvitationDialogOpen.value = true;
};
</script>

<template>
    <Head :title="pageTitle" />

    <h1 class="sr-only">{{ pageTitle }}</h1>

    <div class="flex flex-col space-y-10">
        <!-- Team Name Section -->
        <div v-if="permissions.canUpdateTeam" class="space-y-6">
            <Heading
                variant="small"
                title="Team settings"
                description="Update your team name and settings"
            />

            <Form
                v-bind="update.form(team.slug)"
                class="space-y-6"
                v-slot="{ errors, processing }"
            >
                <div class="grid gap-2">
                    <Label for="name">Team name</Label>
                    <Input
                        id="name"
                        name="name"
                        data-test="team-name-input"
                        :default-value="team.name"
                        required
                    />
                    <InputError :message="errors.name" />
                </div>

                <div class="flex items-center gap-4">
                    <Button
                        type="submit"
                        data-test="team-save-button"
                        :disabled="processing"
                    >
                        Save
                    </Button>
                </div>
            </Form>
        </div>

        <div v-else class="space-y-6">
            <Heading variant="small" :title="team.name" />
        </div>

        <!-- Model API keys -->
        <div v-if="permissions.canUpdateTeam" class="space-y-6">
            <Heading
                variant="small"
                title="Model API keys"
                description="The keys your team's autofix jobs run on. Scope each one to this team and give it a spend limit at the provider."
            />

            <div
                v-if="llmCredentials.length"
                class="divide-y divide-border rounded-lg border border-border"
                data-test="team-llm-credentials"
            >
                <div
                    v-for="credential in llmCredentials"
                    :key="credential.id"
                    class="flex flex-wrap items-center justify-between gap-3 p-4"
                >
                    <div class="min-w-0 space-y-1">
                        <div class="flex items-center gap-2">
                            <span class="truncate font-medium">
                                {{ credential.label }}
                            </span>
                            <Badge
                                v-if="credential.isDefault"
                                variant="secondary"
                            >
                                Default
                            </Badge>
                        </div>
                        <p class="text-sm text-muted-foreground">
                            {{ credential.providerLabel }} · key ending
                            <span class="font-mono">{{ credential.hint }}</span>
                            <template v-if="addedOn(credential)">
                                · added {{ addedOn(credential) }}
                                <DeleteLlmCredentialModal
                                    v-model:open="deleteCredentialOpen"
                                    :team-slug="team.slug"
                                    :credential="credentialToDelete"
                                />
                            </template>
                        </p>
                    </div>

                    <div class="flex items-center gap-2">
                        <Button
                            v-if="!credential.isDefault"
                            variant="secondary"
                            size="sm"
                            data-test="team-llm-credential-make-default"
                            @click="makeDefault(credential)"
                        >
                            Make default
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            data-test="team-llm-credential-delete"
                            @click="confirmDeleteCredential(credential)"
                        >
                            <X class="size-4" />
                            <span class="sr-only"
                                >Remove {{ credential.label }}</span
                            >
                        </Button>
                    </div>
                </div>
            </div>

            <p v-else class="text-sm text-muted-foreground">
                No keys yet, so jobs fall back to this installation's key if it
                has one and fail if it does not. Add a key below to run jobs on
                your own budget.
            </p>

            <Form
                v-bind="storeLlmCredential.form(team.slug)"
                class="space-y-6"
                :reset-on-success="['api_key', 'label']"
                v-slot="{ errors, processing }"
            >
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="llm_provider">Provider</Label>
                        <Select v-model="newKeyProvider">
                            <SelectTrigger
                                id="llm_provider"
                                class="w-full"
                                data-test="team-llm-credential-provider"
                            >
                                <SelectValue placeholder="Pick a provider" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="provider in llmProviders"
                                    :key="provider.value"
                                    :value="provider.value"
                                >
                                    {{ provider.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <input
                            type="hidden"
                            name="provider"
                            :value="newKeyProvider"
                        />
                        <InputError :message="errors.provider" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="llm_label">Name</Label>
                        <Input
                            id="llm_label"
                            name="label"
                            autocomplete="off"
                            placeholder="Production budget"
                            data-test="team-llm-credential-label"
                        />
                        <InputError :message="errors.label" />
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="llm_api_key">API key</Label>
                    <Input
                        id="llm_api_key"
                        name="api_key"
                        type="password"
                        autocomplete="off"
                        data-test="team-llm-credential-input"
                        :placeholder="newKeyPlaceholder"
                    />
                    <InputError :message="errors.api_key" />
                    <p class="text-sm text-muted-foreground">
                        Stored encrypted and never shown again — only the last
                        four characters come back, so you can tell your keys
                        apart. To replace a key, add the new one and remove the
                        old.
                    </p>
                </div>

                <div class="flex items-center gap-4">
                    <Button
                        type="submit"
                        data-test="team-llm-credential-save-button"
                        :disabled="processing"
                    >
                        Add key
                    </Button>
                </div>
            </Form>
        </div>

        <!-- Members Section -->
        <div class="space-y-6">
            <div class="flex items-center justify-between">
                <Heading
                    variant="small"
                    title="Team members"
                    :description="
                        permissions.canCreateInvitation
                            ? 'Manage who belongs to this team'
                            : ''
                    "
                />

                <Button
                    v-if="permissions.canCreateInvitation"
                    data-test="invite-member-button"
                    @click="inviteDialogOpen = true"
                >
                    <UserPlus /> Invite member
                </Button>
            </div>

            <div class="space-y-3">
                <div
                    v-for="member in members"
                    :key="member.id"
                    data-test="member-row"
                    class="flex items-center justify-between rounded-lg border p-4"
                >
                    <div class="flex items-center gap-4">
                        <Avatar class="h-10 w-10">
                            <AvatarImage
                                v-if="member.avatar"
                                :src="member.avatar"
                                :alt="member.name"
                            />
                            <AvatarFallback>{{
                                getInitials(member.name)
                            }}</AvatarFallback>
                        </Avatar>
                        <div>
                            <div class="font-medium">
                                {{ member.name }}
                            </div>
                            <div class="text-sm text-muted-foreground">
                                {{ member.email }}
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <DropdownMenu
                            v-if="
                                member.role !== 'owner' &&
                                permissions.canUpdateMember
                            "
                        >
                            <DropdownMenuTrigger as-child>
                                <Button
                                    data-test="member-role-trigger"
                                    variant="outline"
                                    size="sm"
                                >
                                    {{ member.role_label }}
                                    <ChevronDown
                                        class="ml-2 h-4 w-4 opacity-50"
                                    />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent>
                                <DropdownMenuItem
                                    v-for="role in availableRoles"
                                    :key="role.value"
                                    data-test="member-role-option"
                                    @click="
                                        updateMemberRole(member, role.value)
                                    "
                                >
                                    {{ role.label }}
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                        <Badge v-else variant="secondary">
                            {{ member.role_label }}
                        </Badge>

                        <TooltipProvider
                            v-if="
                                member.role !== 'owner' &&
                                permissions.canRemoveMember
                            "
                        >
                            <Tooltip>
                                <TooltipTrigger as-child>
                                    <Button
                                        data-test="member-remove-button"
                                        variant="ghost"
                                        size="sm"
                                        @click="confirmRemoveMember(member)"
                                    >
                                        <X class="h-4 w-4" />
                                    </Button>
                                </TooltipTrigger>
                                <TooltipContent>
                                    <p>Remove member</p>
                                </TooltipContent>
                            </Tooltip>
                        </TooltipProvider>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pending Invitations Section -->
        <div v-if="invitations.length > 0" class="space-y-6">
            <Heading
                variant="small"
                title="Pending invitations"
                description="Invitations that haven't been accepted yet"
            />

            <div class="space-y-3">
                <div
                    v-for="invitation in invitations"
                    :key="invitation.code"
                    data-test="invitation-row"
                    class="flex items-center justify-between rounded-lg border p-4"
                >
                    <div class="flex items-center gap-4">
                        <div
                            class="flex h-10 w-10 items-center justify-center rounded-full bg-muted"
                        >
                            <Mail class="h-5 w-5 text-muted-foreground" />
                        </div>
                        <div>
                            <div class="font-medium">
                                {{ invitation.email }}
                            </div>
                            <div class="text-sm text-muted-foreground">
                                {{ invitation.role_label }}
                            </div>
                        </div>
                    </div>

                    <TooltipProvider v-if="permissions.canCancelInvitation">
                        <Tooltip>
                            <TooltipTrigger as-child>
                                <Button
                                    data-test="invitation-cancel-button"
                                    variant="ghost"
                                    size="sm"
                                    @click="confirmCancelInvitation(invitation)"
                                >
                                    <X class="h-4 w-4" />
                                </Button>
                            </TooltipTrigger>
                            <TooltipContent>
                                <p>Cancel invitation</p>
                            </TooltipContent>
                        </Tooltip>
                    </TooltipProvider>
                </div>
            </div>
        </div>

        <!-- Danger Zone -->
        <div
            v-if="permissions.canDeleteTeam && !team.isPersonal"
            class="space-y-6"
        >
            <Heading
                variant="small"
                title="Delete team"
                description="Permanently delete your team"
            />
            <div
                class="space-y-4 rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10"
            >
                <div
                    class="relative space-y-0.5 text-red-600 dark:text-red-100"
                >
                    <p class="font-medium">Warning</p>
                    <p class="text-sm">
                        Please proceed with caution, this cannot be undone.
                    </p>
                </div>
                <Button
                    data-test="delete-team-button"
                    variant="destructive"
                    @click="deleteDialogOpen = true"
                    >Delete team</Button
                >
            </div>
        </div>
    </div>

    <InviteMemberModal
        v-if="permissions.canCreateInvitation"
        :team="team"
        :available-roles="availableRoles"
        :open="inviteDialogOpen"
        @update:open="inviteDialogOpen = $event"
    />

    <RemoveMemberModal
        :team="team"
        :member="memberToRemove"
        :open="removeMemberDialogOpen"
        @update:open="removeMemberDialogOpen = $event"
    />

    <CancelInvitationModal
        :team="team"
        :invitation="invitationToCancel"
        :open="cancelInvitationDialogOpen"
        @update:open="cancelInvitationDialogOpen = $event"
    />

    <DeleteTeamModal
        v-if="permissions.canDeleteTeam && !team.isPersonal"
        :team="team"
        :open="deleteDialogOpen"
        @update:open="deleteDialogOpen = $event"
    />
    <DeleteLlmCredentialModal
        v-model:open="deleteCredentialOpen"
        :team-slug="team.slug"
        :credential="credentialToDelete"
    />
</template>
