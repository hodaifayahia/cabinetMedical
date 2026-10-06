<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    CalendarClock,
    CalendarDays,
    ChevronRight,
    Droplet,
    FileText,
    FolderOpen,
    GitMerge,
    HeartPulse,
    Mail,
    MapPin,
    Pencil,
    Phone,
    Pill,
    Sparkles,
    Stethoscope,
    UserRound,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import PatientAiAnalysis from '@/components/ai/PatientAiAnalysis.vue';
import PageBackButton from '@/components/PageBackButton.vue';
import FamilyFindings from '@/components/patients/FamilyFindings.vue';
import PatientAvatar from '@/components/patients/PatientAvatar.vue';
import PatientMergeDialog from '@/components/patients/PatientMergeDialog.vue';
import PatientRelatives from '@/components/patients/PatientRelatives.vue';
import PatientSafetyBanner from '@/components/patients/PatientSafetyBanner.vue';
import type { PatientSafetySummary } from '@/components/patients/PatientSafetyBanner.vue';
import PatientVaccinations from '@/components/patients/PatientVaccinations.vue';
import type { VaccinationCard } from '@/components/patients/PatientVaccinations.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogDescription,
    DialogHeader,
    DialogScrollContent,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    formatAge,
    formatDate,
    formatDateTime,
    formatGender,
    relativeDay,
} from '@/lib/patientDisplay';
import { PATIENT_HISTORY_FIELDS } from '@/lib/patientHistory';
import type { FamilyMedicalRelative } from '@/lib/patientHistory';
import type { PatientDetail, PatientOption, PatientOverview } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Patients', href: '/app/patients' }],
    },
});

const props = defineProps<{
    patient: PatientDetail;
    safety: PatientSafetySummary;
    canEditSafety: boolean;
    vaccinations: VaccinationCard;
    overview: PatientOverview;
    canMerge: boolean;
    relatives: FamilyMedicalRelative[];
    relationOptions: PatientOption[];
    canEditRelatives: boolean;
}>();

const page = usePage();
const showMerge = ref(false);
const showAnalysis = ref(false);

const can = (permission: string): boolean =>
    page.props.auth.user?.permissions?.includes(permission) ?? false;

const facts = computed(() =>
    [
        props.patient.gender
            ? { icon: UserRound, text: formatGender(props.patient.gender) }
            : null,
        props.patient.date_of_birth
            ? {
                  icon: CalendarDays,
                  text: `${formatAge(props.patient.date_of_birth)} · né(e) le ${formatDate(props.patient.date_of_birth)}`,
              }
            : null,
        props.patient.blood_group
            ? { icon: Droplet, text: `Groupe ${props.patient.blood_group}` }
            : null,
    ].filter((fact) => fact !== null),
);

const counters = computed(() => [
    {
        label: 'Consultations',
        value: props.overview.counts.consultations,
        icon: Stethoscope,
        tone: 'bg-brand-soft text-brand dark:text-brand-mint',
    },
    {
        label: 'Ordonnances',
        value: props.overview.counts.prescriptions,
        icon: Pill,
        tone: 'bg-violet-100 text-violet-700 dark:bg-violet-900/40 dark:text-violet-300',
    },
    {
        label: 'Documents',
        value: props.overview.counts.documents,
        icon: FolderOpen,
        tone: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
    },
    {
        label: 'ECG',
        value: props.overview.counts.ecgs,
        icon: HeartPulse,
        tone: 'bg-rose-100 text-rose-700 dark:bg-rose-900/40 dark:text-rose-300',
    },
    {
        label: 'Rendez-vous',
        value: props.overview.counts.appointments,
        icon: CalendarClock,
        tone: 'bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300',
    },
]);

const details = computed(() =>
    [
        { label: 'Téléphone secondaire', value: props.patient.secondary_phone },
        { label: 'Adresse', value: props.patient.address },
        {
            label: 'Situation familiale',
            value:
                props.patient.marital_status_label ??
                props.patient.marital_status,
        },
        { label: 'Profession', value: props.patient.profession },
        {
            label: 'Tabagisme',
            value:
                props.patient.smoking_status_label ??
                props.patient.smoking_status,
        },
        { label: 'Orienté par', value: props.patient.referred_by },
        {
            label: 'Contact d’urgence',
            value: [
                props.patient.emergency_contact_name,
                props.patient.emergency_contact_phone,
            ]
                .filter(Boolean)
                .join(' · '),
        },
        {
            label: 'Dossier créé le',
            value: formatDate(props.patient.created_at),
        },
    ].filter((detail) => detail.value),
);

// Every history field is listed, filled or not, so a missing allergy note
// is visible as such. Gyneco-obstetric history is not shown for men unless
// something was written there.
const medicalHistory = computed(() =>
    PATIENT_HISTORY_FIELDS.filter(
        (field) =>
            field.key !== 'antecedents_gyneco' ||
            props.patient.gender !== 'male' ||
            Boolean(props.patient.antecedents_gyneco?.trim()),
    ).map((field) => ({
        key: field.key,
        label: field.label,
        value: props.patient[field.key]?.trim() || null,
    })),
);

const lastVisit = computed(
    () => props.overview.recent_consultations[0] ?? null,
);
</script>

<template>
    <Head :title="props.patient.full_name" />

    <div class="med-page">
        <PageBackButton href="/app/patients" label="Retour aux patients" />

        <!-- Identity header -->
        <section class="med-panel relative overflow-hidden p-5 sm:p-6">
            <div
                class="pointer-events-none absolute inset-x-0 top-0 h-1.5 bg-gradient-to-r from-brand via-brand-mint to-sky-400"
            />
            <div
                class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between"
            >
                <div class="flex min-w-0 items-center gap-4">
                    <PatientAvatar
                        :id="props.patient.id"
                        :name="props.patient.full_name"
                        size="lg"
                    />
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h1
                                class="truncate text-2xl leading-tight font-bold tracking-tight"
                            >
                                {{ props.patient.full_name }}
                            </h1>
                            <span
                                class="rounded-md bg-muted px-2 py-0.5 font-mono text-xs text-muted-foreground"
                                >{{ props.patient.patient_number }}</span
                            >
                        </div>
                        <div
                            class="mt-2 flex flex-wrap gap-x-4 gap-y-1.5 text-sm text-muted-foreground"
                        >
                            <span
                                v-for="fact in facts"
                                :key="fact.text"
                                class="inline-flex items-center gap-1.5"
                            >
                                <component :is="fact.icon" class="size-4" />
                                {{ fact.text }}
                            </span>
                        </div>
                        <div
                            class="mt-1.5 flex flex-wrap gap-x-4 gap-y-1.5 text-sm"
                        >
                            <a
                                v-if="props.patient.phone"
                                :href="`tel:${props.patient.phone}`"
                                class="inline-flex items-center gap-1.5 font-medium text-foreground hover:text-brand hover:underline"
                            >
                                <Phone class="size-4 text-muted-foreground" />
                                {{ props.patient.phone }}
                            </a>
                            <a
                                v-if="props.patient.email"
                                :href="`mailto:${props.patient.email}`"
                                class="inline-flex items-center gap-1.5 text-foreground hover:text-brand hover:underline"
                            >
                                <Mail class="size-4 text-muted-foreground" />
                                {{ props.patient.email }}
                            </a>
                            <span
                                v-if="props.patient.city"
                                class="inline-flex items-center gap-1.5 text-muted-foreground"
                            >
                                <MapPin class="size-4" />
                                {{ props.patient.city }}
                            </span>
                        </div>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2 lg:justify-end">
                    <Button
                        v-if="can('consultations.view')"
                        variant="outline"
                        class="text-brand dark:text-brand-mint"
                        @click="showAnalysis = true"
                    >
                        <Sparkles class="size-4" />
                        Analyse IA
                    </Button>
                    <Button
                        v-if="can('consultations.view')"
                        variant="outline"
                        as-child
                    >
                        <Link
                            :href="`/app/patients/${props.patient.id}/consultation-history`"
                        >
                            <Stethoscope class="size-4" />
                            Historique
                        </Link>
                    </Button>
                    <Button
                        v-if="canMerge"
                        variant="outline"
                        title="Regrouper un doublon de ce patient dans ce dossier"
                        @click="showMerge = true"
                    >
                        <GitMerge class="size-4" />
                        Fusionner
                    </Button>
                    <Button v-if="can('patients.update')" as-child>
                        <Link :href="`/app/patients/${props.patient.id}/edit`">
                            <Pencil class="size-4" />
                            Modifier
                        </Link>
                    </Button>
                </div>
            </div>
        </section>

        <PatientSafetyBanner
            :patient-id="props.patient.id"
            :safety="props.safety"
            :can-edit="props.canEditSafety"
        />

        <!-- Activity at a glance -->
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
            <div
                v-for="counter in counters"
                :key="counter.label"
                class="med-panel flex items-center gap-3 p-4"
            >
                <span
                    class="flex size-10 shrink-0 items-center justify-center rounded-xl"
                    :class="counter.tone"
                >
                    <component :is="counter.icon" class="size-5" />
                </span>
                <div>
                    <p class="text-xl leading-tight font-bold tabular-nums">
                        {{ counter.value }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ counter.label }}
                    </p>
                </div>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <!-- Recent visits -->
            <section class="med-panel p-5 lg:col-span-2">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="text-base font-semibold">
                        Dernières consultations
                    </h2>
                    <Link
                        v-if="
                            can('consultations.view') &&
                            overview.counts.consultations > 0
                        "
                        :href="`/app/patients/${props.patient.id}/consultation-history`"
                        class="inline-flex items-center gap-1 text-sm font-medium text-brand hover:underline dark:text-brand-mint"
                    >
                        Tout voir ({{ overview.counts.consultations }})
                        <ChevronRight class="size-4" />
                    </Link>
                </div>

                <div
                    v-if="overview.recent_consultations.length === 0"
                    class="med-empty py-10"
                >
                    <Stethoscope class="med-empty-icon" />
                    <p class="med-empty-title">Aucune consultation</p>
                    <p class="med-empty-hint">
                        Les visites de ce patient apparaîtront ici.
                    </p>
                </div>

                <ol v-else class="relative mt-4 grid gap-1">
                    <li
                        v-for="(visit, index) in overview.recent_consultations"
                        :key="visit.id"
                        class="relative pl-7"
                    >
                        <span
                            class="absolute top-4 left-[7px] size-3 rounded-full border-2 border-surface ring-2"
                            :class="
                                index === 0
                                    ? 'bg-brand ring-brand/30'
                                    : 'bg-muted-foreground/40 ring-transparent'
                            "
                        />
                        <span
                            v-if="
                                index < overview.recent_consultations.length - 1
                            "
                            class="absolute top-7 bottom-[-12px] left-[12px] w-px bg-border"
                        />
                        <component
                            :is="can('consultations.view') ? Link : 'div'"
                            :href="
                                can('consultations.view')
                                    ? `/app/consultation-history/${visit.id}`
                                    : undefined
                            "
                            class="block rounded-xl px-3 py-2.5 transition hover:bg-muted/50"
                        >
                            <div
                                class="flex flex-wrap items-baseline justify-between gap-x-3"
                            >
                                <p class="font-medium">
                                    {{ visit.motif ?? 'Consultation' }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ formatDate(visit.consulted_at) }} ·
                                    {{ relativeDay(visit.consulted_at) }}
                                </p>
                            </div>
                            <p
                                v-if="visit.diagnostic"
                                class="mt-0.5 line-clamp-2 text-sm text-muted-foreground"
                            >
                                <span class="font-medium text-foreground/80"
                                    >Diagnostic :</span
                                >
                                {{ visit.diagnostic }}
                            </p>
                            <span
                                v-if="visit.status === 'in_progress'"
                                class="mt-1 inline-block rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-medium text-amber-800 dark:bg-amber-900/40 dark:text-amber-200"
                                >En cours</span
                            >
                        </component>
                    </li>
                </ol>
            </section>

            <div class="grid content-start gap-6">
                <!-- Next appointment -->
                <section
                    class="med-panel p-5"
                    :class="
                        overview.next_appointment
                            ? 'border-sky-300/60 bg-sky-50/60 dark:border-sky-800 dark:bg-sky-950/20'
                            : ''
                    "
                >
                    <h2
                        class="flex items-center gap-2 text-sm font-semibold text-muted-foreground"
                    >
                        <CalendarClock class="size-4" />
                        Prochain rendez-vous
                    </h2>
                    <template v-if="overview.next_appointment">
                        <p class="mt-2 text-lg font-semibold capitalize">
                            {{
                                formatDateTime(
                                    overview.next_appointment.starts_at,
                                )
                            }}
                        </p>
                        <p class="text-sm text-sky-700 dark:text-sky-300">
                            {{
                                relativeDay(overview.next_appointment.starts_at)
                            }}
                        </p>
                        <p
                            v-if="overview.next_appointment.reason"
                            class="mt-1 text-sm text-muted-foreground"
                        >
                            {{ overview.next_appointment.reason }}
                        </p>
                    </template>
                    <p v-else class="mt-2 text-sm text-muted-foreground">
                        Aucun rendez-vous prévu.
                        <template v-if="lastVisit">
                            Dernière visite
                            {{ relativeDay(lastVisit.consulted_at) }}.
                        </template>
                    </p>
                </section>

                <!-- Other details -->
                <section class="med-panel p-5">
                    <h2 class="text-sm font-semibold text-muted-foreground">
                        Informations
                    </h2>
                    <dl class="mt-3 grid gap-3">
                        <div
                            v-for="detail in details"
                            :key="detail.label"
                            class="grid gap-0.5"
                        >
                            <dt class="text-xs text-muted-foreground">
                                {{ detail.label }}
                            </dt>
                            <dd class="text-sm font-medium">
                                {{ detail.value }}
                            </dd>
                        </div>
                    </dl>
                    <div v-if="props.patient.notes" class="mt-4 border-t pt-3">
                        <p
                            class="flex items-center gap-1.5 text-xs text-muted-foreground"
                        >
                            <FileText class="size-3.5" /> Notes
                        </p>
                        <p class="mt-1 text-sm whitespace-pre-line">
                            {{ props.patient.notes }}
                        </p>
                    </div>
                    <div
                        v-if="overview.merged.length"
                        class="mt-4 border-t pt-3"
                    >
                        <p
                            class="flex items-center gap-1.5 text-xs text-muted-foreground"
                        >
                            <GitMerge class="size-3.5" /> Dossiers fusionnés ici
                        </p>
                        <ul class="mt-1 grid gap-1 text-sm">
                            <li
                                v-for="merged in overview.merged"
                                :key="merged.patient_number"
                            >
                                <span class="font-mono text-xs">{{
                                    merged.patient_number
                                }}</span>
                                {{ merged.full_name }}
                                <span class="text-xs text-muted-foreground"
                                    >· le
                                    {{ formatDate(merged.merged_at) }}</span
                                >
                            </li>
                        </ul>
                    </div>
                </section>

                <PatientRelatives
                    :patient-id="props.patient.id"
                    :patient-name="props.patient.full_name"
                    :relatives="props.relatives"
                    :relation-options="props.relationOptions"
                    :can-edit="props.canEditRelatives"
                />
            </div>
        </div>

        <!-- Medical history -->
        <section
            class="med-panel p-5"
            aria-labelledby="medical-history-title"
            data-testid="patient-medical-history"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2
                        id="medical-history-title"
                        class="flex items-center gap-2 text-base font-semibold"
                    >
                        <HeartPulse class="size-4 text-rose-600" />
                        Antécédents médicaux du patient
                    </h2>
                    <p class="text-xs text-muted-foreground">
                        Informations médicales complètes, reprises dans chaque
                        consultation.
                    </p>
                </div>
                <Button
                    v-if="can('patients.update')"
                    size="sm"
                    variant="outline"
                    as-child
                >
                    <Link :href="`/app/patients/${props.patient.id}/edit`">
                        <Pencil class="size-4" />
                        Compléter
                    </Link>
                </Button>
            </div>
            <dl class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <div
                    v-for="item in medicalHistory"
                    :key="item.key"
                    class="grid content-start gap-1 rounded-xl border px-3 py-2.5"
                    :class="
                        item.key === 'allergies' && item.value
                            ? 'border-rose-300 bg-rose-50/60 dark:border-rose-500/40 dark:bg-rose-500/10'
                            : ''
                    "
                >
                    <dt class="text-xs font-medium text-muted-foreground">
                        {{ item.label }}
                    </dt>
                    <dd
                        v-if="item.value"
                        class="text-sm font-medium break-words whitespace-pre-line"
                    >
                        {{ item.value }}
                    </dd>
                    <dd v-else class="text-sm text-muted-foreground italic">
                        Non renseigné
                    </dd>
                    <FamilyFindings
                        v-if="item.key === 'antecedents_family'"
                        class="mt-2"
                        :relatives="props.relatives"
                        link-dossiers
                    />
                </div>
            </dl>
        </section>

        <PatientVaccinations
            :patient-id="props.patient.id"
            :card="props.vaccinations"
            :can-edit="props.canEditSafety"
        />

        <PatientMergeDialog
            v-if="canMerge"
            v-model:open="showMerge"
            :primary="{
                id: props.patient.id,
                full_name: props.patient.full_name,
                patient_number: props.patient.patient_number,
            }"
        />

        <Dialog v-model:open="showAnalysis">
            <DialogScrollContent class="sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>{{ props.patient.full_name }}</DialogTitle>
                    <DialogDescription>
                        Dossier {{ props.patient.patient_number }} · synthèse
                        par l’assistant IA
                    </DialogDescription>
                </DialogHeader>
                <PatientAiAnalysis
                    v-if="showAnalysis"
                    :patient-id="props.patient.id"
                />
            </DialogScrollContent>
        </Dialog>
    </div>
</template>
