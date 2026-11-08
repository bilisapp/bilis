import type { AlertBadgeCounts, NewChannelSecret } from '@/types/alerts';
import type { Auth } from '@/types/auth';
import type { IssueBadgeCounts } from '@/types/issues';
import type { NewProjectApiKey } from '@/types/projects';
import type { Team } from '@/types/teams';
import type { FlashToast } from '@/types/ui';

// Extend ImportMeta interface for Vite...
declare module 'vite/client' {
    interface ImportMetaEnv {
        readonly VITE_APP_NAME: string;
        [key: string]: string | boolean | undefined;
    }

    interface ImportMeta {
        readonly env: ImportMetaEnv;
        readonly glob: <T>(pattern: string) => Record<string, () => Promise<T>>;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        flashDataType: {
            toast?: FlashToast;
            newApiKey?: NewProjectApiKey;
            channelSecret?: NewChannelSecret;
        };
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            currentTeam: Team | null;
            teams: Team[];
            autofix: { enabled: boolean };
            /** Deferred and kept once per minute; null when storage is busy. */
            issueBadge?: IssueBadgeCounts | null;
            /** Deferred and kept once per minute: series firing now. */
            alertBadge?: AlertBadgeCounts | null;
            [key: string]: unknown;
        };
    }
}

declare module 'vue' {
    interface ComponentCustomProperties {
        $inertia: typeof Router;
        $page: Page;
        $headManager: ReturnType<typeof createHeadManager>;
    }
}
