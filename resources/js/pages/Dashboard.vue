<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowRight,
    Banknote,
    BellRing,
    Building2,
    CalendarCheck,
    CalendarRange,
    ChartColumnBig,
    CircleDollarSign,
    Clock3,
    FileText,
    Gauge,
    PiggyBank,
    ReceiptText,
    Stethoscope,
    TrendingDown,
    TrendingUp,
    UserPlus,
    Users,
    Wallet,
} from '@lucide/vue';
import { isTauri } from '@tauri-apps/api/core';
import { computed, onMounted, ref } from 'vue';
import AreaChart from '@/components/charts/AreaChart.vue';
import BarChart from '@/components/charts/BarChart.vue';
import ComparisonBarChart from '@/components/charts/ComparisonBarChart.vue';
import DonutChart from '@/components/charts/DonutChart.vue';
import HBarChart from '@/components/charts/HBarChart.vue';
import Heading from '@/components/Heading.vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    compactAmount,
    methodColor,
    paymentMethodLabel,
} from '@/pages/payments/display';
import { dashboard } from '@/routes';

type TrendPoint = { label: string; value: number };
type StatusSlice = {
    status: string;
    label: string;
    count: number;
    color: string;
};
type Prestation = { label: string; value: number };
type Payment = {
    id: number;
    patient_name: string;
    patient_number: string | null;
    amount: number;
    method: string | null;
    date_label: string | null;
};

type FinanceKpis = {
    collected: number;
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
type FinanceSnapshot = {
    today: FinanceKpis;
    month: FinanceKpis;
    year: FinanceKpis;
    changes: {
        month_collected: number | null;
        year_collected: number | null;
        month_average_ticket: number | null;
    };
    month_projection: number | null;
    year_label: string;
    month_label: string;
    timeline: {
        key: string;
        month: number;
        label: string;
        collected: number;
        previous_collected: number;
        future: boolean;
    }[];
    methods: { label: string; value: number; count: number; share: number }[];
};
type Receivables = {
    total: number;
    count: number;
    patients: number;
    buckets: { key: string; label: string; amount: number; count: number }[];
    debtors: {
        patient_id: number;
        patient_name: string;
        patient_number: string | null;
        amount: number;
        age_days: number;
    }[];
};

const props = defineProps<{
    currency: string;
    stats: {
        new_patients_this_month: number;
        new_patients_change: number | null;
        no_show_rate: number | null;
        revenue_this_month: number;
        revenue_last_month: number;
        revenue_total: number;
        revenue_change: number | null;
        appointments_total: number;
        appointments_this_month: number;
        prestations_total: number;
        patients_total: number;
        consultations_total: number;
    };
    revenueTrend: TrendPoint[];
    appointmentsByStatus: StatusSlice[];
    appointmentsTrend: TrendPoint[];
    topPrestations: Prestation[];
    recentPayments: Payment[];
    canViewFinance: boolean;
    finance: FinanceSnapshot | null;
    receivables: Receivables | null;
    profit: {
        expenses: number;
        net: number;
        margin: number | null;
        changes: { expenses: number | null; net: number | null };
    } | null;
    todayActivity: {
        total: number;
        waiting: number;
        done: number;
        cancelled: number;
        consultations: number;
        upcoming: {
            id: number;
            time: string | null;
            patient_name: string;
            prestation: string | null;
            status: string;
            status_label: string;
        }[];
    };
    patientsTrend: TrendPoint[];
    profile: {
        welcome_name: string | null;
        clinic_name: string;
        doctor_name: string | null;
        specialty: string | null;
        professional_identifier: string | null;
        prescriptions_total: number;
    };
}>();

const desktopRuntime = ref(false);

onMounted(() => {
    desktopRuntime.value = isTauri();
});

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Tableau de bord',
                href: dashboard(),
            },
        ],
    },
});

const formatMoney = (value: number): string =>
    `${new Intl.NumberFormat('fr-DZ', { maximumFractionDigits: 0 }).format(value)} ${props.currency}`;

const formatNumber = (value: number): string =>
    new Intl.NumberFormat('fr-DZ').format(value);

const paymentMethodLabels: Record<string, string> = {
    cash: 'Espèces',
    card: 'Carte',
    bank_transfer: 'Virement bancaire',
    insurance: 'Assurance',
    other: 'Autre',
};

const formatMethod = (method: string): string =>
    paymentMethodLabels[method] ?? method.replace(/_/g, ' ');

const initials = (name: string): string =>
    name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((word) => word.charAt(0).toUpperCase())
        .join('') || '?';

const kpis = computed(() => [
    {
        key: 'revenue',
        label: 'Recettes du mois',
        value: formatMoney(props.stats.revenue_this_month),
        icon: Wallet,
        accent: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
        change: props.stats.revenue_change,
        footnote: `Total ${formatMoney(props.stats.revenue_total)}`,
    },
    {
        key: 'appointments',
        label: 'Rendez-vous',
        value: formatNumber(props.stats.appointments_total),
        icon: CalendarCheck,
        accent: 'bg-brand-soft0/10 text-brand dark:text-brand-mint',
        change: null,
        footnote: `${formatNumber(props.stats.appointments_this_month)} ce mois-ci`,
    },
    {
        key: 'prestations',
        label: 'Consultations',
        value: formatNumber(props.stats.consultations_total),
        icon: Stethoscope,
        accent: 'bg-brand-soft0/10 text-brand dark:text-brand-mint',
        change: null,
        footnote:
            formatNumber(props.stats.prestations_total) +
            ' prestations actives',
    },
    {
        key: 'patients',
        label: 'Patients',
        value: formatNumber(props.stats.patients_total),
        icon: Users,
        accent: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
        change: props.stats.new_patients_change,
        footnote: `+${formatNumber(props.stats.new_patients_this_month)} nouveaux ce mois-ci`,
    },
]);

// With the finance section shown, its month tile (compared over the same
// elapsed days) replaces the older full-month revenue card.
const visibleKpis = computed(() =>
    props.canViewFinance && props.finance
        ? kpis.value.filter((kpi) => kpi.key !== 'revenue')
        : kpis.value,
);

const statusSlices = computed(() =>
    props.appointmentsByStatus.map((slice) => ({
        label: slice.label,
        value: slice.count,
        color: slice.color,
    })),
);

const formatPercent = (value: number): string =>
    `${value >= 0 ? '+' : ''}${value.toLocaleString('fr-DZ')} %`;

const financeTiles = computed(() => {
    const finance = props.finance;

    if (!finance) {
        return [];
    }

    return [
        {
            key: 'today',
            label: 'Encaissé aujourd’hui',
            value: formatMoney(finance.today.collected),
            change: null as number | null,
            footnote: `${formatNumber(finance.today.transactions)} versement(s)`,
            icon: Banknote,
            accent: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
        },
        {
            key: 'month',
            label: `Encaissé · ${finance.month_label}`,
            value: formatMoney(finance.month.collected),
            change: finance.changes.month_collected,
            footnote:
                finance.month_projection !== null
                    ? `≈ ${formatMoney(finance.month_projection)} en fin de mois`
                    : `Facturé ${formatMoney(finance.month.billed)}`,
            icon: Wallet,
            accent: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
        },
        {
            key: 'year',
            label: `Encaissé · ${finance.year_label}`,
            value: formatMoney(finance.year.collected),
            change: finance.changes.year_collected,
            footnote: 'vs même période l’an dernier',
            icon: CalendarRange,
            accent: 'bg-brand-soft text-brand',
        },
        {
            key: 'debt',
            label: 'Dettes patients',
            value: formatMoney(props.receivables?.total ?? 0),
            change: null,
            footnote: `${props.receivables?.patients ?? 0} patient(s) concerné(s)`,
            icon: CircleDollarSign,
            accent: 'bg-amber-500/10 text-amber-700 dark:text-amber-400',
        },
        {
            key: 'rate',
            label: 'Taux de recouvrement',
            value:
                finance.month.collection_rate === null
                    ? '—'
                    : `${finance.month.collection_rate.toLocaleString('fr-DZ')} %`,
            change: null,
            footnote: `Consultations de ${finance.month_label.toLowerCase()}`,
            icon: Gauge,
            accent: 'bg-sky-500/10 text-sky-700 dark:text-sky-400',
        },
        {
            key: 'ticket',
            label: 'Panier moyen',
            value: formatMoney(finance.month.average_ticket),
            change: finance.changes.month_average_ticket,
            footnote: `${formatNumber(finance.month.consultations)} consultation(s) facturée(s)`,
            icon: ReceiptText,
            accent: 'bg-brand-soft text-brand',
        },
    ].map((tile) =>
        // Doctors see what is left after costs instead of the average bill.
        tile.key === 'ticket' && props.profit
            ? {
                  key: 'net',
                  label: `Bénéfice net · ${finance.month_label}`,
                  value: formatMoney(props.profit.net),
                  change: props.profit.changes.net,
                  footnote: `Charges ${formatMoney(props.profit.expenses)}`,
                  icon: PiggyBank,
                  accent:
                      props.profit.net < 0
                          ? 'bg-rose-500/10 text-rose-700 dark:text-rose-400'
                          : 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
              }
            : tile,
    );
});

const yearChart = computed(() =>
    (props.finance?.timeline ?? []).map((row) => ({
        key: row.key,
        label: row.label,
        current: row.collected,
        previous: row.previous_collected,
        future: row.future,
    })),
);

const methodSlices = computed(() =>
    (props.finance?.methods ?? [])
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

const openMonth = (key: string) => {
    const [year, month] = key.split('-').map(Number);
    router.get('/app/payments/analytics', { year, month });
};

const todayLabel = new Intl.DateTimeFormat('fr-DZ', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
}).format(new Date());
</script>

<template>
    <Head title="Tableau de bord" />

    <div class="med-page">
        <Heading
            title="Tableau de bord"
            description="Vue d’ensemble en temps réel des recettes, rendez-vous et de l’activité du cabinet."
        />

        <div
            class="grid gap-4 xl:grid-cols-[minmax(0,1.8fr)_minmax(300px,0.8fr)]"
        >
            <section
                class="relative overflow-hidden rounded-2xl bg-brand-deep p-6 text-white shadow-lg shadow-brand-deep/10"
            >
                <div
                    class="absolute -top-20 -right-16 size-64 rounded-full bg-white/10 blur-2xl"
                />
                <div
                    class="absolute -bottom-24 left-1/3 size-56 rounded-full bg-brand-mint/15 blur-3xl"
                />
                <div
                    class="relative z-10"
                    :class="desktopRuntime ? 'lg:max-w-[68%]' : ''"
                >
                    <div
                        class="flex items-center gap-2 text-sm font-medium text-white/75"
                    >
                        <Building2 class="size-4" />
                        {{ profile.clinic_name }}
                    </div>
                    <h2
                        class="mt-5 text-2xl font-bold tracking-tight sm:text-3xl"
                    >
                        Bienvenue, {{ profile.welcome_name }}
                    </h2>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-white/75">
                        Retrouvez les rendez-vous du jour, l’activité des
                        patients et les paiements depuis un espace de travail
                        unique.
                    </p>
                    <p
                        class="mt-4 text-xs font-semibold tracking-wide text-white/75 uppercase"
                    >
                        {{ todayLabel }}
                    </p>
                    <div class="mt-6 flex flex-wrap gap-2">
                        <Link
                            href="/app/appointments"
                            class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-white px-5 text-sm font-semibold whitespace-nowrap text-brand shadow-sm transition hover:-translate-y-0.5 hover:bg-brand-soft focus-visible:ring-2 focus-visible:ring-white/70"
                        >
                            Rendez-vous du jour
                            <ArrowRight class="size-4 shrink-0" />
                        </Link>
                        <Link
                            href="/app/payments"
                            class="inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-white/35 bg-white/10 px-5 text-sm font-semibold whitespace-nowrap text-white shadow-sm transition hover:-translate-y-0.5 hover:bg-white/20 focus-visible:ring-2 focus-visible:ring-white/70"
                        >
                            Voir les paiements
                            <Banknote class="size-4 shrink-0" />
                        </Link>
                        <Link
                            href="/app/reminders"
                            class="inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-white/35 bg-white/10 px-5 text-sm font-semibold whitespace-nowrap text-white shadow-sm transition hover:-translate-y-0.5 hover:bg-white/20 focus-visible:ring-2 focus-visible:ring-white/70"
                        >
                            Relances WhatsApp / SMS
                            <BellRing class="size-4 shrink-0" />
                        </Link>
                    </div>
                </div>
                <img
                    v-if="desktopRuntime"
                    src="/brands/Bell%2C%20calendar%2C%20and%20phone%20reminders-3.png"
                    alt=""
                    class="pointer-events-none absolute right-3 bottom-0 hidden h-52 w-52 object-contain lg:block xl:right-5 xl:h-64 xl:w-64"
                    loading="lazy"
                />
            </section>

            <section class="med-panel p-5">
                <div class="flex items-center gap-3">
                    <div
                        class="flex size-14 shrink-0 items-center justify-center rounded-full bg-brand text-base font-bold text-white"
                    >
                        {{
                            initials(
                                profile.doctor_name ??
                                    profile.welcome_name ??
                                    '?',
                            )
                        }}
                    </div>
                    <div class="min-w-0">
                        <p class="truncate text-base font-bold">
                            {{ profile.doctor_name ?? profile.welcome_name }}
                        </p>
                        <p class="truncate text-sm font-medium text-brand">
                            {{ profile.specialty ?? 'Professionnel de santé' }}
                        </p>
                        <p class="truncate text-xs text-muted-foreground">
                            {{
                                profile.professional_identifier ??
                                profile.clinic_name
                            }}
                        </p>
                    </div>
                </div>
                <div
                    class="mt-5 grid grid-cols-2 gap-3 border-t border-border pt-4"
                >
                    <div class="rounded-xl bg-muted/40 p-3">
                        <p class="text-xl font-bold tabular-nums">
                            {{ formatNumber(stats.consultations_total) }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Consultations
                        </p>
                    </div>
                    <div class="rounded-xl bg-muted/40 p-3">
                        <p
                            class="flex items-center gap-1.5 text-xl font-bold tabular-nums"
                        >
                            <FileText class="size-4 text-amber-500" />
                            {{ formatNumber(profile.prescriptions_total) }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Prescriptions
                        </p>
                    </div>
                </div>
            </section>
        </div>

        <!-- Today at a glance -->
        <section
            class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(320px,0.9fr)]"
        >
            <div class="med-panel p-5">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="flex items-center gap-2 text-base font-bold">
                        <Clock3 class="size-4 text-brand" />
                        Aujourd’hui
                    </h2>
                    <Link
                        href="/app/appointments"
                        class="inline-flex items-center gap-1 text-sm font-semibold text-brand hover:underline"
                    >
                        Agenda <ArrowRight class="size-3.5" />
                    </Link>
                </div>
                <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-5">
                    <div
                        v-for="item in [
                            {
                                label: 'Rendez-vous',
                                value: todayActivity.total,
                            },
                            {
                                label: 'En salle / en cours',
                                value: todayActivity.waiting,
                            },
                            { label: 'Terminés', value: todayActivity.done },
                            {
                                label: 'Annulés / absents',
                                value: todayActivity.cancelled,
                            },
                            {
                                label: 'Consultations',
                                value: todayActivity.consultations,
                            },
                        ]"
                        :key="item.label"
                        class="rounded-xl bg-muted/40 p-3"
                    >
                        <p class="text-2xl font-bold tabular-nums">
                            {{ formatNumber(item.value) }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{ item.label }}
                        </p>
                    </div>
                </div>
            </div>

            <div class="med-panel p-5">
                <h2 class="text-base font-bold">Prochains patients</h2>
                <ul
                    v-if="todayActivity.upcoming.length"
                    class="mt-3 divide-y divide-border"
                >
                    <li
                        v-for="item in todayActivity.upcoming"
                        :key="item.id"
                        class="flex items-center justify-between gap-3 py-2 first:pt-0 last:pb-0"
                    >
                        <div class="flex min-w-0 items-center gap-3">
                            <span
                                class="w-12 shrink-0 font-mono text-sm font-semibold text-brand tabular-nums"
                                >{{ item.time ?? '—' }}</span
                            >
                            <span class="min-w-0">
                                <span
                                    class="block truncate text-sm font-medium"
                                    >{{ item.patient_name }}</span
                                >
                                <span
                                    v-if="item.prestation"
                                    class="block truncate text-xs text-muted-foreground"
                                    >{{ item.prestation }}</span
                                >
                            </span>
                        </div>
                        <span
                            class="shrink-0 rounded-full bg-muted px-2 py-0.5 text-[11px] font-semibold text-muted-foreground"
                            >{{ item.status_label }}</span
                        >
                    </li>
                </ul>
                <p
                    v-else
                    class="mt-6 text-center text-sm text-muted-foreground"
                >
                    Plus aucun rendez-vous prévu aujourd’hui.
                </p>
            </div>
        </section>

        <!-- Finance KPIs -->
        <section v-if="canViewFinance && finance" class="space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-lg font-bold tracking-tight">Finances</h2>
                <Link
                    href="/app/payments/analytics"
                    class="inline-flex items-center gap-1.5 text-sm font-semibold text-brand hover:underline"
                >
                    <ChartColumnBig class="size-4" />
                    Analyse financière complète
                </Link>
            </div>
            <div
                class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6"
            >
                <article
                    v-for="tile in financeTiles"
                    :key="tile.key"
                    class="med-panel p-4"
                >
                    <div class="flex items-start justify-between gap-2">
                        <p
                            class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            {{ tile.label }}
                        </p>
                        <span
                            class="flex size-8 shrink-0 items-center justify-center rounded-lg"
                            :class="tile.accent"
                        >
                            <component :is="tile.icon" class="size-4" />
                        </span>
                    </div>
                    <p
                        class="mt-2 text-xl font-bold tracking-tight tabular-nums"
                    >
                        {{ tile.value }}
                    </p>
                    <p class="mt-1 flex flex-wrap items-center gap-1.5 text-xs">
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
                                :is="
                                    tile.change >= 0 ? TrendingUp : TrendingDown
                                "
                                class="size-3"
                            />
                            {{ formatPercent(tile.change) }}
                        </span>
                        <span class="text-muted-foreground">{{
                            tile.footnote
                        }}</span>
                    </p>
                </article>
            </div>
        </section>

        <!-- KPI cards -->
        <div
            class="grid gap-4 sm:grid-cols-2"
            :class="
                visibleKpis.length === 4 ? 'xl:grid-cols-4' : 'xl:grid-cols-3'
            "
        >
            <Card v-for="kpi in visibleKpis" :key="kpi.key">
                <div class="flex items-start justify-between gap-4 px-6">
                    <div class="space-y-1.5">
                        <p class="text-sm font-medium text-muted-foreground">
                            {{ kpi.label }}
                        </p>
                        <p
                            class="text-2xl font-bold tracking-tight text-foreground tabular-nums"
                        >
                            {{ kpi.value }}
                        </p>
                        <div
                            class="flex flex-wrap items-center gap-1.5 text-xs"
                        >
                            <span
                                v-if="kpi.change !== null"
                                class="inline-flex items-center gap-0.5 rounded-full px-1.5 py-0.5 font-semibold"
                                :class="
                                    kpi.change >= 0
                                        ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
                                        : 'bg-rose-500/10 text-rose-600 dark:text-rose-400'
                                "
                            >
                                <component
                                    :is="
                                        kpi.change >= 0
                                            ? TrendingUp
                                            : TrendingDown
                                    "
                                    class="size-3"
                                />
                                {{ Math.abs(kpi.change) }}%
                            </span>
                            <span class="text-muted-foreground">
                                {{ kpi.footnote }}
                            </span>
                        </div>
                    </div>
                    <div
                        class="flex size-11 shrink-0 items-center justify-center rounded-xl"
                        :class="kpi.accent"
                    >
                        <component :is="kpi.icon" class="size-5" />
                    </div>
                </div>
            </Card>
        </div>

        <!-- Revenue trend + status donut -->
        <div class="grid gap-4 lg:grid-cols-3">
            <Card v-if="canViewFinance && finance" class="lg:col-span-2">
                <CardHeader>
                    <CardTitle>
                        Recettes {{ finance.year_label }} vs
                        {{ Number(finance.year_label) - 1 }}
                    </CardTitle>
                    <CardDescription>
                        Encaissements mois par mois · cliquez sur un mois pour
                        le détail
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <ComparisonBarChart
                        :data="yearChart"
                        :current-label="finance.year_label"
                        :previous-label="`${Number(finance.year_label) - 1}`"
                        :format-value="formatMoney"
                        :format-axis="compactAmount"
                        :height="260"
                        clickable
                        @select="openMonth"
                    />
                </CardContent>
            </Card>
            <Card v-else class="lg:col-span-2">
                <CardHeader>
                    <CardTitle>Recettes</CardTitle>
                    <CardDescription>
                        Consultations réglées sur les 6 derniers mois
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <AreaChart
                        :data="revenueTrend"
                        color="#00666f"
                        :format-value="formatMoney"
                    />
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Rendez-vous par statut</CardTitle>
                    <CardDescription>
                        Répartition entre les différents statuts
                    </CardDescription>
                </CardHeader>
                <CardContent class="flex flex-col items-center gap-6">
                    <DonutChart
                        :data="statusSlices"
                        :center-value="formatNumber(stats.appointments_total)"
                        center-label="Rendez-vous"
                    />
                    <div class="grid w-full grid-cols-2 gap-x-4 gap-y-2">
                        <div
                            v-for="slice in appointmentsByStatus"
                            :key="slice.status"
                            class="flex items-center justify-between gap-2 text-sm"
                        >
                            <span
                                class="flex min-w-0 items-center gap-2 truncate"
                            >
                                <span
                                    class="size-2.5 shrink-0 rounded-full"
                                    :style="{ backgroundColor: slice.color }"
                                />
                                <span class="truncate text-muted-foreground">
                                    {{ slice.label }}
                                </span>
                            </span>
                            <span class="font-semibold tabular-nums">
                                {{ slice.count }}
                            </span>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>

        <!-- Payment methods + receivables -->
        <div
            v-if="canViewFinance && finance && receivables"
            class="grid gap-4 lg:grid-cols-3"
        >
            <Card>
                <CardHeader>
                    <CardTitle>Modes de paiement</CardTitle>
                    <CardDescription>
                        Encaissements de {{ finance.month_label.toLowerCase() }}
                    </CardDescription>
                </CardHeader>
                <CardContent class="flex flex-col items-center gap-5">
                    <template v-if="methodSlices.length">
                        <DonutChart
                            :data="methodSlices"
                            :size="170"
                            :thickness="24"
                            :center-value="
                                compactAmount(finance.month.collected)
                            "
                            center-label="Ce mois"
                        />
                        <ul class="w-full space-y-2 text-sm">
                            <li
                                v-for="method in finance.methods"
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
                        Aucun encaissement ce mois-ci.
                    </p>
                </CardContent>
            </Card>

            <Card class="lg:col-span-2">
                <CardHeader
                    class="flex flex-row flex-wrap items-start justify-between gap-3 space-y-0"
                >
                    <div class="space-y-1.5">
                        <CardTitle class="flex items-center gap-2">
                            <AlertTriangle class="size-4 text-amber-600" />
                            Dettes à recouvrer
                        </CardTitle>
                        <CardDescription>
                            {{ formatMoney(receivables.total) }} sur
                            {{ receivables.count }} consultation(s)
                        </CardDescription>
                    </div>
                    <Link
                        href="/app/payments?status=debt"
                        class="inline-flex items-center gap-1 text-sm font-semibold text-brand hover:underline"
                    >
                        Toutes les dettes <ArrowRight class="size-3.5" />
                    </Link>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                        <div
                            v-for="bucket in receivables.buckets"
                            :key="bucket.key"
                            class="rounded-xl border p-3"
                        >
                            <p
                                class="flex items-center gap-1.5 text-[11px] text-muted-foreground"
                            >
                                <span
                                    class="size-2 rounded-full"
                                    :class="agingTones[bucket.key]"
                                />
                                {{ bucket.label }}
                            </p>
                            <p class="mt-1 font-bold tabular-nums">
                                {{ formatMoney(bucket.amount) }}
                            </p>
                        </div>
                    </div>
                    <ul
                        v-if="receivables.debtors.length"
                        class="divide-y divide-border"
                    >
                        <li
                            v-for="debtor in receivables.debtors"
                            :key="debtor.patient_id"
                            class="flex items-center justify-between gap-3 py-2 first:pt-0 last:pb-0"
                        >
                            <Link
                                :href="`/app/patients/${debtor.patient_id}`"
                                class="flex min-w-0 items-center gap-3 hover:underline"
                            >
                                <span
                                    class="flex size-8 shrink-0 items-center justify-center rounded-full bg-amber-500/15 text-xs font-semibold text-amber-800 dark:text-amber-300"
                                    >{{ initials(debtor.patient_name) }}</span
                                >
                                <span class="min-w-0">
                                    <span
                                        class="block truncate text-sm font-medium"
                                        >{{ debtor.patient_name }}</span
                                    >
                                    <span class="text-xs text-muted-foreground"
                                        >depuis
                                        {{ debtor.age_days }} jour(s)</span
                                    >
                                </span>
                            </Link>
                            <span
                                class="shrink-0 text-sm font-bold text-amber-700 tabular-nums dark:text-amber-400"
                                >{{ formatMoney(debtor.amount) }}</span
                            >
                        </li>
                    </ul>
                    <p
                        v-else
                        class="py-4 text-center text-sm text-muted-foreground"
                    >
                        Aucune dette en cours.
                    </p>
                </CardContent>
            </Card>
        </div>

        <!-- Patient growth -->
        <div class="grid gap-4 lg:grid-cols-3">
            <Card class="lg:col-span-2">
                <CardHeader
                    class="flex flex-row flex-wrap items-start justify-between gap-3 space-y-0"
                >
                    <div class="space-y-1.5">
                        <CardTitle class="flex items-center gap-2">
                            <UserPlus class="size-4 text-brand" />
                            Nouveaux patients
                        </CardTitle>
                        <CardDescription>
                            Dossiers ouverts sur les 12 derniers mois
                        </CardDescription>
                    </div>
                    <Link
                        v-if="
                            $page.props.auth.user?.permissions?.includes(
                                'reports.view',
                            )
                        "
                        href="/app/statistics"
                        class="inline-flex items-center gap-1 text-sm font-semibold text-brand hover:underline"
                    >
                        Statistiques médicales
                        <ArrowRight class="size-3.5" />
                    </Link>
                </CardHeader>
                <CardContent>
                    <BarChart
                        :data="patientsTrend"
                        color="var(--viz-current)"
                        :height="200"
                    />
                </CardContent>
            </Card>
            <Card>
                <CardHeader>
                    <CardTitle>Assiduité</CardTitle>
                    <CardDescription>
                        Rendez-vous terminés des 90 derniers jours
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div>
                        <p class="text-4xl font-bold tabular-nums">
                            {{
                                stats.no_show_rate === null
                                    ? '—'
                                    : `${stats.no_show_rate.toLocaleString('fr-DZ')} %`
                            }}
                        </p>
                        <p class="mt-1 text-sm text-muted-foreground">
                            de patients absents sans prévenir
                        </p>
                    </div>
                    <div class="h-2 rounded-full bg-muted">
                        <div
                            class="h-2 rounded-full bg-rose-500"
                            :style="{
                                width: `${Math.min(100, stats.no_show_rate ?? 0)}%`,
                            }"
                        />
                    </div>
                    <p class="text-xs text-muted-foreground">
                        Un taux au-dessus de 10 % justifie des rappels la veille
                        du rendez-vous.
                    </p>
                    <Link
                        href="/app/reminders"
                        class="inline-flex items-center gap-1 text-sm font-semibold text-brand hover:underline"
                    >
                        Relancer les rendez-vous de demain
                        <ArrowRight class="size-3.5" />
                    </Link>
                </CardContent>
            </Card>
        </div>

        <!-- Appointment volume + top prestations -->
        <div class="grid gap-4 lg:grid-cols-3">
            <Card class="lg:col-span-2">
                <CardHeader>
                    <CardTitle>Volume des rendez-vous</CardTitle>
                    <CardDescription>
                        Réservations des 14 derniers jours
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <BarChart :data="appointmentsTrend" color="#59f59b" />
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Prestations principales</CardTitle>
                    <CardDescription>
                        Services les plus souvent réservés
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <HBarChart :data="topPrestations" />
                </CardContent>
            </Card>
        </div>

        <!-- Recent earnings -->
        <Card>
            <CardHeader
                class="flex flex-row items-center justify-between space-y-0"
            >
                <div class="space-y-1.5">
                    <CardTitle>Paiements récents</CardTitle>
                    <CardDescription>
                        Dernières consultations réglées
                    </CardDescription>
                </div>
                <div
                    class="flex size-10 items-center justify-center rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400"
                >
                    <Banknote class="size-5" />
                </div>
            </CardHeader>
            <CardContent>
                <ul v-if="recentPayments.length" class="divide-y divide-border">
                    <li
                        v-for="(payment, index) in recentPayments"
                        :key="`${payment.id}-${index}`"
                        class="flex items-center justify-between gap-4 py-3 first:pt-0 last:pb-0"
                    >
                        <div class="flex min-w-0 items-center gap-3">
                            <div
                                class="flex size-9 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-semibold text-foreground"
                            >
                                {{ initials(payment.patient_name) }}
                            </div>
                            <div class="min-w-0">
                                <p
                                    class="truncate text-sm font-medium text-foreground"
                                >
                                    {{ payment.patient_name }}
                                </p>
                                <p
                                    class="truncate text-xs text-muted-foreground"
                                >
                                    {{ payment.date_label }}
                                    <span v-if="payment.method">
                                        · {{ formatMethod(payment.method) }}
                                    </span>
                                </p>
                            </div>
                        </div>
                        <span
                            class="shrink-0 text-sm font-semibold tabular-nums"
                            :class="
                                payment.amount < 0
                                    ? 'text-rose-600 dark:text-rose-400'
                                    : 'text-emerald-600 dark:text-emerald-400'
                            "
                        >
                            {{ payment.amount < 0 ? '−' : '+'
                            }}{{ formatMoney(Math.abs(payment.amount)) }}
                        </span>
                    </li>
                </ul>
                <p
                    v-else
                    class="py-8 text-center text-sm text-muted-foreground"
                >
                    Aucun paiement enregistré pour le moment.
                </p>
            </CardContent>
        </Card>
    </div>
</template>
