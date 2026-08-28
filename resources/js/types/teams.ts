export type TeamRole = 'owner' | 'admin' | 'member';

export type LlmProviderValue = 'anthropic' | 'openai' | 'openrouter';

/**
 * What the settings page and the new-job picker know about one model API key.
 *
 * Never the key itself — only which provider it is for, what the customer
 * called it, and its last four characters, so two keys can be told apart
 * without either ever being shown again.
 */
export type TeamLlmCredential = {
    id: number;
    provider: LlmProviderValue;
    providerLabel: string;
    label: string;
    hint: string | null;
    isDefault: boolean;
    lastUsedAt: string | null;
    createdAt: string | null;
};

/** A provider a credential can be added for. */
export type LlmProviderOption = {
    value: LlmProviderValue;
    label: string;
    placeholder: string;
};

export type Team = {
    id: number;
    name: string;
    slug: string;
    isPersonal: boolean;
    role?: TeamRole;
    roleLabel?: string;
    isCurrent?: boolean;
};

export type TeamMember = {
    id: number;
    name: string;
    email: string;
    avatar?: string | null;
    role: TeamRole;
    role_label: string;
};

export type TeamInvitation = {
    code: string;
    email: string;
    role: TeamRole;
    role_label: string;
    created_at: string;
};

export type TeamInvitationContext = {
    code: string;
    teamName: string;
};

export type DashboardInvitation = {
    code: string;
    inviterName: string;
    team: {
        name: string;
        slug: string;
    };
};

export type TeamPermissions = {
    canUpdateTeam: boolean;
    canDeleteTeam: boolean;
    canAddMember: boolean;
    canUpdateMember: boolean;
    canRemoveMember: boolean;
    canCreateInvitation: boolean;
    canCancelInvitation: boolean;
};

export type RoleOption = {
    value: TeamRole;
    label: string;
};
