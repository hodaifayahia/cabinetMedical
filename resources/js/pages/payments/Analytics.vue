<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowLeft,
    ArrowRight,
    Banknote,
    CalendarRange,
    ChevronLeft,
    ChevronRight,
    CircleDollarSign,
    Download,
    Gauge,
    Percent,
    Printer,
    ReceiptText,
    Stethoscope,
    TrendingDown,
    TrendingUp,
    Undo2,
    UserRound,
    Wallet,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import BarChart from '@/components/charts/BarChart.vue';
import ComparisonBarChart from '@/components/charts/ComparisonBarChart.vue';
import DonutChart from '@/components/charts/DonutChart.vue';
import HBarChart from '@/components/charts/HBarChart.vue';
import PaymentsTabs from '@/components/payments/PaymentsTabs.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    compactAmount,
    createFrDzMoneyFormatter,
    methodColor,
    paymentMethodLabel,
} from '@/pages/payments/display';

type Kpis = {
    collected: number;
    gross_collected: number;
    refunds: number;
    billed: number;
    discounts: number;
    outstanding: number;
    collection_rate: number | null;
    consultations: number;
    average_ticket: number;
    patients: number;
    transactions: number;
};

type TimelineRow = {
    key: string;
    label: string;
    month?: number;
    day?: number;
    weekday?: string;
    collected: number;
    refunds: number;
    billed: number;
    discounts?: number;
    consultations: number;
    previous_collected?: number;
    future: boolean;
};

type Breakdown = { label: string; value: number; count: number };

const props = defineProps<{
    currency: string;
    years: number[];
    report: {
        period: {
            year: number;
            month: number | null;
            label: string;
            from: string;
            to: string;
            is_current: boolean;
            elapsed_days: number;
            total_days: number;
        };
        comparison: {
            label: string;
            partial: boolean;
            from: string;
            to: string;
        };
        kpis: Kpis;
        previous: Kpis;
        changes: {
            collected: number | null;
            billed: number | null;
            consultations: number | null;
            average_ticket: number | null;
        };
        projection: number | null;
        timeline: TimelineRow[];
        weekdays: Breakdown[];
        methods: (Breakdown & { share: number })[];
        services: (Breakdown & { collected: number })[];
        practitioners: Breakdown[];
        topPatients: {
            patient_id: number;
            patient_name: string;
            patient_number: string | null;
            value: number;
            count: number;
        }[];
    };
    profit: {
        expenses: number;
        previous_expenses: number;
        net: number;
        previous_net: number;
        margin: number | null;
        changes: { expenses: number | null; net: number | null };
        categories: {
            category: string;
            label: string;
            value: number;
            share: number;
        }[];
        timeline: Record<string, { expenses: number; net: number }>;
    } | null;
    receivables: {
        total: number;
        count: number;
        patients: number;
        buckets: {
            key: string;
            label: string;
            amount: number;
            count: number;
        }[];
        debtors: {
            patient_id: number;
            patient_name: string;
            patient_number: string | null;
            phone: string | null;
            amount: number;
            count: number;
            oldest: string;
            age_days: number;
        }[];
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Paiements', href: '/app/payments' },
            { title: 'Analyse financière', href: '/app/payments/analytics' },
        ],
    },
});

const MONTHS = [
    'Janvier',
    'Février',
    'Mars',
    'Avril',
    'Mai',
    'Juin',
    'Juillet',
    'Août',
    'Septembre',
    'Octobre',
    'Novembre',
    'Décembre',
];

const formatMoney = createFrDzMoneyFormatter(props.currency);
const formatAxis = (value: number): string => compactAmount(value);
const formatNumber = (value: number): string =>
    new Intl.NumberFormat('fr-DZ').format(value);

const period = computed(() => props.report.period);
const isMonthView = computed(() => period.value.month !== null);
const selectedYear = ref(period.value.year);
const selectedMonth = ref<number | ''>(period.value.month ?? '');

const visit = (year: number, month: number | null) => {
    router.get(
        '/app/payments/analytics',
        month === null ? { year } : { year, month },
        { preserveScroll: true },
    );
};

const applyPeriod = () =>
    visit(
        Number(selectedYear.value),
        selectedMonth.value === '' ? null : Number(selectedMonth.value),
    );

const step = (direction: -1 | 1) => {
    if (!isMonthView.value) {
        visit(period.value.year + direction, null);

        return;
    }

    const date = new Date(
        period.value.year,
        (period.value.month ?? 1) - 1 + direction,
        1,
    );
    visit(date.getFullYear(), date.getMonth() + 1);
};

const canStepForward = computed(() => {
    const now = new Date();

    return isMonthView.value
        ? period.value.year < now.getFullYear() ||
              (period.value.year === now.getFullYear() &&
                  (period.value.month ?? 12) < now.getMonth() + 1)
        : period.value.year < now.getFullYear();
});

const exportUrl = computed(
    () =>
        `/app/payments/export?from=${period.value.from}&to=${period.value.to}`,
);

const profitRow = (key: string): { expenses: number; net: number } => {
    const row = props.profit?.timeline[key];
    const collected =
        props.report.timeline.find((item) => item.key === key)?.collected ?? 0;

    return row ?? { expenses: 0, net: collected };
};

const kpis = computed(() => props.report.kpis);
const changes = computed(() => props.report.changes);

const tiles = computed(() => [
    {
        key: 'collected',
        label: 'Encaissé',
        hint: 'Argent réellement reçu sur la période, remboursements déduits',
        value: formatMoney(kpis.value.collected),
        change: changes.value.collected,
        previous: formatMoney(props.report.previous.collected),
        icon: Wallet,
        accent: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
    },
    {
        key: 'billed',
        label: 'Facturé',
        hint: 'Total des prestations des consultations de la période',
        value: formatMoney(kpis.value.billed),
        change: changes.value.billed,
        previous: formatMoney(props.report.previous.billed),
        icon: ReceiptText,
        accent: 'bg-brand-soft text-brand',
    },
    {
        key: 'outstanding',
        label: 'Reste à encaisser',
        hint: 'Part encore due sur les consultations de la période',
        value: formatMoney(kpis.value.outstanding),
        change: null,
        previous: null,
        icon: CircleDollarSign,
        accent: 'bg-amber-500/10 text-amber-700 dark:text-amber-400',
    },
    {
        key: 'rate',
        label: 'Taux de recouvrement',
        hint: 'Part des montants facturés (hors remises) déjà payée',
        value:
            kpis.value.collection_rate === null
                ? '—'
                : `${kpis.value.collection_rate.toLocaleString('fr-DZ')} %`,
        change: null,
        previous:
            props.report.previous.collection_rate === null
                ? null
                : `${props.report.previous.collection_rate.toLocaleString('fr-DZ')} %`,
        icon: Gauge,
        accent: 'bg-sky-500/10 text-sky-700 dark:text-sky-400',
    },
    {
        key: 'consultations',
        label: 'Consultations facturées',
        hint: `${formatNumber(kpis.value.patients)} patient(s) différent(s)`,
        value: formatNumber(kpis.value.consultations),
        change: changes.value.consultations,
        previous: formatNumber(props.report.previous.consultations),
        icon: Stethoscope,
        accent: 'bg-brand-soft text-brand',
    },
    {
        key: 'ticket',
        label: 'Panier moyen',
        hint: 'Montant moyen facturé par consultation',
        value: formatMoney(kpis.value.average_ticket),
        change: changes.value.average_ticket,
        previous: formatMoney(props.report.previous.average_ticket),
        icon: Banknote,
        accent: 'bg-brand-soft text-brand',
    },
    {
        key: 'discounts',
        label: 'Remises accordées',
        hint: 'Reliquats acceptés et soldés avec un motif',
        value: formatMoney(kpis.value.discounts),
        change: null,
        previous: formatMoney(props.report.previous.discounts),
        icon: Percent,
        accent: 'bg-slate-500/10 text-slate-700 dark:text-slate-300',
    },
    {
        key: 'refunds',
        label: 'Remboursements',
        hint: 'Sommes rendues aux patients sur la période',
        value: formatMoney(kpis.value.refunds),
        change: null,
        previous: formatMoney(props.report.previous.refunds),
        icon: Undo2,
        accent: 'bg-rose-500/10 text-rose-700 dark:text-rose-400',
    },
]);

const chartData = computed(() =>
    props.report.timeline.map((row) => ({
        key: row.key,
        label: row.label,
        current: row.collected,
        previous: isMonthView.value
            ? row.billed
            : (row.previous_collected ?? 0),
        future: row.future,
        detail: isMonthView.value
            ? `${row.weekday} ${row.day} ${MONTHS[(period.value.month ?? 1) - 1].toLowerCase()}`
            : `${MONTHS[(row.month ?? 1) - 1]} ${period.value.year}`,
    })),
);

const selectMonth = (key: string) => {
    if (isMonthView.value) {
        return;
    }

    const [year, month] = key.split('-').map(Number);
    visit(year, month);
};

const bestBucket = computed(() => {
    const rows = props.report.timeline.filter((row) => !row.future);

    return rows.reduce<TimelineRow | null>(
        (best, row) =>
            best === null || row.collected > best.collected ? row : best,
        null,
    );
});

const methodSlices = computed(() =>
    props.report.methods
        .filter((method) => method.value > 0)
        .map((method) => ({
            label: paymentMethodLabel(method.label),
            value: method.value,
            color: methodColor(method.label),
        })),
);

const agingTones: Record<string, string> = {
    current: 'bg-emerald-500',
    d60: 'bg-amber-400',
    d90: 'bg-orange-500',
    older: 'bg-rose-600',
};

const agingShare = (amount: number): number =>
    props.receivables.total > 0 ? (amount / props.receivables.total) * 100 : 0;

const formatDate = (date: string): string =>
    new Intl.DateTimeFormat('fr-DZ', { dateStyle: 'medium' }).format(
        new Date(`${date}T00:00:00`),
    );

const printUrl = computed(() => {
    const params = new URLSearchParams({ year: String(period.value.year) });

    if (period.value.month !== null) {
        params.set('month', String(period.value.month));
    }

    return `/app/payments/analytics/print?${params.toString()}`;
});

// Opens the journal on this patient's open debts, all periods, ready to
// record the payment.
const collectUrl = (debtor: {
    patient_number: string | null;
    patient_name: string;
}): string => {
    const params = new URLSearchParams({
        status: 'debt',
        search: debtor.patient_number ?? debtor.patient_name,
    });

    return `/app/payments?${params.toString()}`;
};
</script>

<template>
    <Head :title="`Analyse financière · ${period.label}`" />

    <div class="med-page">
        <header class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1
                    class="text-[2rem] leading-none font-bold tracking-tight text-[#111827] sm:text-[2.2rem] dark:text-slate-50"
                >
                    Analyse financière
                </h1>
                <div class="mt-3 h-1 w-20 rounded-full bg-brand" />
                <p class="mt-3 text-sm text-muted-foreground">
                    Ce que le cabinet a facturé et encaissé, par année et par
                    mois, avec les dettes en cours.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2 print:hidden">
                <Button variant="outline" as-child>
                    <a :href="exportUrl">
                        <Download class="size-4" />
                        Exporter (CSV)
                    </a>
                </Button>
                <Button variant="outline" as-child>
                    <a :href="printUrl" target="_blank" rel="noopener">
                        <Printer class="size-4" />
                        Rapport imprimable
                    </a>
                </Button>
            </div>
        </header>

        <PaymentsTabs active="analytics" class="print:hidden" />

        <!-- Period picker -->
        <section
            class="med-panel flex flex-wrap items-center justify-between gap-3 p-3 print:hidden"
        >
            <div class="flex items-center gap-1">
                <Button
                    variant="ghost"
                    size="icon"
                    aria-label="Période précédente"
                    title="Période précédente"
                    @click="step(-1)"
                >
                    <ChevronLeft class="size-4" />
                </Button>
                <div class="flex items-center gap-2 px-2">
                    <CalendarRange class="size-4 text-brand" />
                    <span class="text-base font-bold">{{ period.label }}</span>
                    <span
                        v-if="period.is_current"
                        class="rounded-full bg-emerald-500/10 px-2 py-0.5 text-[11px] font-semibold text-emerald-700 dark:text-emerald-400"
                    >
                        En cours · jour {{ period.elapsed_days }}/{{
                            period.total_days
                        }}
                    </span>
                </div>
                <Button
                    variant="ghost"
                    size="icon"
                    aria-label="Période suivante"
                    title="Période suivante"
                    :disabled="!canStepForward"
                    @click="step(1)"
                >
                    <ChevronRight class="size-4" />
                </Button>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <label class="sr-only" for="analytics-year">Année</label>
                <select
                    id="analytics-year"
                    v-model="selectedYear"
                    class="h-10 rounded-xl border border-input bg-background px-3 text-sm shadow-sm"
                    @change="applyPeriod"
                >
                    <option v-for="year in years" :key="year" :value="year">
                        {{ year }}
                    </option>
                </select>
                <label class="sr-only" for="analytics-month">Mois</label>
                <select
                    id="analytics-month"
                    v-model="selectedMonth"
                    class="h-10 rounded-xl border border-input bg-background px-3 text-sm shadow-sm"
                    @change="applyPeriod"
                >
                    <option value="">Toute l’année</option>
                    <option
                        v-for="(name, index) in MONTHS"
                        :key="name"
                        :value="index + 1"
                    >
                        {{ name }}
                    </option>
                </select>
                <Button
                    v-if="isMonthView"
                    variant="secondary"
                    @click="visit(period.year, null)"
                >
                    <ArrowLeft class="size-4" />
                    Vue annuelle
                </Button>
            </div>
        </section>

        <p class="-mt-2 text-xs text-muted-foreground">
            Comparaison avec {{ report.comparison.label }}
            <template v-if="report.comparison.partial">
                sur la même durée écoulée (jusqu’au
                {{ formatDate(report.comparison.to) }})
            </template>
            .
        </p>

        <!-- Hero numbers -->
        <section class="grid gap-4 lg:grid-cols-[1.4fr_1fr]">
            <div
                class="relative overflow-hidden rounded-2xl bg-brand-deep p-6 text-white shadow-lg shadow-brand-deep/10"
            >
                <div
                    class="absolute -top-16 -right-10 size-56 rounded-full bg-white/10 blur-2xl"
                />
                <div class="relative">
                    <p
                        class="text-xs font-semibold tracking-wider text-white/75 uppercase"
                    >
                        Encaissé · {{ period.label }}
                    </p>
                    <p
                        class="mt-2 text-4xl font-bold tracking-tight tabular-nums sm:text-5xl"
                    >
                        {{ formatMoney(kpis.collected) }}
                    </p>
                    <p
                        class="mt-3 flex flex-wrap items-center gap-2 text-sm text-white/75"
                    >
                        <span
                            v-if="changes.collected !== null"
                            class="inline-flex items-center gap-1 rounded-full bg-white/15 px-2 py-0.5 font-semibold text-white"
                        >
                            <component
                                :is="
                                    changes.collected >= 0
                                        ? TrendingUp
                                        : TrendingDown
                                "
                                class="size-3.5"
                            />
                            {{ changes.collected >= 0 ? '+' : ''
                            }}{{ changes.collected.toLocaleString('fr-DZ') }} %
                        </span>
                        <span>
                            contre
                            {{ formatMoney(report.previous.collected) }} ({{
                                report.comparison.label
                            }})
                        </span>
                    </p>
                    <div
                        class="mt-5 grid gap-3 border-t border-white/15 pt-4 sm:grid-cols-3"
                    >
                        <div>
                            <p class="text-xs text-white/75">Versements</p>
                            <p class="text-lg font-bold tabular-nums">
                                {{ formatNumber(kpis.transactions) }}
                            </p>
                        </div>
                        <div v-if="report.projection !== null">
                            <p class="text-xs text-white/75">
                                Projection fin de
                                {{ isMonthView ? 'mois' : 'année' }}
                            </p>
                            <p class="text-lg font-bold tabular-nums">
                                ≈ {{ formatMoney(report.projection) }}
                            </p>
                        </div>
                        <div v-if="bestBucket && bestBucket.collected > 0">
                            <p class="text-xs text-white/75">
                                Meilleur {{ isMonthView ? 'jour' : 'mois' }}
                            </p>
                            <p class="text-lg font-bold">
                                {{
                                    isMonthView
                                        ? `${bestBucket.weekday} ${bestBucket.day}`
                                        : MONTHS[(bestBucket.month ?? 1) - 1]
                                }}
                                <span
                                    class="text-sm font-medium text-white/75 tabular-nums"
                                >
                                    · {{ formatMoney(bestBucket.collected) }}
                                </span>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>De la facturation à l’encaissement</CardTitle>
                    <CardDescription>
                        Consultations de la période
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-3">
                    <div
                        v-for="row in [
                            {
                                label: 'Facturé',
                                value: kpis.billed,
                                tone: 'bg-brand',
                            },
                            {
                                label: 'Remises',
                                value: kpis.discounts,
                                tone: 'bg-slate-400',
                            },
                            {
                                label: 'Reste dû',
                                value: kpis.outstanding,
                                tone: 'bg-amber-500',
                            },
                        ]"
                        :key="row.label"
                    >
                        <div class="flex justify-between text-sm">
                            <span class="text-muted-foreground">{{
                                row.label
                            }}</span>
                            <span class="font-semibold tabular-nums">{{
                                formatMoney(row.value)
                            }}</span>
                        </div>
                        <div class="mt-1 h-2 rounded-full bg-muted">
                            <div
                                class="h-2 rounded-full"
                                :class="row.tone"
                                :style="{
                                    width: `${kpis.billed > 0 ? Math.min(100, (row.value / kpis.billed) * 100) : 0}%`,
                                }"
                            />
                        </div>
                    </div>
                    <p class="pt-1 text-xs text-muted-foreground">
                        « Encaissé » compte l’argent reçu pendant la période,
                        même pour des consultations plus anciennes. « Facturé »
                        compte les consultations de la période.
                    </p>
                </CardContent>
            </Card>
        </section>

        <!-- KPI tiles -->
        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <article
                v-for="tile in tiles"
                :key="tile.key"
                class="med-panel p-5"
                :title="tile.hint"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p
                            class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                        >
                            {{ tile.label }}
                        </p>
                        <p class="mt-2 text-2xl font-bold tabular-nums">
                            {{ tile.value }}
                        </p>
                    </div>
                    <span
                        class="flex size-10 shrink-0 items-center justify-center rounded-xl"
                        :class="tile.accent"
                    >
                        <component :is="tile.icon" class="size-5" />
                    </span>
                </div>
                <div class="mt-2 flex flex-wrap items-center gap-1.5 text-xs">
                    <span
                        v-if="tile.change !== null"
                        class="inline-flex items-center gap-0.5 rounded-full px-1.5 py-0.5 font-semibold"
                        :class="
                            tile.change >= 0
                                ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400'
                                : 'bg-rose-500/10 text-rose-700 dark:text-rose-400'
                        "
                    >
                        <component
                            :is="tile.change >= 0 ? TrendingUp : TrendingDown"
                            class="size-3"
                        />
                        {{ tile.change >= 0 ? '+' : ''
                        }}{{ tile.change.toLocaleString('fr-DZ') }} %
                    </span>
                    <span
                        v-if="tile.previous !== null"
                        class="text-muted-foreground"
                    >
                        avant : {{ tile.previous }}
                    </span>
                    <span v-else class="text-muted-foreground">{{
                        tile.hint
                    }}</span>
                </div>
            </article>
        </section>

        <!-- Net profit (doctor-level: costs include salaries) -->
        <section v-if="profit" class="grid gap-4 lg:grid-cols-[1fr_1.3fr]">
            <div
                class="grid gap-4 sm:grid-cols-3 lg:grid-cols-1 xl:grid-cols-3"
            >
                <article class="med-panel p-5">
                    <p
                        class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Charges
                    </p>
                    <p
                        class="mt-2 text-2xl font-bold text-rose-700 tabular-nums dark:text-rose-400"
                    >
                        {{ formatMoney(profit.expenses) }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        avant : {{ formatMoney(profit.previous_expenses) }}
                    </p>
                </article>
                <article class="med-panel p-5">
                    <p
                        class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Bénéfice net
                    </p>
                    <p
                        class="mt-2 text-2xl font-bold tabular-nums"
                        :class="
                            profit.net < 0
                                ? 'text-rose-700 dark:text-rose-400'
                                : 'text-emerald-700 dark:text-emerald-400'
                        "
                    >
                        {{ formatMoney(profit.net) }}
                    </p>
                    <p class="mt-1 flex items-center gap-1.5 text-xs">
                        <span
                            v-if="profit.changes.net !== null"
                            class="inline-flex items-center gap-0.5 rounded-full px-1.5 py-0.5 font-semibold"
                            :class="
                                profit.changes.net >= 0
                                    ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400'
                                    : 'bg-rose-500/10 text-rose-700 dark:text-rose-400'
                            "
                        >
                            {{ profit.changes.net >= 0 ? '+' : ''
                            }}{{ profit.changes.net.toLocaleString('fr-DZ') }}
                            %
                        </span>
                        <span class="text-muted-foreground">
                            avant : {{ formatMoney(profit.previous_net) }}
                        </span>
                    </p>
                </article>
                <article class="med-panel p-5">
                    <p
                        class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Marge nette
                    </p>
                    <p class="mt-2 text-2xl font-bold tabular-nums">
                        {{
                            profit.margin === null
                                ? '—'
                                : `${profit.margin.toLocaleString('fr-DZ')} %`
                        }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        Part de l’encaissé qui reste après les charges
                    </p>
                </article>
            </div>
            <Card>
                <CardHeader
                    class="flex flex-row flex-wrap items-start justify-between gap-3 space-y-0"
                >
                    <div class="space-y-1.5">
                        <CardTitle>Où part l’argent</CardTitle>
                        <CardDescription>Charges par catégorie</CardDescription>
                    </div>
                    <Link
                        href="/app/expenses"
                        class="text-sm font-semibold text-brand hover:underline print:hidden"
                    >
                        Gérer les charges →
                    </Link>
                </CardHeader>
                <CardContent>
                    <HBarChart
                        v-if="profit.categories.length"
                        :data="
                            profit.categories.map((row) => ({
                                label: `${row.label} · ${row.share.toLocaleString('fr-DZ')} %`,
                                value: row.value,
                            }))
                        "
                        :format-value="formatMoney"
                    />
                    <p
                        v-else
                        class="py-6 text-center text-sm text-muted-foreground"
                    >
                        Aucune charge saisie sur la période.
                        <Link
                            href="/app/expenses"
                            class="font-semibold text-brand hover:underline"
                            >Ajouter les charges</Link
                        >
                        pour voir le bénéfice réel.
                    </p>
                </CardContent>
            </Card>
        </section>

        <!-- Timeline -->
        <Card>
            <CardHeader>
                <CardTitle>
                    {{
                        isMonthView
                            ? 'Encaissé et facturé, jour par jour'
                            : `Encaissements mois par mois · ${period.year} vs ${period.year - 1}`
                    }}
                </CardTitle>
                <CardDescription>
                    {{
                        isMonthView
                            ? 'Chaque colonne correspond à un jour du mois.'
                            : 'Cliquez sur un mois pour ouvrir son détail.'
                    }}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <ComparisonBarChart
                    :data="chartData"
                    :current-label="isMonthView ? 'Encaissé' : `${period.year}`"
                    :previous-label="
                        isMonthView ? 'Facturé' : `${period.year - 1}`
                    "
                    :format-value="formatMoney"
                    :format-axis="formatAxis"
                    :clickable="!isMonthView"
                    @select="selectMonth"
                />

                <details class="mt-4 rounded-xl border" :open="!isMonthView">
                    <summary
                        class="cursor-pointer px-4 py-3 text-sm font-semibold"
                    >
                        Tableau détaillé
                    </summary>
                    <div class="med-table-wrap rounded-none border-0">
                        <table class="med-table">
                            <thead>
                                <tr>
                                    <th class="px-4 py-2 font-medium">
                                        {{ isMonthView ? 'Jour' : 'Mois' }}
                                    </th>
                                    <th
                                        class="px-4 py-2 text-right font-medium"
                                    >
                                        Facturé
                                    </th>
                                    <th
                                        class="px-4 py-2 text-right font-medium"
                                    >
                                        Encaissé
                                    </th>
                                    <th
                                        v-if="!isMonthView"
                                        class="px-4 py-2 text-right font-medium"
                                    >
                                        Remises
                                    </th>
                                    <th
                                        class="px-4 py-2 text-right font-medium"
                                    >
                                        Remboursé
                                    </th>
                                    <th
                                        class="px-4 py-2 text-right font-medium"
                                    >
                                        Consult.
                                    </th>
                                    <template v-if="profit">
                                        <th
                                            class="px-4 py-2 text-right font-medium"
                                        >
                                            Charges
                                        </th>
                                        <th
                                            class="px-4 py-2 text-right font-medium"
                                        >
                                            Bénéfice
                                        </th>
                                    </template>
                                    <th
                                        v-if="!isMonthView"
                                        class="px-4 py-2 text-right font-medium"
                                    >
                                        Encaissé {{ period.year - 1 }}
                                    </th>
                                    <th
                                        v-if="!isMonthView"
                                        class="px-4 py-2 text-right font-medium"
                                    >
                                        Évolution
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="row in report.timeline"
                                    :key="row.key"
                                    class="bg-background"
                                    :class="[
                                        row.future
                                            ? 'text-muted-foreground/60'
                                            : '',
                                        !isMonthView && !row.future
                                            ? 'cursor-pointer hover:bg-muted/40'
                                            : '',
                                    ]"
                                    @click="
                                        !isMonthView &&
                                        !row.future &&
                                        selectMonth(row.key)
                                    "
                                >
                                    <td class="px-4 py-2 font-medium">
                                        {{
                                            isMonthView
                                                ? `${row.weekday} ${row.day}`
                                                : MONTHS[(row.month ?? 1) - 1]
                                        }}
                                    </td>
                                    <td
                                        class="px-4 py-2 text-right tabular-nums"
                                    >
                                        {{ formatMoney(row.billed) }}
                                    </td>
                                    <td
                                        class="px-4 py-2 text-right font-semibold tabular-nums"
                                    >
                                        {{ formatMoney(row.collected) }}
                                    </td>
                                    <td
                                        v-if="!isMonthView"
                                        class="px-4 py-2 text-right tabular-nums"
                                    >
                                        {{ formatMoney(row.discounts ?? 0) }}
                                    </td>
                                    <td
                                        class="px-4 py-2 text-right tabular-nums"
                                    >
                                        {{
                                            row.refunds > 0
                                                ? formatMoney(row.refunds)
                                                : '—'
                                        }}
                                    </td>
                                    <td
                                        class="px-4 py-2 text-right tabular-nums"
                                    >
                                        {{ row.consultations }}
                                    </td>
                                    <template v-if="profit">
                                        <td
                                            class="px-4 py-2 text-right text-rose-700 tabular-nums dark:text-rose-400"
                                        >
                                            {{
                                                profitRow(row.key).expenses > 0
                                                    ? formatMoney(
                                                          profitRow(row.key)
                                                              .expenses,
                                                      )
                                                    : '—'
                                            }}
                                        </td>
                                        <td
                                            class="px-4 py-2 text-right font-semibold tabular-nums"
                                            :class="
                                                profitRow(row.key).net < 0
                                                    ? 'text-rose-700 dark:text-rose-400'
                                                    : ''
                                            "
                                        >
                                            {{
                                                row.future
                                                    ? '—'
                                                    : formatMoney(
                                                          profitRow(row.key)
                                                              .net,
                                                      )
                                            }}
                                        </td>
                                    </template>
                                    <td
                                        v-if="!isMonthView"
                                        class="px-4 py-2 text-right text-muted-foreground tabular-nums"
                                    >
                                        {{
                                            formatMoney(
                                                row.previous_collected ?? 0,
                                            )
                                        }}
                                    </td>
                                    <td
                                        v-if="!isMonthView"
                                        class="px-4 py-2 text-right text-xs font-semibold tabular-nums"
                                        :class="
                                            (row.previous_collected ?? 0) > 0 &&
                                            !row.future
                                                ? row.collected >=
                                                  (row.previous_collected ?? 0)
                                                    ? 'text-emerald-700 dark:text-emerald-400'
                                                    : 'text-rose-700 dark:text-rose-400'
                                                : 'text-muted-foreground'
                                        "
                                    >
                                        {{
                                            (row.previous_collected ?? 0) > 0 &&
                                            !row.future
                                                ? `${row.collected >= (row.previous_collected ?? 0) ? '+' : ''}${(
                                                      ((row.collected -
                                                          (row.previous_collected ??
                                                              0)) /
                                                          (row.previous_collected ??
                                                              1)) *
                                                      100
                                                  ).toFixed(1)} %`
                                                : '—'
                                        }}
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr class="border-t bg-muted/30 font-bold">
                                    <td class="px-4 py-2">Total</td>
                                    <td
                                        class="px-4 py-2 text-right tabular-nums"
                                    >
                                        {{ formatMoney(kpis.billed) }}
                                    </td>
                                    <td
                                        class="px-4 py-2 text-right tabular-nums"
                                    >
                                        {{ formatMoney(kpis.collected) }}
                                    </td>
                                    <td
                                        v-if="!isMonthView"
                                        class="px-4 py-2 text-right tabular-nums"
                                    >
                                        {{ formatMoney(kpis.discounts) }}
                                    </td>
                                    <td
                                        class="px-4 py-2 text-right tabular-nums"
                                    >
                                        {{ formatMoney(kpis.refunds) }}
                                    </td>
                                    <td
                                        class="px-4 py-2 text-right tabular-nums"
                                    >
                                        {{ kpis.consultations }}
                                    </td>
                                    <template v-if="profit">
                                        <td
                                            class="px-4 py-2 text-right tabular-nums"
                                        >
                                            {{ formatMoney(profit.expenses) }}
                                        </td>
                                        <td
                                            class="px-4 py-2 text-right tabular-nums"
                                        >
                                            {{ formatMoney(profit.net) }}
                                        </td>
                                    </template>
                                    <td
                                        v-if="!isMonthView"
                                        class="px-4 py-2 text-right tabular-nums"
                                    >
                                        {{
                                            formatMoney(
                                                report.timeline.reduce(
                                                    (sum, row) =>
                                                        sum +
                                                        (row.previous_collected ??
                                                            0),
                                                    0,
                                                ),
                                            )
                                        }}
                                    </td>
                                    <td v-if="!isMonthView" />
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </details>
            </CardContent>
        </Card>

        <!-- Breakdowns -->
        <section class="grid gap-4 lg:grid-cols-3">
            <Card>
                <CardHeader>
                    <CardTitle>Modes de paiement</CardTitle>
                    <CardDescription
                        >Répartition des encaissements</CardDescription
                    >
                </CardHeader>
                <CardContent class="flex flex-col items-center gap-5">
                    <template v-if="methodSlices.length">
                        <DonutChart
                            :data="methodSlices"
                            :size="180"
                            :thickness="26"
                            :center-value="formatAxis(kpis.collected)"
                            center-label="Encaissé"
                        />
                        <ul class="w-full space-y-2 text-sm">
                            <li
                                v-for="method in report.methods"
                                :key="method.label"
                                class="flex items-center justify-between gap-3"
                            >
                                <span class="flex min-w-0 items-center gap-2">
                                    <span
                                        class="size-2.5 shrink-0 rounded-full"
                                        :style="{
                                            background: methodColor(
                                                method.label,
                                            ),
                                        }"
                                    />
                                    <span class="truncate">{{
                                        paymentMethodLabel(method.label)
                                    }}</span>
                                    <span class="text-xs text-muted-foreground"
                                        >{{ method.share }} %</span
                                    >
                                </span>
                                <span class="font-semibold tabular-nums">{{
                                    formatMoney(method.value)
                                }}</span>
                            </li>
                        </ul>
                    </template>
                    <p v-else class="py-10 text-sm text-muted-foreground">
                        Aucun encaissement sur la période.
                    </p>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Prestations les plus rentables</CardTitle>
                    <CardDescription
                        >Montant facturé par prestation</CardDescription
                    >
                </CardHeader>
                <CardContent>
                    <HBarChart
                        v-if="report.services.length"
                        :data="
                            report.services.map((service) => ({
                                label: `${service.label} (${service.count})`,
                                value: service.value,
                            }))
                        "
                        :format-value="formatMoney"
                    />
                    <p
                        v-else
                        class="py-10 text-center text-sm text-muted-foreground"
                    >
                        Aucune prestation facturée.
                    </p>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Encaissements par jour de semaine</CardTitle>
                    <CardDescription>Jours les plus productifs</CardDescription>
                </CardHeader>
                <CardContent>
                    <BarChart
                        :data="report.weekdays"
                        color="var(--viz-current)"
                        :height="200"
                        :format-value="formatMoney"
                    />
                </CardContent>
            </Card>
        </section>

        <section class="grid gap-4 lg:grid-cols-2">
            <Card>
                <CardHeader>
                    <CardTitle>Encaissements par utilisateur</CardTitle>
                    <CardDescription
                        >Qui a enregistré les versements</CardDescription
                    >
                </CardHeader>
                <CardContent>
                    <HBarChart
                        v-if="report.practitioners.length"
                        :data="
                            report.practitioners.map((row) => ({
                                label: `${row.label} (${row.count})`,
                                value: row.value,
                            }))
                        "
                        :format-value="formatMoney"
                    />
                    <p
                        v-else
                        class="py-10 text-center text-sm text-muted-foreground"
                    >
                        Aucun encaissement sur la période.
                    </p>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Meilleurs patients de la période</CardTitle>
                    <CardDescription>Montants encaissés</CardDescription>
                </CardHeader>
                <CardContent>
                    <ul
                        v-if="report.topPatients.length"
                        class="divide-y divide-border"
                    >
                        <li
                            v-for="(patient, index) in report.topPatients"
                            :key="patient.patient_id"
                            class="flex items-center justify-between gap-3 py-2.5 first:pt-0 last:pb-0"
                        >
                            <Link
                                :href="`/app/patients/${patient.patient_id}`"
                                class="flex min-w-0 items-center gap-3 hover:underline"
                            >
                                <span
                                    class="flex size-7 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-bold"
                                    >{{ index + 1 }}</span
                                >
                                <span class="min-w-0">
                                    <span
                                        class="block truncate text-sm font-medium"
                                        >{{ patient.patient_name }}</span
                                    >
                                    <span class="text-xs text-muted-foreground"
                                        >{{ patient.patient_number ?? '—' }} ·
                                        {{ patient.count }} versement(s)</span
                                    >
                                </span>
                            </Link>
                            <span class="shrink-0 font-semibold tabular-nums">{{
                                formatMoney(patient.value)
                            }}</span>
                        </li>
                    </ul>
                    <p
                        v-else
                        class="py-10 text-center text-sm text-muted-foreground"
                    >
                        Aucun encaissement sur la période.
                    </p>
                </CardContent>
            </Card>
        </section>

        <!-- Receivables -->
        <Card>
            <CardHeader
                class="flex flex-row flex-wrap items-start justify-between gap-3 space-y-0"
            >
                <div class="space-y-1.5">
                    <CardTitle class="flex items-center gap-2">
                        <AlertTriangle class="size-4 text-amber-600" />
                        Dettes patients à ce jour
                    </CardTitle>
                    <CardDescription>
                        {{ formatMoney(receivables.total) }} dus par
                        {{ receivables.patients }} patient(s) sur
                        {{ receivables.count }} consultation(s), toutes périodes
                        confondues.
                    </CardDescription>
                </div>
                <Button variant="secondary" as-child class="print:hidden">
                    <Link href="/app/payments?status=debt">
                        Relancer les dettes
                        <ArrowRight class="size-4" />
                    </Link>
                </Button>
            </CardHeader>
            <CardContent class="space-y-5">
                <div
                    v-if="receivables.total > 0"
                    class="flex h-3 overflow-hidden rounded-full bg-muted"
                    role="img"
                    aria-label="Ancienneté des dettes"
                >
                    <div
                        v-for="bucket in receivables.buckets"
                        :key="bucket.key"
                        class="h-3 border-r-2 border-card last:border-r-0"
                        :class="agingTones[bucket.key]"
                        :style="{ width: `${agingShare(bucket.amount)}%` }"
                    />
                </div>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div
                        v-for="bucket in receivables.buckets"
                        :key="bucket.key"
                        class="rounded-xl border p-3"
                    >
                        <p
                            class="flex items-center gap-2 text-xs text-muted-foreground"
                        >
                            <span
                                class="size-2.5 rounded-full"
                                :class="agingTones[bucket.key]"
                            />
                            {{ bucket.label }}
                        </p>
                        <p class="mt-1 text-lg font-bold tabular-nums">
                            {{ formatMoney(bucket.amount) }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ bucket.count }} consultation(s) ·
                            {{ Math.round(agingShare(bucket.amount)) }} %
                        </p>
                    </div>
                </div>

                <div v-if="receivables.debtors.length" class="med-table-wrap">
                    <table class="med-table">
                        <thead>
                            <tr>
                                <th class="px-4 py-2 font-medium">Patient</th>
                                <th class="px-4 py-2 font-medium">Téléphone</th>
                                <th class="px-4 py-2 text-right font-medium">
                                    Consult.
                                </th>
                                <th class="px-4 py-2 font-medium">
                                    Plus ancienne
                                </th>
                                <th class="px-4 py-2 text-right font-medium">
                                    Montant dû
                                </th>
                                <th class="px-4 py-2 print:hidden">
                                    <span class="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="debtor in receivables.debtors"
                                :key="debtor.patient_id"
                                class="bg-background"
                            >
                                <td class="px-4 py-2">
                                    <Link
                                        :href="`/app/patients/${debtor.patient_id}`"
                                        class="inline-flex items-center gap-2 font-medium hover:underline"
                                    >
                                        <UserRound
                                            class="size-4 text-muted-foreground"
                                        />
                                        {{ debtor.patient_name }}
                                    </Link>
                                    <span
                                        class="ml-1 text-xs text-muted-foreground"
                                        >{{ debtor.patient_number }}</span
                                    >
                                </td>
                                <td class="px-4 py-2 text-muted-foreground">
                                    <a
                                        v-if="debtor.phone"
                                        :href="`tel:${debtor.phone}`"
                                        class="hover:underline"
                                        >{{ debtor.phone }}</a
                                    >
                                    <span v-else>—</span>
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums">
                                    {{ debtor.count }}
                                </td>
                                <td class="px-4 py-2">
                                    {{ formatDate(debtor.oldest) }}
                                    <span
                                        class="ml-1 rounded-full px-1.5 py-0.5 text-[11px] font-semibold"
                                        :class="
                                            debtor.age_days > 90
                                                ? 'bg-rose-500/10 text-rose-700 dark:text-rose-400'
                                                : debtor.age_days > 30
                                                  ? 'bg-amber-500/10 text-amber-700 dark:text-amber-400'
                                                  : 'bg-muted text-muted-foreground'
                                        "
                                    >
                                        {{ debtor.age_days }} j
                                    </span>
                                </td>
                                <td
                                    class="px-4 py-2 text-right font-bold text-amber-700 tabular-nums dark:text-amber-400"
                                >
                                    {{ formatMoney(debtor.amount) }}
                                </td>
                                <td class="px-4 py-2 text-right print:hidden">
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        as-child
                                    >
                                        <Link :href="collectUrl(debtor)">
                                            <Banknote class="size-3.5" />
                                            Encaisser
                                        </Link>
                                    </Button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p
                    v-else
                    class="py-6 text-center text-sm text-muted-foreground"
                >
                    Aucune dette en cours. Tout est encaissé.
                </p>
            </CardContent>
        </Card>
    </div>
</template>
