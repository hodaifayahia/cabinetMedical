import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

const read = (path: string): string =>
    readFileSync(resolve(process.cwd(), `resources/js/${path}`), 'utf8');

describe('desktop PIN user-flow contract', () => {
    it('enrolls only after server success with every device-bound field', () => {
        const source = read('components/DesktopPinEnrollment.vue');

        expect(source).toContain("form.post('/desktop/pin/enroll'");

        for (const field of [
            'device_token',
            'pin',
            'pin_confirmation',
            'device_name',
        ]) {
            expect(source).toContain(`name="${field}"`);
        }

        const request = source.indexOf("form.post('/desktop/pin/enroll'");
        const success = source.indexOf('onSuccess:', request);
        const persistence = source.indexOf(
            'saveDesktopPinEnrollment(',
            success,
        );

        expect(request).toBeGreaterThan(0);
        expect(success).toBeGreaterThan(request);
        expect(persistence).toBeGreaterThan(success);
        expect(source).toContain('enrollingUser.id');
        expect(source).toContain('enrollingUser.name');
    });

    it('blocks cabinet pages until a numeric confirmed PIN is enrolled', () => {
        const overlay = read('components/DesktopPinEnrollment.vue');
        const appLayout = read('layouts/AppLayout.vue');
        const pending = read('pages/auth/PendingActivation.vue');

        expect(overlay).toContain('role="dialog"');
        expect(overlay).toContain('aria-modal="true"');
        expect(overlay).toContain('pattern="[0-9]{4}"');
        expect(overlay).toContain('inputmode="numeric"');
        expect(overlay.match(/class="grid min-w-0 gap-2"/gu)).toHaveLength(2);
        expect(overlay.match(/h-14 w-full min-w-0 rounded-2xl/gu)).toHaveLength(
            2,
        );
        expect(overlay).toContain('form.pin_confirmation !== form.pin');
        expect(overlay).toContain('!user?.can.accessAdminPanel');
        expect(overlay).toContain(
            'isDesktopPinEnrollmentForUser(localEnrollment.value, user?.id)',
        );
        expect(appLayout).toContain('<DesktopPinEnrollment />');
        expect(pending).toContain('<DesktopPinEnrollment />');
    });

    it('shows only account-bound fast PIN login for an enrolled desktop', () => {
        const login = read('pages/auth/Login.vue');

        expect(login).toContain('v-if="runtimeResolved && pinEnrollment"');
        expect(login).toContain("pinForm.post('/desktop/pin/login'");
        expect(login).toContain('name="device_token"');
        expect(login).toContain('name="pin"');
        expect(login).toContain('pinEnrollment.userName');
        expect(login).toContain('pinEnrollment.deviceName');
        expect(login).toContain('Aucun e-mail ni mot de passe');
        expect(login).toContain('<template v-else-if="runtimeResolved">');
    });

    it('retains enrollment on logout and clears it only for another account', () => {
        const login = read('pages/auth/Login.vue');
        const appLayout = read('layouts/AppLayout.vue');
        const pending = read('pages/auth/PendingActivation.vue');

        expect(login).toContain('clearDesktopPinEnrollment()');
        expect(login).toContain('Utiliser un autre compte');
        expect(login).toContain('data-test="desktop-pin-use-another-account"');
        expect(appLayout).not.toContain('clearDesktopPinEnrollment');
        expect(pending).not.toContain('clearDesktopPinEnrollment');
    });
});
