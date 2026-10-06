const methodLabels: Readonly<Record<string, string>> = {
    bank_card: 'Carte bancaire',
    bank_transfer: 'Virement bancaire',
    card: 'Carte',
    cash: 'Espèces',
    cash_payment: 'Espèces',
    check: 'Chèque',
    cheque: 'Chèque',
    credit_card: 'Carte bancaire',
    transfer: 'Virement',
    wire_transfer: 'Virement bancaire',
};

const statusLabels: Readonly<Record<string, string>> = {
    all: 'Tous les paiements',
    paid: 'Payés',
    unpaid: 'Impayés',
};

const normalizeTechnicalValue = (value: string): string =>
    value
        .trim()
        .toLocaleLowerCase('en')
        .replace(/[\s-]+/gu, '_');

export const paymentMethodLabel = (method?: string | null): string => {
    if (!method?.trim()) {
        return 'Non renseigné';
    }

    return methodLabels[normalizeTechnicalValue(method)] ?? method;
};

export const paymentStatusLabel = (status: string): string =>
    statusLabels[normalizeTechnicalValue(status)] ?? status;

export const paymentPaginationLabel = (label: string): string =>
    label.replace(/Previous/giu, 'Précédent').replace(/Next/giu, 'Suivant');

const paymentDateFormatter = new Intl.DateTimeFormat('fr-DZ', {
    dateStyle: 'short',
    hour12: false,
    timeStyle: 'short',
    timeZone: 'Africa/Algiers',
});

export const paymentDateLabel = (
    date?: string | null,
    fallback?: string | null,
): string => {
    if (!date) {
        return fallback || '—';
    }

    const parsed = new Date(date);

    return Number.isNaN(parsed.getTime())
        ? fallback || '—'
        : paymentDateFormatter.format(parsed);
};

export const createFrDzMoneyFormatter = (
    currency: string,
): ((value: number) => string) => {
    const configuredCurrency = currency.trim();
    const currencyCode =
        configuredCurrency.toLocaleUpperCase('en') === 'DA'
            ? 'DZD'
            : configuredCurrency.toLocaleUpperCase('en');

    if (/^[A-Z]{3}$/u.test(currencyCode)) {
        try {
            const formatter = new Intl.NumberFormat('fr-DZ', {
                currency: currencyCode,
                maximumFractionDigits: 2,
                minimumFractionDigits: 0,
                style: 'currency',
            });

            return (value: number): string => formatter.format(value);
        } catch {
            // Fall through for a configured label that is not an ISO code.
        }
    }

    const formatter = new Intl.NumberFormat('fr-DZ', {
        maximumFractionDigits: 2,
        minimumFractionDigits: 0,
    });

    return (value: number): string =>
        [formatter.format(value), configuredCurrency].filter(Boolean).join(' ');
};

const compactFormatter = new Intl.NumberFormat('fr-DZ', {
    maximumFractionDigits: 1,
    notation: 'compact',
});

/** Short axis/centre label such as « 1,2 k » or « 3,4 M ». */
export const compactAmount = (value: number): string =>
    compactFormatter.format(value);

// Colour follows the payment method itself (never its rank), so « Espèces »
// keeps the same colour from one period to the next. Unknown methods share
// the last slot.
const methodSlots: Readonly<Record<string, string>> = {
    Espèces: 'var(--viz-cat-1)',
    Carte: 'var(--viz-cat-2)',
    'Carte bancaire': 'var(--viz-cat-2)',
    Chèque: 'var(--viz-cat-3)',
    Virement: 'var(--viz-cat-4)',
    'Virement bancaire': 'var(--viz-cat-4)',
    Assurance: 'var(--viz-cat-5)',
};

export const methodColor = (method?: string | null): string =>
    methodSlots[paymentMethodLabel(method)] ?? 'var(--viz-cat-6)';

const pad2 = (value: number): string => String(value).padStart(2, '0');

/**
 * « YYYY-MM-DD » of a date in the browser's own time zone. Unlike
 * toISOString() (UTC), midnight in Algiers (UTC+1) stays on the same day.
 */
export const localIsoDate = (date: Date = new Date()): string =>
    `${date.getFullYear()}-${pad2(date.getMonth() + 1)}-${pad2(date.getDate())}`;

/** First day of the date's month, in local time (« YYYY-MM-01 »). */
export const monthStartIsoDate = (date: Date = new Date()): string =>
    localIsoDate(new Date(date.getFullYear(), date.getMonth(), 1));

const toCents = (value: number | string | null | undefined): number => {
    const amount = Number(value ?? 0);

    return Number.isFinite(amount) ? Math.round(amount * 100) : 0;
};

/**
 * Most that can be collected now: the (possibly edited) total price minus
 * what is already paid, in whole cents so 1500,3 − 1000,1 gives 500,2 and
 * not 500,19999… (which would make the browser reject a valid 500,2).
 */
export const maxCollectable = (
    total: number | string | null | undefined,
    alreadyPaid: number | string | null | undefined,
): number => Math.max(0, toCents(total) - toCents(alreadyPaid)) / 100;

/** What will still be owed once this payment is recorded. */
export const projectedOutstanding = (
    total: number | string | null | undefined,
    alreadyPaid: number | string | null | undefined,
    paidNow: number | string | null | undefined,
): number =>
    Math.max(0, toCents(total) - toCents(alreadyPaid) - toCents(paidNow)) / 100;
