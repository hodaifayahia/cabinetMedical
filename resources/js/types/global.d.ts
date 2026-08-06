import type { Auth, SessionLockState } from '@/types/auth';
import type { Cabinet } from '@/types/cabinet';

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
    type DesktopDownload = {
        available: boolean;
        url: string | null;
        label: string;
        reason: string | null;
    };

    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            cabinet: Cabinet;
            auth: Auth;
            desktopDownload: DesktopDownload;
            sessionLock: SessionLockState | null;
            sidebarOpen: boolean;
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
