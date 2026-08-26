export type ProjectSummary = {
    id: number;
    name: string;
    slug: string;
    apiKeysCount: number;
    createdAt: string | null;
};

export type ProjectDetail = {
    id: number;
    name: string;
    slug: string;
    createdAt: string | null;
};

export type ProjectApiKey = {
    id: number;
    name: string;
    keyPrefix: string;
    lastUsedAt: string | null;
    lastUsedForHumans: string | null;
    createdAt: string | null;
};

/** The plaintext key, flashed exactly once right after it is issued. */
export type NewProjectApiKey = {
    name: string;
    key: string;
};
