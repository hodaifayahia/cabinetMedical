import type { DesktopDownload } from '@/types/desktop';

export type DesktopChromeVisibility = {
    administration: boolean;
    installer: boolean;
};

/**
 * The hosted web interface advertises the installer and can link platform
 * administrators to Filament. Those links are intentionally omitted inside
 * the installed shell: downloading the app from itself is confusing and the
 * platform back office belongs in the browser.
 */
export function desktopChromeVisibility(
    desktopRuntime: boolean,
    canAccessAdminPanel: boolean,
    desktopDownload: DesktopDownload | null | undefined,
): DesktopChromeVisibility {
    return {
        administration: !desktopRuntime && canAccessAdminPanel,
        installer: !desktopRuntime && Boolean(desktopDownload),
    };
}
