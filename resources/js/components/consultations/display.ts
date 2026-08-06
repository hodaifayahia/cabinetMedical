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
