import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { expect, test } from 'vitest';

const tauriConfig = JSON.parse(
    readFileSync(resolve(process.cwd(), 'src-tauri/tauri.conf.json'), 'utf8'),
) as {
    bundle: {
        windows: {
            nsis: { installerHooks?: string };
        };
    };
};

const installerHook = readFileSync(
    resolve(process.cwd(), 'src-tauri/windows/installer-hooks.nsh'),
    'utf8',
);

test('the NSIS bundle runs the legacy-product migration before installation', () => {
    expect(tauriConfig.bundle.windows.nsis.installerHooks).toBe(
        'windows/installer-hooks.nsh',
    );
    expect(installerHook).toContain('!macro NSIS_HOOK_PREINSTALL');
    expect(installerHook).toContain(
        '!insertmacro DRCLICK_REMOVE_LEGACY_INSTALL "MediSmart" "medismart-desktop"',
    );
    expect(installerHook).toContain(
        '!insertmacro DRCLICK_REMOVE_LEGACY_INSTALL "DrClickDz" "DrClickDz"',
    );
    expect(installerHook).toContain(
        '!insertmacro CheckIfAppIsRunning "${MAIN_BINARY_NAME}.exe" "${PRODUCT_NAME}"',
    );
});

test('the migration removes only exact legacy artifacts and preserves data', () => {
    expect(installerHook).toContain(
        'Delete "$LOCALAPPDATA\\${PRODUCT_NAME}\\${MAIN_BINARY_NAME}.exe"',
    );
    expect(installerHook).toContain(
        'Delete "$LOCALAPPDATA\\${PRODUCT_NAME}\\uninstall.exe"',
    );
    expect(installerHook).toContain(
        'Delete "$SMPROGRAMS\\${PRODUCT_NAME}.lnk"',
    );
    expect(installerHook).toContain('Delete "$DESKTOP\\${PRODUCT_NAME}.lnk"');
    expect(installerHook).toContain(
        'Delete "$QUICKLAUNCH\\User Pinned\\TaskBar\\${PRODUCT_NAME}.lnk"',
    );
    expect(installerHook).toContain(
        'DeleteRegKey HKCU "Software\\Microsoft\\Windows\\CurrentVersion\\Uninstall\\${PRODUCT_NAME}"',
    );

    expect(installerHook).not.toMatch(/\bRMDir\b/i);
    expect(installerHook).not.toMatch(/\bExec(?:Wait)?\b/i);
    expect(installerHook).not.toMatch(
        /Delete\s+"\$(?:LOCALAPPDATA|APPDATA)\\\$\{PRODUCT_NAME\}"/i,
    );
    expect(installerHook).not.toMatch(
        /(?:Delete|RMDir)[^\r\n]*dz\.click\.medismart/i,
    );
    expect(installerHook).not.toMatch(
        /(?:Delete|RMDir)[^\r\n]*\\(?:binaries|cloudflared|data|initial|laravel|license|php)(?:\\|"|$)/i,
    );
});
