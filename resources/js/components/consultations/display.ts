const appointmentStatusLabels: Readonly<Record<string, string>> = {
    cancelled: 'Annulé',
    checked_in: 'Arrivé',
    completed: 'Terminé',
    confirmed: 'Confirmé',
    in_progress: 'En cours',
    no_show: 'Absent',
    scheduled: 'Planifié',
};

const normalizeTechnicalValue = (value: string): string =>
    value
        .trim()
        .toLocaleLowerCase('en')
        .replace(/[\s-]+/gu, '_');

export const appointmentStatusLabel = (status: string): string =>
    appointmentStatusLabels[normalizeTechnicalValue(status)] ?? status;

const familyRelationLabels: Readonly<Record<string, string>> = {
    father: 'père',
    mother: 'mère',
    parent: 'parent',
    husband: 'époux',
    wife: 'épouse',
    spouse: 'conjoint(e)',
    son: 'fils',
    daughter: 'fille',
    child: 'enfant',
    brother: 'frère',
    sister: 'sœur',
    sibling: 'frère / sœur',
    grandfather: 'grand-père',
    grandmother: 'grand-mère',
    grandparent: 'grand-parent',
    grandson: 'petit-fils',
    granddaughter: 'petite-fille',
    grandchild: 'petit-enfant',
    uncle: 'oncle',
    aunt: 'tante',
    uncle_aunt: 'oncle / tante',
    nephew: 'neveu',
    niece: 'nièce',
    nephew_niece: 'neveu / nièce',
    cousin: 'cousin(e)',
    other: 'proche',
};

export const familyRelationLabel = (relation: string | null): string =>
    relation ? (familyRelationLabels[relation] ?? relation) : 'proche';
