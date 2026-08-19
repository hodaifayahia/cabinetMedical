import { describe, expect, it } from 'vitest';
import { configurationNavForPermissions } from './configurationNav';

const visibleLinks = (permissions: string[], manageRolePermissions = false) =>
    configurationNavForPermissions(permissions, manageRolePermissions)
        .flatMap((group) => group.links)
        .map((link) => link.href);

describe('configurationNavForPermissions', () => {
    it('shows no configuration links without an explicit permission', () => {
        expect(visibleLinks([])).toEqual([]);
    });

    it('shows only clinic identity for a branding operator', () => {
        expect(visibleLinks(['configuration.branding.manage'])).toEqual([
            '/app/configuration/identity',
        ]);
    });

    it.each([
        'configuration.connectivity.manage',
        'configuration.backups.manage',
        'configuration.restore.manage',
        'configuration.drive.manage',
        'configuration.licensing.manage',
        'configuration.diagnostics.view',
    ])('routes %s to connectivity and licence status', (permission) => {
        expect(visibleLinks([permission])).toEqual([
            '/app/configuration/connectivity-backup',
            '/app/configuration/connectivity-backup#license',
        ]);
    });

    it('does not expose clinic or catalog links through the old broad settings permission', () => {
        expect(visibleLinks(['settings.manage'])).toEqual([]);
    });

    it('shows the editable role matrix to staff managers', () => {
        expect(visibleLinks(['staff.manage'])).toEqual([
            '/app/configuration/roles-permissions',
        ]);
    });

    it('shows the editable role matrix through the explicit doctor/owner capability', () => {
        expect(visibleLinks([], true)).toEqual([
            '/app/configuration/roles-permissions',
        ]);
    });

    it('keeps appointment configuration independent from clinic settings', () => {
        expect(visibleLinks(['appointments.configure'])).toEqual([
            '/app/appointments/configure',
        ]);
    });
});
