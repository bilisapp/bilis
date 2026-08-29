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
    /** The browser origins allowed to post to this project's ingest endpoints. */
    allowedOrigins: string[];
    createdAt: string | null;
};

export type ProjectApiKey = {
    id: number;
    name: string;
    keyPrefix: string;
    /** The DSN built from this key's public half. */
    dsn: string | null;
    lastUsedAt: string | null;
    lastUsedForHumans: string | null;
    createdAt: string | null;
};

/** The plaintext secret key, flashed exactly once right after it is issued. */
export type NewProjectApiKey = {
    name: string;
    key: string;
    dsn: string | null;
};
