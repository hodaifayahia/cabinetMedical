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
