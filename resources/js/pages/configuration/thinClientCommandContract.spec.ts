import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

const componentSource = readFileSync(
    resolve(
        process.cwd(),
        'resources/js/pages/configuration/ConnectivityAndBackup.vue',
    ),
    'utf8',
);

describe('hosted desktop command contract', () => {
    it('only invokes the signed updater commands retained by the thin client', () => {
        const invokedCommands = [
            ...componentSource.matchAll(
                /\binvoke(?:<[^>]+>)?\(\s*['"]([^'"]+)['"]/gu,
            ),
        ].map((match) => match[1]);

        expect(invokedCommands).toEqual([
            'signed_updater_status',
            'check_for_signed_update',
            'install_signed_update',
        ]);
    });

    it('does not reference commands removed with the bundled runtime', () => {
        for (const removedCommand of [
            'list_lan_adapters',
            'apply_lan_listener_configuration',
            'apply_prepared_offline_restore',
            'open_google_oauth_authorization',
        ]) {
            expect(componentSource).not.toContain(removedCommand);
        }
    });

    it('keeps settings server-backed and OAuth on the guarded navigation path', () => {
        expect(componentSource).toContain('form.put(settingsUrl');
        expect(componentSource).toContain(
            'Préférences enregistrées sur le serveur DrClickDz.',
        );
        expect(componentSource).toContain('openGoogleAuthorization(');
    });
});
