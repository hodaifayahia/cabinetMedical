import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const source = readFileSync(
    resolve(
        process.cwd(),
        'resources/js/pages/configuration/RolesPermissions.vue',
    ),
    'utf8',
);

describe('cabinet role and permission editor contract', () => {
    it('renders permissions as rows and canonical roles as columns', () => {
        expect(source).toContain('v-for="group in permissionGroups"');
        expect(source).toContain('v-for="role in roles"');
        expect(source).toContain('togglePermission(');
        expect(source).toContain('Enregistrer les permissions');
    });

    it('persists permission changes and same-page user role assignments', () => {
        expect(source).toContain(
            "form.put('/app/configuration/roles-permissions'",
        );
        expect(source).toContain(
            '`/app/configuration/roles-permissions/users/${user.id}`',
        );
        expect(source).toContain('Rôle de chaque utilisateur');
    });

    it('protects the super administrator and removes the read-only presentation', () => {
        expect(source).toContain('role.locked || form.processing');
        expect(source).toContain('Le rôle Super administrateur est protégé');
        expect(source).not.toContain('Configuration en lecture seule');
    });
});
