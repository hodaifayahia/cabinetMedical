<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    CalendarClock,
    CopyCheck,
    Eye,
    Pencil,
    Phone,
    Plus,
    Search,
    ShieldAlert,
    Sparkles,
    Stethoscope,
    UserPlus,
    Users,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import PatientAiAnalysis from '@/components/ai/PatientAiAnalysis.vue';
import PageHeader from '@/components/PageHeader.vue';
import DuplicatePatientsDialog from '@/components/patients/DuplicatePatientsDialog.vue';
import PatientAvatar from '@/components/patients/PatientAvatar.vue';
import PatientForm from '@/components/patients/PatientForm.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogDescription,
    DialogHeader,
    DialogScrollContent,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    formatAge,
    formatDate,
    formatDateTime,
    formatGender,
    relativeDay,
} from '@/lib/patientDisplay';
import type {
    Paginator,
    PatientIndexStats,
    PatientListItem,
    PatientOption,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Patients', href: '/app/patients' }],
    },
});

const props = defineProps<{
    patients: Paginator<PatientListItem>;
    filters: { search: string };
    genders: PatientOption[];
    bloodGroups: PatientOption[];
    stats: PatientIndexStats;
}>();

const page = usePage();
const search = ref(props.filters.search ?? '');
const showCreate = ref(false);
const showDuplicates = ref(false);
const analysisPatient = ref<PatientListItem | null>(null);

const can = (permission: string): boolean =>
    page.props.auth.user?.permissions?.includes(permission) ?? false;

const submitSearch = () => {
    router.get(
        '/app/patients',
        { search: search.value },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};

const clearSearch = () => {
    search.value = '';
    submitSearch();
};

const open = (patient: PatientListItem) => {
    router.visit(`/app/patients/${patient.id}`);
};

const statCards = computed(() => [
    {
        key: 'total',
        label: 'Patients',
        value: props.stats.total,
        hint: 'dossiers actifs',
        icon: Users,
        tone: 'bg-brand-soft text-brand dark:text-brand-mint',
    },
    {
        key: 'new',
        label: 'Nouveaux ce mois',
        value: props.stats.new_this_month,
        hint: 'dossiers créés',
        icon: UserPlus,
        tone: 'bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300',
    },
    {
        key: 'seen',
        label: 'Vus ce mois',
        value: props.stats.seen_this_month,
        hint: 'patients consultés',
        icon: Stethoscope,
        tone: 'bg-violet-100 text-violet-700 dark:bg-violet-900/40 dark:text-violet-300',
    },
]);

const paginationLabel = (label: string): string => {
    if (label.includes('Previous')) {
        return '« Précédent';
    }

    if (label.includes('Next')) {
        return 'Suivant »';
    }

    return label;
};
</script>

<template>
    <Head title="Patients" />

    <div class="med-page">
        <PageHeader
            title="Patients"
            description="Rechercher, enregistrer et gérer les dossiers patients."
        >
            <template #actions>
                <Button
                    v-if="stats.duplicates !== null"
                    variant="outline"
                    @click="showDuplicates = true"
                >
                    <CopyCheck class="size-4" />
                    Doublons
                    <span
                        v-if="stats.duplicates > 0"
                        class="rounded-full bg-amber-500 px-1.5 text-xs leading-5 font-semibold text-white"
                        >{{ stats.duplicates }}</span
                    >
                </Button>
                <Button
                    v-if="can('patients.create')"
                    @click="showCreate = true"
                >
                    <Plus class="size-4" />
                    Ajouter un patient
                </Button>
            </template>
        </PageHeader>

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <div
                v-for="card in statCards"
                :key="card.key"
                class="med-panel flex items-center gap-4 p-4"
            >
                <span
                    class="flex size-11 shrink-0 items-center justify-center rounded-xl"
                    :class="card.tone"
                >
                    <component :is="card.icon" class="size-5" />
                </span>
                <div class="min-w-0">
                    <p class="text-sm text-muted-foreground">
                        {{ card.label }}
                    </p>
                    <p class="text-2xl leading-tight font-bold tabular-nums">
                        {{ card.value.toLocaleString('fr-DZ') }}
                    </p>
                    <p class="text-xs text-muted-foreground">{{ card.hint }}</p>
                </div>
            </div>

            <button
                v-if="stats.duplicates !== null"
                type="button"
                class="med-panel flex items-center gap-4 p-4 text-left transition hover:-translate-y-0.5 hover:shadow-md"
                @click="showDuplicates = true"
            >
                <span
                    class="flex size-11 shrink-0 items-center justify-center rounded-xl"
                    :class="
                        stats.duplicates > 0
                            ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300'
                            : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300'
                    "
                >
                    <CopyCheck class="size-5" />
                </span>
                <div class="min-w-0">
                    <p class="text-sm text-muted-foreground">
                        Doublons possibles
                    </p>
                    <p class="text-2xl leading-tight font-bold tabular-nums">
                        {{ stats.duplicates }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{
                            stats.duplicates > 0
                                ? 'Vérifier et fusionner →'
                                : 'Aucun à traiter'
                        }}
                    </p>
                </div>
            </button>
        </div>

        <section class="med-panel overflow-hidden">
            <div
                class="flex flex-col gap-3 border-b p-4 sm:flex-row sm:items-center sm:justify-between"
            >
                <form
                    class="flex w-full max-w-2xl gap-2"
                    @submit.prevent="submitSearch"
                >
                    <div class="relative min-w-0 flex-1">
                        <Search
                            class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                        />
                        <Input
                            v-model="search"
                            type="search"
                            class="pr-9 pl-9"
                            placeholder="Nom, numéro de dossier, téléphone ou e-mail"
                            aria-label="Rechercher des patients"
                        />
                        <button
                            v-if="filters.search"
                            type="button"
                            class="absolute top-1/2 right-2 flex size-6 -translate-y-1/2 items-center justify-center rounded-md text-muted-foreground hover:bg-muted hover:text-foreground"
                            aria-label="Effacer la recherche"
                            @click="clearSearch"
                        >
                            <X class="size-3.5" />
                        </button>
                    </div>
                    <Button type="submit" variant="secondary">
                        Rechercher
                    </Button>
                </form>
                <p class="text-sm whitespace-nowrap text-muted-foreground">
                    <template v-if="filters.search">
                        {{ patients.total }} résultat{{
                            patients.total > 1 ? 's' : ''
                        }}
                        pour « {{ filters.search }} »
                    </template>
                    <template v-else>
                        {{ patients.total }} patient{{
                            patients.total > 1 ? 's' : ''
                        }}
                    </template>
                </p>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead
                        class="bg-muted/40 text-left text-xs tracking-wide text-muted-foreground uppercase"
                    >
                        <tr>
                            <th class="px-4 py-3 font-medium">Patient</th>
                            <th class="px-4 py-3 font-medium">Contact</th>
                            <th class="px-4 py-3 font-medium">
                                Dernière visite
                            </th>
                            <th class="px-4 py-3 font-medium">Prochain RDV</th>
                            <th class="px-4 py-3 text-right font-medium">
                                Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border/70">
                        <tr v-if="patients.data.length === 0">
                            <td colspan="5">
                                <div class="med-empty">
                                    <Users class="med-empty-icon" />
                                    <p class="med-empty-title">
                                        Aucun patient trouvé
                                    </p>
                                    <p class="med-empty-hint">
                                        Ajustez votre recherche ou enregistrez
                                        un nouveau dossier patient.
                                    </p>
                                    <Button
                                        v-if="can('patients.create')"
                                        class="mt-3"
                                        size="sm"
                                        @click="showCreate = true"
                                    >
                                        <Plus class="size-4" />
                                        Ajouter un patient
                                    </Button>
                                </div>
                            </td>
                        </tr>
                        <tr
                            v-for="patient in patients.data"
                            :key="patient.id"
                            class="group cursor-pointer bg-surface transition-colors hover:bg-brand-soft/40"
                            @click="open(patient)"
                        >
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <PatientAvatar
                                        :id="patient.id"
                                        :name="patient.full_name"
                                    />
                                    <div class="min-w-0">
                                        <p
                                            class="flex items-center gap-2 font-semibold text-foreground group-hover:text-brand dark:group-hover:text-brand-mint"
                                        >
                                            <span class="truncate">{{
                                                patient.full_name
                                            }}</span>
                                            <span
                                                v-if="patient.alerts_count > 0"
                                                class="inline-flex items-center gap-0.5 rounded-full bg-red-100 px-1.5 py-0.5 text-[11px] font-semibold text-red-700 dark:bg-red-900/40 dark:text-red-300"
                                                :title="`${patient.alerts_count} alerte(s) : allergies, pathologies ou traitements`"
                                            >
                                                <ShieldAlert class="size-3" />
                                                {{ patient.alerts_count }}
                                            </span>
                                        </p>
                                        <p
                                            class="text-xs text-muted-foreground"
                                        >
                                            <span class="font-mono">{{
                                                patient.patient_number
                                            }}</span>
                                            <template
                                                v-if="
                                                    patient.gender ||
                                                    patient.date_of_birth
                                                "
                                            >
                                                ·
                                                {{
                                                    [
                                                        patient.gender
                                                            ? formatGender(
                                                                  patient.gender,
                                                              )
                                                            : null,
                                                        patient.date_of_birth
                                                            ? formatAge(
                                                                  patient.date_of_birth,
                                                              )
                                                            : null,
                                                    ]
                                                        .filter(Boolean)
                                                        .join(', ')
                                                }}
                                            </template>
                                        </p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <a
                                    v-if="patient.phone"
                                    :href="`tel:${patient.phone}`"
                                    class="inline-flex items-center gap-1.5 text-foreground hover:text-brand hover:underline"
                                    @click.stop
                                >
                                    <Phone
                                        class="size-3.5 text-muted-foreground"
                                    />
                                    {{ patient.phone }}
                                </a>
                                <span v-else class="text-muted-foreground"
                                    >—</span
                                >
                                <p
                                    v-if="patient.city"
                                    class="text-xs text-muted-foreground"
                                >
                                    {{ patient.city }}
                                </p>
                            </td>
                            <td class="px-4 py-3">
                                <template v-if="patient.last_visit_at">
                                    <p class="text-foreground">
                                        {{ relativeDay(patient.last_visit_at) }}
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        {{ formatDate(patient.last_visit_at) }}
                                        · {{ patient.visits_count }} visite{{
                                            patient.visits_count > 1 ? 's' : ''
                                        }}
                                    </p>
                                </template>
                                <span
                                    v-else
                                    class="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground"
                                    >Jamais consulté</span
                                >
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    v-if="patient.next_appointment_at"
                                    class="inline-flex items-center gap-1.5 rounded-full bg-sky-100 px-2.5 py-1 text-xs font-medium text-sky-800 dark:bg-sky-900/40 dark:text-sky-200"
                                    :title="
                                        formatDateTime(
                                            patient.next_appointment_at,
                                        )
                                    "
                                >
                                    <CalendarClock class="size-3.5" />
                                    {{
                                        relativeDay(patient.next_appointment_at)
                                    }}
                                </span>
                                <span v-else class="text-muted-foreground"
                                    >—</span
                                >
                            </td>
                            <td class="px-4 py-3" @click.stop>
                                <div
                                    class="flex items-center justify-end gap-1"
                                >
                                    <Button
                                        v-if="can('consultations.view')"
                                        variant="ghost"
                                        size="icon-sm"
                                        class="text-brand hover:bg-brand-soft hover:text-brand dark:text-brand-mint"
                                        :aria-label="`Analyse IA de ${patient.full_name}`"
                                        title="Analyse IA du dossier"
                                        @click="analysisPatient = patient"
                                    >
                                        <Sparkles class="size-4" />
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="icon-sm"
                                        as-child
                                        title="Ouvrir le dossier"
                                    >
                                        <Link
                                            :href="`/app/patients/${patient.id}`"
                                            :aria-label="`Voir ${patient.full_name}`"
                                        >
                                            <Eye class="size-4" />
                                        </Link>
                                    </Button>
                                    <Button
                                        v-if="can('consultations.view')"
                                        variant="ghost"
                                        size="icon-sm"
                                        as-child
                                        title="Historique des consultations"
                                    >
                                        <Link
                                            :href="`/app/patients/${patient.id}/consultation-history`"
                                            :aria-label="`Historique des consultations de ${patient.full_name}`"
                                        >
                                            <Stethoscope class="size-4" />
                                        </Link>
                                    </Button>
                                    <Button
                                        v-if="can('patients.update')"
                                        variant="ghost"
                                        size="icon-sm"
                                        as-child
                                        title="Modifier"
                                    >
                                        <Link
                                            :href="`/app/patients/${patient.id}/edit`"
                                            :aria-label="`Modifier ${patient.full_name}`"
                                        >
                                            <Pencil class="size-4" />
                                        </Link>
                                    </Button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div
                v-if="patients.last_page > 1"
                class="flex flex-col gap-3 border-t p-4 sm:flex-row sm:items-center sm:justify-between"
            >
                <p class="text-sm text-muted-foreground">
                    {{ patients.from ?? 0 }}–{{ patients.to ?? 0 }} sur
                    {{ patients.total }}
                </p>

                <div class="flex flex-wrap items-center gap-1.5">
                    <Button
                        v-for="link in patients.links"
                        :key="link.label"
                        :variant="link.active ? 'default' : 'outline'"
                        size="sm"
                        :disabled="!link.url"
                        as-child
                    >
                        <Link v-if="link.url" :href="link.url" preserve-scroll>
                            <span>{{ paginationLabel(link.label) }}</span>
                        </Link>
                        <span v-else>{{ paginationLabel(link.label) }}</span>
                    </Button>
                </div>
            </div>
        </section>

        <Dialog v-model:open="showCreate">
            <DialogScrollContent class="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Nouveau patient</DialogTitle>
                    <DialogDescription
                        >Créer un nouveau dossier patient.</DialogDescription
                    >
                </DialogHeader>

                <PatientForm
                    :genders="genders"
                    :blood-groups="bloodGroups"
                    method="post"
                    submit-url="/app/patients"
                    submit-label="Créer le patient"
                    @success="showCreate = false"
                    @cancel="showCreate = false"
                />
            </DialogScrollContent>
        </Dialog>

        <DuplicatePatientsDialog
            v-if="stats.duplicates !== null"
            v-model:open="showDuplicates"
        />

        <Dialog
            :open="analysisPatient !== null"
            @update:open="(open) => !open && (analysisPatient = null)"
        >
            <DialogScrollContent class="sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>{{ analysisPatient?.full_name }}</DialogTitle>
                    <DialogDescription>
                        Dossier {{ analysisPatient?.patient_number }} · synthèse
                        par l’assistant IA
                    </DialogDescription>
                </DialogHeader>

                <PatientAiAnalysis
                    v-if="analysisPatient"
                    :patient-id="analysisPatient.id"
                />
            </DialogScrollContent>
        </Dialog>
    </div>
</template>
