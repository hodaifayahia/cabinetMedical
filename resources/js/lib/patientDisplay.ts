// Shared formatting for the patient screens: age, dates, initials and a
// stable avatar colour per patient.

const genderLabels: Record<string, string> = {
    female: 'Femme',
    femme: 'Femme',
    male: 'Homme',
    homme: 'Homme',
};

export const formatGender = (value: string | null | undefined): string =>
    value ? (genderLabels[value.toLocaleLowerCase('fr-DZ')] ?? value) : '—';

const parseDay = (value: string): Date =>
    new Date(
        value.length <= 10 ? `${value}T00:00:00` : value.replace(' ', 'T'),
    );

export const formatDate = (value: string | null | undefined): string =>
    value
        ? new Intl.DateTimeFormat('fr-DZ').format(parseDay(value.slice(0, 10)))
        : '—';

export const formatDateTime = (value: string | null | undefined): string =>
    value
        ? new Intl.DateTimeFormat('fr-DZ', {
              weekday: 'short',
              day: '2-digit',
              month: 'short',
              hour: '2-digit',
              minute: '2-digit',
          }).format(parseDay(value))
        : '—';

/** Age in whole years, or null when the date of birth is unknown. */
export const ageInYears = (
    dateOfBirth: string | null | undefined,
    now: Date = new Date(),
): number | null => {
    if (!dateOfBirth) {
        return null;
    }

    const birth = parseDay(dateOfBirth.slice(0, 10));
    let years = now.getFullYear() - birth.getFullYear();
    const monthDelta = now.getMonth() - birth.getMonth();

    if (
        monthDelta < 0 ||
        (monthDelta === 0 && now.getDate() < birth.getDate())
    ) {
        years -= 1;
    }

    return years >= 0 ? years : null;
};

export const formatAge = (dateOfBirth: string | null | undefined): string => {
    const years = ageInYears(dateOfBirth);

    if (years === null) {
        return '—';
    }

    return `${years} an${years > 1 ? 's' : ''}`;
};

/** "aujourd’hui", "il y a 3 j", "dans 2 sem." — for dates close to now. */
export const relativeDay = (
    value: string | null | undefined,
    now: Date = new Date(),
): string => {
    if (!value) {
        return '—';
    }

    const day = parseDay(value);
    const startOf = (date: Date) =>
        new Date(date.getFullYear(), date.getMonth(), date.getDate()).getTime();
    const days = Math.round((startOf(day) - startOf(now)) / 86_400_000);

    if (days === 0) {
        return 'aujourd’hui';
    }

    if (days === 1) {
        return 'demain';
    }

    if (days === -1) {
        return 'hier';
    }

    const abs = Math.abs(days);
    const span =
        abs < 14
            ? `${abs} j`
            : abs < 60
              ? `${Math.round(abs / 7)} sem.`
              : abs < 730
                ? `${Math.round(abs / 30)} mois`
                : `${Math.round(abs / 365)} ans`;

    return days < 0 ? `il y a ${span}` : `dans ${span}`;
};

export const initials = (fullName: string): string =>
    fullName
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toLocaleUpperCase('fr-DZ') ?? '')
        .join('') || '?';

const avatarTones = [
    'bg-teal-100 text-teal-800 dark:bg-teal-900/50 dark:text-teal-200',
    'bg-sky-100 text-sky-800 dark:bg-sky-900/50 dark:text-sky-200',
    'bg-violet-100 text-violet-800 dark:bg-violet-900/50 dark:text-violet-200',
    'bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-200',
    'bg-rose-100 text-rose-800 dark:bg-rose-900/50 dark:text-rose-200',
    'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-200',
];

/** The same patient always gets the same colour. */
export const avatarTone = (seed: number | string): string => {
    const text = String(seed);
    let hash = 0;

    for (let i = 0; i < text.length; i++) {
        hash = (hash * 31 + text.charCodeAt(i)) >>> 0;
    }

    return avatarTones[hash % avatarTones.length];
};
