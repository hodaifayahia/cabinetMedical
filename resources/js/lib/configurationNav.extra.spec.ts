import { describe, expect, it } from 'vitest';
import {
    configurationNav,
    configurationNavForPermissions,
} from './configurationNav';

const allPermissions = [
    ...new Set(
        configurationNav.flatMap((group) =>
            group.links.flatMap((link) => link.permissions),
        ),
    ),
];

describe('configuration navigation (extended)', () => {
    it('keeps the groups in their display order', () => {
        expect(configurationNav.map((group) => group.label)).toEqual([
            'Cabinet',
            'Catalogues',
            'Finance',
            'Agenda',
        ]);
    });

    it('points every link inside the authenticated application', () => {
        for (const link of configurationNav.flatMap((group) => group.links)) {
            expect(link.href.startsWith('/app/')).toBe(true);
            expect(link.title.trim()).not.toBe('');
        }
    });

    it('never repeats a destination', () => {
        const hrefs = configurationNav.flatMap((group) =>
            group.links.map((link) => link.href),
        );

        expect(new Set(hrefs).size).toBe(hrefs.length);
    });

    it('guards every link with a permission or a server capability', () => {
        for (const link of configurationNav.flatMap((group) => group.links)) {
            expect(
                link.permissions.length > 0 || link.capability !== undefined,
            ).toBe(true);
        }
    });

    it('shows every permission-guarded link to a fully privileged user', () => {
        const links = configurationNavForPermissions(allPermissions).flatMap(
            (group) => group.links,
        );

        expect(links.map((link) => link.href)).not.toContain(
            '/app/configuration/online-service',
        );
        expect(links).toHaveLength(
            configurationNav.flatMap((group) => group.links).length - 1,
        );
    });

    it('shows everything when both server capabilities are also granted', () => {
        const groups = configurationNavForPermissions(
            allPermissions,
            true,
            true,
        );

        expect(groups).toEqual(configurationNav);
    });

    it('gives the catalogue permission both catalogue and finance groups', () => {
        const groups = configurationNavForPermissions(['configuration.manage']);

        expect(groups.map((group) => group.label)).toEqual([
            'Catalogues',
            'Finance',
        ]);
        expect(groups.flatMap((group) => group.links)).toHaveLength(7);
    });

    it('drops a group entirely when none of its links are visible', () => {
        const groups = configurationNavForPermissions([
            'appointments.configure',
        ]);

        expect(groups).toHaveLength(1);
        expect(groups[0]?.label).toBe('Agenda');
    });

    it('ignores unknown permissions', () => {
        expect(
            configurationNavForPermissions(['patients.view', 'admin', '*']),
        ).toEqual([]);
    });

    it('does not mutate the shared navigation definition', () => {
        const before = JSON.stringify(configurationNav);

        configurationNavForPermissions(['configuration.manage']);
        configurationNavForPermissions([]);

        expect(JSON.stringify(configurationNav)).toBe(before);
    });

    it('a capability flag never reveals links that require permissions', () => {
        const hrefs = configurationNavForPermissions([], true, true).flatMap(
            (group) => group.links.map((link) => link.href),
        );

        expect(hrefs).toEqual([
            '/app/configuration/online-service',
            '/app/configuration/roles-permissions',
        ]);
    });
});
