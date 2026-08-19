const roleLabels: Readonly<Record<string, string>> = {
    doctor: 'Médecin (Super administrateur)',
    assistant: 'Assistant',
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
