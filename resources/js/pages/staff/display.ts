const roleLabels: Readonly<Record<string, string>> = {
    administrator: 'Administrateur',
    cashier: 'Caissier',
    doctor: 'Médecin',
    pharmacist: 'Pharmacien',
    receptionist: 'Réceptionniste',
    stock_manager: 'Gestionnaire de stock',
    super_administrator: 'Super-administrateur',
};

const normalizeTechnicalValue = (value: string): string =>
    value
        .trim()
        .toLocaleLowerCase('en')
        .replace(/[\s-]+/gu, '_');

export const staffRoleLabel = (role: string): string =>
    roleLabels[normalizeTechnicalValue(role)] ?? role;

export const staffPaginationLabel = (label: string): string =>
    label.replace(/Previous/giu, 'Précédent').replace(/Next/giu, 'Suivant');
