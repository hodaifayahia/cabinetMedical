export type ConfigLink = {
    title: string;
    href: string;
    permissions: string[];
};
export type ConfigGroup = { label: string; links: ConfigLink[] };

const sensitiveConfigurationPermissions = [
    'configuration.connectivity.manage',
    'configuration.backups.manage',
    'configuration.restore.manage',
    'configuration.drive.manage',
    'configuration.licensing.manage',
    'configuration.diagnostics.view',
];

// Single source of truth for the Configuration sub-navigation, shared by the
// navbar fly-out and the on-page configuration tabs.
export const configurationNav: ConfigGroup[] = [
    {
        label: 'Cabinet',
        links: [
            {
                title: 'Cabinet & documents',
                href: '/app/configuration/identity',
                permissions: ['configuration.branding.manage'],
            },
            {
                title: 'Connexion & sauvegardes',
                href: '/app/configuration/connectivity-backup',
                permissions: sensitiveConfigurationPermissions,
            },
        ],
    },
    {
        label: 'Catalogues',
        links: [
            {
                title: 'Médicaments',
                href: '/app/configuration/medications',
                permissions: ['configuration.manage'],
            },
            {
                title: 'Catégories de bilans',
                href: '/app/configuration/ref/bilan-types',
                permissions: ['configuration.manage'],
            },
            {
                title: 'Examens',
                href: '/app/configuration/ref/exams',
                permissions: ['configuration.manage'],
            },
        ],
    },
    {
        label: 'Finance',
        links: [
            {
                title: 'Tarifs de consultation',
                href: '/app/configuration/ref/consultation-fees',
                permissions: ['configuration.manage'],
            },
            {
                title: 'Catégories & actes',
                href: '/app/configuration/ref/acts',
                permissions: ['configuration.manage'],
            },
            {
                title: 'Moyens de paiement',
                href: '/app/configuration/ref/payment-methods',
                permissions: ['configuration.manage'],
            },
            {
                title: 'Paramètres comptables',
                href: '/app/configuration/accounting',
                permissions: ['configuration.manage'],
            },
        ],
    },
    {
        label: 'Agenda',
        links: [
            {
                title: 'Disponibilités des rendez-vous',
                href: '/app/appointments/configure',
                permissions: ['appointments.configure'],
            },
        ],
    },
];

export const configurationNavForPermissions = (
    grantedPermissions: readonly string[],
): ConfigGroup[] => {
    const granted = new Set(grantedPermissions);

    return configurationNav
        .map((group) => ({
            ...group,
            links: group.links.filter((link) =>
                link.permissions.some((permission) => granted.has(permission)),
            ),
        }))
        .filter((group) => group.links.length > 0);
};
