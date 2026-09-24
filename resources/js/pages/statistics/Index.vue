<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Activity, Stethoscope, Tags, UserPlus, Users } from '@lucide/vue';
import { computed, ref } from 'vue';
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

type Row = { label: string; value: number };

const props = defineProps<{
    period: { year: number; month: number | null; from: string; to: string };
    summary: {
        consultations: number;
        patients: number;
        new_patients: number;
        coded_share: number | null;
    };
    monthly: Row[];
    topDiagnoses: {
        code: string;
        label: string;
        value: number;
        patients: number;
    }[];
    chapters: Row[];
    demographics: { ages: Row[]; sexes: Row[]; cities: Row[] };
    chronic: Row[];
    totalPatients: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Statistiques', href: '/app/statistics' }],
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

const year = ref(props.period.year);
const month = ref<number | ''>(props.period.month ?? '');
const years = computed(() => {
    const current = new Date().getFullYear();

    return Array.from({ length: 6 }, (_, index) => current - index);
});

const apply = () => {
    router.get(
        '/app/statistics',
        month.value === ''
            ? { year: year.value }
            : { year: year.value, month: month.value },
        { preserveScroll: true },
    );
};

const formatNumber = (value: number): string =>
    new Intl.NumberFormat('fr-DZ').format(value);

// Fixed order, validated categorical palette (colour follows the category).
const sexSlices = computed(() =>
    props.demographics.sexes
        .filter((row) => row.value > 0)
        .map((row) => ({
            ...row,
            color:
                row.label === 'Femmes'
                    ? 'var(--viz-cat-5)'
                    : row.label === 'Hommes'
                      ? 'var(--viz-cat-1)'
                      : 'var(--viz-cat-6)',
        })),
);

const periodLabel = computed(() =>
    props.period.month
        ? `${MONTHS[props.period.month - 1]} ${props.period.year}`
        : `Année ${props.period.year}`,
);

const tiles = computed(() => [
    {
        label: 'Consultations',
        value: formatNumber(props.summary.consultations),
        icon: Stethoscope,
    },
    {
        label: 'Patients vus',
        value: formatNumber(props.summary.patients),
        icon: Users,
    },
    {
        label: 'Nouveaux dossiers',
        value: formatNumber(props.summary.new_patients),
        icon: UserPlus,
    },
    {
        label: 'Consultations codées CIM-10',
        value:
            props.summary.coded_share === null
                ? '—'
                : `${props.summary.coded_share.toLocaleString('fr-DZ')} %`,
        icon: Tags,
    },
]);
</script>

<template>
    <Head title="Statistiques médicales" />

    <div class="med-page">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1
                    class="flex items-center gap-3 text-[2rem] leading-none font-bold tracking-tight text-[#111827] sm:text-[2.2rem] dark:text-slate-50"
                >
                    <Activity class="size-7 text-brand" />
                    Statistiques médicales
                </h1>
                <div class="mt-3 h-1 w-20 rounded-full bg-brand" />
                <p class="mt-3 text-sm text-muted-foreground">
                    Ce que vous soignez et qui sont vos patients ·
                    {{ periodLabel }}
                </p>
            </div>
            <div class="flex items-center gap-2">
                <label class="sr-only" for="stats-year">Année</label>
                <select
                    id="stats-year"
                    v-model="year"
                    class="h-10 rounded-xl border border-input bg-background px-3 text-sm shadow-sm"
                    @change="apply"
                >
                    <option v-for="item in years" :key="item" :value="item">
                        {{ item }}
                    </option>
                </select>
                <label class="sr-only" for="stats-month">Mois</label>
                <select
                    id="stats-month"
                    v-model="month"
                    class="h-10 rounded-xl border border-input bg-background px-3 text-sm shadow-sm"
                    @change="apply"
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
            </div>
        </header>

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <article
                v-for="tile in tiles"
                :key="tile.label"
                class="med-panel flex items-start justify-between gap-3 p-5"
            >
                <div>
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
                    class="flex size-10 items-center justify-center rounded-xl bg-brand-soft text-brand"
                >
                    <component :is="tile.icon" class="size-5" />
                </span>
            </article>
        </section>

        <section class="grid gap-4 lg:grid-cols-3">
            <Card class="lg:col-span-2">
                <CardHeader>
                    <CardTitle>Diagnostics les plus fréquents</CardTitle>
                    <CardDescription>
                        Codes CIM-10 posés en consultation
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div v-if="topDiagnoses.length" class="med-table-wrap">
                        <table class="med-table">
                            <thead>
                                <tr>
                                    <th class="px-4 py-2 font-medium">Code</th>
                                    <th class="px-4 py-2 font-medium">
                                        Diagnostic
                                    </th>
                                    <th
                                        class="px-4 py-2 text-right font-medium"
                                    >
                                        Consultations
                                    </th>
                                    <th
                                        class="px-4 py-2 text-right font-medium"
                                    >
                                        Patients
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="row in topDiagnoses"
                                    :key="row.code"
                                    class="bg-background"
                                >
                                    <td
                                        class="px-4 py-2 font-mono text-xs font-bold text-amber-700 dark:text-amber-300"
                                    >
                                        {{ row.code }}
                                    </td>
                                    <td class="px-4 py-2">{{ row.label }}</td>
                                    <td
                                        class="px-4 py-2 text-right font-semibold tabular-nums"
                                    >
                                        {{ row.value }}
                                    </td>
                                    <td
                                        class="px-4 py-2 text-right tabular-nums"
                                    >
                                        {{ row.patients }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p
                        v-else
                        class="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground"
                    >
                        Aucun diagnostic codé sur la période. Codez vos
                        diagnostics dans la consultation (champ « Coder le
                        diagnostic ») pour obtenir ces statistiques.
                    </p>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Par grande catégorie</CardTitle>
                    <CardDescription>Chapitres CIM-10</CardDescription>
                </CardHeader>
                <CardContent>
                    <HBarChart v-if="chapters.length" :data="chapters" />
                    <p
                        v-else
                        class="py-8 text-center text-sm text-muted-foreground"
                    >
                        Pas encore de données.
                    </p>
                </CardContent>
            </Card>
        </section>

        <Card>
            <CardHeader>
                <CardTitle
                    >Consultations par mois · {{ period.year }}</CardTitle
                >
            </CardHeader>
            <CardContent>
                <BarChart
                    :data="monthly"
                    color="var(--viz-current)"
                    :height="220"
                />
            </CardContent>
        </Card>

        <section class="grid gap-4 lg:grid-cols-3">
            <Card>
                <CardHeader>
                    <CardTitle>Sexe des patients vus</CardTitle>
                </CardHeader>
                <CardContent class="flex flex-col items-center gap-4">
                    <DonutChart
                        v-if="sexSlices.length"
                        :data="sexSlices"
                        :size="170"
                        :thickness="24"
                        :center-value="formatNumber(summary.patients)"
                        center-label="Patients"
                    />
                    <ul class="w-full space-y-1.5 text-sm">
                        <li
                            v-for="row in sexSlices"
                            :key="row.label"
                            class="flex items-center justify-between"
                        >
                            <span class="flex items-center gap-2">
                                <span
                                    class="size-2.5 rounded-full"
                                    :style="{ background: row.color }"
                                />
                                {{ row.label }}
                            </span>
                            <span class="font-semibold tabular-nums">{{
                                row.value
                            }}</span>
                        </li>
                    </ul>
                </CardContent>
            </Card>
            <Card>
                <CardHeader>
                    <CardTitle>Tranches d’âge</CardTitle>
                </CardHeader>
                <CardContent>
                    <HBarChart :data="demographics.ages" />
                </CardContent>
            </Card>
            <Card>
                <CardHeader>
                    <CardTitle>Communes des patients</CardTitle>
                </CardHeader>
                <CardContent>
                    <HBarChart
                        v-if="demographics.cities.length"
                        :data="demographics.cities"
                    />
                    <p
                        v-else
                        class="py-8 text-center text-sm text-muted-foreground"
                    >
                        Ville non renseignée dans les dossiers.
                    </p>
                </CardContent>
            </Card>
        </section>

        <Card>
            <CardHeader>
                <CardTitle>Maladies chroniques suivies</CardTitle>
                <CardDescription>
                    Patients ayant la maladie dans leur fiche de sécurité, sur
                    {{ formatNumber(totalPatients) }} dossiers
                </CardDescription>
            </CardHeader>
            <CardContent>
                <HBarChart v-if="chronic.length" :data="chronic" />
                <p
                    v-else
                    class="py-6 text-center text-sm text-muted-foreground"
                >
                    Aucune maladie chronique renseignée. Ajoutez-les depuis la
                    fiche patient (bouton « Gérer »).
                </p>
            </CardContent>
        </Card>
    </div>
</template>
