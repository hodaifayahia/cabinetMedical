<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    Banknote,
    Building2,
    CalendarCheck,
    FileText,
    Stethoscope,
    TrendingDown,
    TrendingUp,
    Users,
    Wallet,
} from '@lucide/vue';
import { computed } from 'vue';
import AreaChart from '@/components/charts/AreaChart.vue';
import BarChart from '@/components/charts/BarChart.vue';
import DonutChart from '@/components/charts/DonutChart.vue';
import HBarChart from '@/components/charts/HBarChart.vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
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

const props = defineProps<{
    currency: string;
    stats: {
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
    profile: {
        welcome_name: string | null;
        clinic_name: string;
        doctor_name: string | null;
        specialty: string | null;
        professional_identifier: string | null;
        prescriptions_total: number;
    };
}>();

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
        accent: 'bg-blue-500/10 text-blue-600 dark:text-blue-400',
        change: null,
        footnote: `${formatNumber(props.stats.appointments_this_month)} ce mois-ci`,
    },
    {
        key: 'prestations',
        label: 'Consultations',
        value: formatNumber(props.stats.consultations_total),
        icon: Stethoscope,
        accent: 'bg-violet-500/10 text-violet-600 dark:text-violet-400',
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
        change: null,
        footnote: 'Dossiers enregistrés',
    },
]);

const statusSlices = computed(() =>
    props.appointmentsByStatus.map((slice) => ({
        label: slice.label,
        value: slice.count,
        color: slice.color,
    })),
);

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
        <div>
            <h1
                class="text-[2rem] leading-none font-bold tracking-tight text-[#111827] sm:text-[2.2rem] dark:text-slate-50"
            >
                Tableau de bord
            </h1>
            <div class="mt-3 h-1 w-20 rounded-full bg-[#e2a719]" />
            <p class="mt-3 text-sm text-muted-foreground">
                Vue d’ensemble en temps réel des recettes, rendez-vous et de
                l’activité du cabinet.
            </p>
        </div>

        <div
            class="grid gap-4 xl:grid-cols-[minmax(0,1.8fr)_minmax(300px,0.8fr)]"
        >
            <section
                class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-blue-800 via-blue-700 to-cyan-600 p-6 text-white shadow-lg shadow-blue-900/10"
            >
                <div
                    class="absolute -top-20 -right-16 size-64 rounded-full bg-white/10 blur-2xl"
                />
                <div
                    class="absolute -bottom-24 left-1/3 size-56 rounded-full bg-cyan-300/15 blur-3xl"
                />
                <div class="relative">
                    <div
                        class="flex items-center gap-2 text-sm font-medium text-blue-100"
                    >
                        <Building2 class="size-4" />
                        {{ profile.clinic_name }}
                    </div>
                    <h2
                        class="mt-5 text-2xl font-bold tracking-tight sm:text-3xl"
                    >
                        Bienvenue, {{ profile.welcome_name }}
                    </h2>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-blue-100">
                        Retrouvez les rendez-vous du jour, l’activité des
                        patients et les paiements depuis un espace de travail
                        unique.
                    </p>
                    <p
                        class="mt-4 text-xs font-semibold tracking-wide text-cyan-100 uppercase"
                    >
                        {{ todayLabel }}
                    </p>
                    <div class="mt-6 flex flex-wrap gap-2">
                        <Link
                            href="/app/appointments"
                            class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-white px-5 text-sm font-semibold whitespace-nowrap text-blue-700 shadow-sm transition hover:-translate-y-0.5 hover:bg-blue-50 focus-visible:ring-2 focus-visible:ring-white/70"
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
                    </div>
                </div>
            </section>

            <section class="med-panel p-5">
                <div class="flex items-center gap-3">
                    <div
                        class="flex size-14 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-blue-600 to-cyan-500 text-base font-bold text-white"
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
                        <p class="truncate text-sm font-medium text-blue-600">
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

        <!-- KPI cards -->
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <Card v-for="kpi in kpis" :key="kpi.key">
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
            <Card class="lg:col-span-2">
                <CardHeader>
                    <CardTitle>Recettes</CardTitle>
                    <CardDescription>
                        Consultations réglées sur les 6 derniers mois
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <AreaChart
                        :data="revenueTrend"
                        color="#10b981"
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
                    <BarChart :data="appointmentsTrend" color="#3b82f6" />
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
                        v-for="payment in recentPayments"
                        :key="payment.id"
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
                            class="shrink-0 text-sm font-semibold text-emerald-600 tabular-nums dark:text-emerald-400"
                        >
                            +{{ formatMoney(payment.amount) }}
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
