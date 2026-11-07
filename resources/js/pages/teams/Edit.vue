<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import { ChevronDown, Mail, UserPlus, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import CancelInvitationModal from '@/components/CancelInvitationModal.vue';
import ConnectAgentCard from '@/components/ConnectAgentCard.vue';
import DeleteLlmCredentialModal from '@/components/DeleteLlmCredentialModal.vue';
import DeleteTeamModal from '@/components/DeleteTeamModal.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import InviteMemberModal from '@/components/InviteMemberModal.vue';
import PlanUsageCard from '@/components/PlanUsageCard.vue';
import RemoveMemberModal from '@/components/RemoveMemberModal.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
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
import { show as docsShow } from '@/routes/docs';
import { edit, index, update } from '@/routes/teams';
import {
    store as storeLlmCredential,
    update as makeLlmCredentialDefault,
} from '@/routes/teams/llm-credentials';
import { update as updateMember } from '@/routes/teams/members';
import type {
    LlmProviderOption,
    PlanUsage,
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
    planUsage: PlanUsage;
    mcpUrl: string;
};

const props = defineProps<Props>();

/** The MCP guide, so the connect card links rather than restates. */
const mcpDocsHref = docsShow({ section: 'reference', page: 'mcp' }).url;

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

    <!-- Team name -->
    <div v-if="permissions.canUpdateTeam" class="flex flex-col gap-5">
        <Heading
            variant="small"
            title="Team settings"
            description="Update your team name and settings"
        />

        <Form
            v-bind="update.form(team.slug)"
            class="flex flex-col gap-5"
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

    <Heading v-else variant="small" :title="team.name" />

    <!--
      Plan and usage. It sits here rather than only on the dashboard because
      members are added on this page: the meter that says how many seats the
      Free plan carries belongs right above the button that spends one. It
      still gates nothing. The section carries its own heading.
    -->
    <PlanUsageCard :usage="planUsage" />

    <!-- Members -->
    <div class="flex flex-col gap-2">
        <Heading
            variant="small"
            title="Team members"
            :description="
                permissions.canCreateInvitation
                    ? 'Manage who belongs to this team'
                    : ''
            "
        >
            <template v-if="permissions.canCreateInvitation" #actions>
                <Button
                    size="sm"
                    data-test="invite-member-button"
                    @click="inviteDialogOpen = true"
                >
                    <UserPlus /> Invite member
                </Button>
            </template>
        </Heading>

        <div>
            <div
                v-for="member in members"
                :key="member.id"
                data-test="member-row"
                class="flex min-h-14 items-center justify-between gap-4 border-b border-border/60 py-2.5"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <Avatar class="size-8">
                        <AvatarImage
                            v-if="member.avatar"
                            :src="member.avatar"
                            :alt="member.name"
                        />
                        <AvatarFallback class="text-xs">{{
                            getInitials(member.name)
                        }}</AvatarFallback>
                    </Avatar>
                    <div class="flex min-w-0 flex-col gap-0.5">
                        <span class="truncate text-sm font-medium">
                            {{ member.name }}
                        </span>
                        <span class="truncate text-xs text-muted-foreground">
                            {{ member.email }}
                        </span>
                    </div>
                </div>

                <div class="flex shrink-0 items-center gap-1">
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
                                <ChevronDown class="size-4 opacity-50" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem
                                v-for="role in availableRoles"
                                :key="role.value"
                                data-test="member-role-option"
                                @click="updateMemberRole(member, role.value)"
                            >
                                {{ role.label }}
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                    <span
                        v-else
                        class="inline-flex h-5 items-center rounded-full px-2 text-[11px] font-medium text-foreground/80 ring-1 ring-border ring-inset"
                    >
                        {{ member.role_label }}
                    </span>

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
                                    class="text-muted-foreground"
                                    :aria-label="`Remove ${member.name}`"
                                    @click="confirmRemoveMember(member)"
                                >
                                    <X class="size-4" />
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

    <!-- Pending invitations -->
    <div v-if="invitations.length > 0" class="flex flex-col gap-2">
        <Heading
            variant="small"
            title="Pending invitations"
            description="Invitations that haven't been accepted yet"
        />

        <div>
            <div
                v-for="invitation in invitations"
                :key="invitation.code"
                data-test="invitation-row"
                class="flex min-h-14 items-center justify-between gap-4 border-b border-border/60 py-2.5"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <span
                        class="flex size-8 shrink-0 items-center justify-center rounded-full bg-muted"
                    >
                        <Mail class="size-4 text-muted-foreground" />
                    </span>
                    <div class="flex min-w-0 flex-col gap-0.5">
                        <span class="truncate text-sm font-medium">
                            {{ invitation.email }}
                        </span>
                        <span class="text-xs text-muted-foreground">
                            {{ invitation.role_label }}
                        </span>
                    </div>
                </div>

                <TooltipProvider v-if="permissions.canCancelInvitation">
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <Button
                                data-test="invitation-cancel-button"
                                variant="ghost"
                                size="sm"
                                class="text-muted-foreground"
                                :aria-label="`Cancel the invitation for ${invitation.email}`"
                                @click="confirmCancelInvitation(invitation)"
                            >
                                <X class="size-4" />
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

    <!--
      The MCP connection. It sits on the team page because the access it
      grants is team-wide: one command connects an agent to every project the
      team owns, read-only.
    -->
    <ConnectAgentCard :url="mcpUrl" :docs-href="mcpDocsHref" />

    <!-- Model API keys -->
    <div v-if="permissions.canUpdateTeam" class="flex flex-col gap-5">
        <Heading
            variant="small"
            title="Model API keys"
            description="The keys your team's autofix jobs run on. Scope each one to this team and give it a spend limit at the provider."
        />

        <div v-if="llmCredentials.length" data-test="team-llm-credentials">
            <div
                v-for="credential in llmCredentials"
                :key="credential.id"
                class="flex min-h-14 flex-wrap items-center justify-between gap-3 border-b border-border/60 py-2.5"
            >
                <div class="flex min-w-0 flex-col gap-0.5">
                    <div class="flex min-w-0 items-center gap-2">
                        <span class="truncate text-sm font-medium">
                            {{ credential.label }}
                        </span>
                        <span
                            v-if="credential.isDefault"
                            class="inline-flex h-5 shrink-0 items-center rounded-full px-2 text-[11px] font-medium text-foreground/80 ring-1 ring-border ring-inset"
                        >
                            Default
                        </span>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        {{ credential.providerLabel }} · key ending
                        <span class="font-mono">{{ credential.hint }}</span>
                        <template v-if="addedOn(credential)">
                            · added {{ addedOn(credential) }}
                        </template>
                    </p>
                </div>

                <div class="flex items-center gap-1">
                    <Button
                        v-if="!credential.isDefault"
                        variant="outline"
                        size="sm"
                        data-test="team-llm-credential-make-default"
                        @click="makeDefault(credential)"
                    >
                        Make default
                    </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        class="text-muted-foreground"
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

        <p v-else class="max-w-prose text-sm text-muted-foreground">
            No keys yet, so jobs fall back to this installation's key if it has
            one and fail if it does not. Add a key below to run jobs on your own
            budget.
        </p>

        <Form
            v-bind="storeLlmCredential.form(team.slug)"
            class="flex flex-col gap-5"
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
                <p class="text-xs text-muted-foreground">
                    Stored encrypted and never shown again — only the last four
                    characters come back, so you can tell your keys apart. To
                    replace a key, add the new one and remove the old.
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

    <!-- Danger zone: `destructive` is the one stated exception to ink-only chrome. -->
    <div
        v-if="permissions.canDeleteTeam && !team.isPersonal"
        class="flex flex-col gap-5"
    >
        <Heading variant="small" title="Delete team" />
        <div class="flex flex-wrap items-center justify-between gap-4">
            <p class="max-w-prose text-sm text-muted-foreground">
                Permanently delete this team and its memberships.
                <span class="font-medium text-destructive"
                    >This cannot be undone.</span
                >
            </p>
            <Button
                data-test="delete-team-button"
                variant="destructive"
                @click="deleteDialogOpen = true"
                >Delete team</Button
            >
        </div>
    </div>

    <InviteMemberModal
        v-if="permissions.canCreateInvitation"
        :team="team"
        :available-roles="availableRoles"
        :open="inviteDialogOpen"
        :allowance="planUsage.members"
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
