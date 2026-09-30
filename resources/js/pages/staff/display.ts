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

/** Mirrors App\Services\Cabinet\CabinetSeatService::summary(). */
export type StaffSeats = {
    used: number;
    limit: number;
    remaining: number;
    canCheckOnline: boolean;
    syncedAt: string | null;
};

export const staffSeatsRemainingLabel = (remaining: number): string => {
    if (remaining <= 0) {
        return 'Aucun siège disponible';
    }

    return remaining === 1
        ? '1 siège disponible'
        : `${remaining} sièges disponibles`;
};
