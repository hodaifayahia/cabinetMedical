<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Pencil, FilePlus } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import PageBackButton from '@/components/PageBackButton.vue';
import { Button } from '@/components/ui/button';
import type {
    EncounterDetail,
    EncounterPatientSummary,
} from '@/types/encounter';

const props = defineProps<{
    patient: EncounterPatientSummary;
    encounter: EncounterDetail;
    amendments: EncounterDetail[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Patients', href: '/app/patients' }],
    },
});

const page = usePage();

const can = (permission: string): boolean =>
    page.props.auth.user?.permissions?.includes(permission) ?? false;

const noteSections = computed(() => [
    {
        label: 'Motif de consultation',
        value: props.encounter.notes.reason_for_visit,
    },
    {
        label: 'Examen clinique',
        value: props.encounter.notes.clinical_examination,
    },
    {
        label: 'Diagnostic et évaluation',
        value: props.encounter.notes.diagnosis_assessment,
    },
    {
        label: 'Plan de traitement',
        value: props.encounter.notes.treatment_plan,
    },
]);

const statusBadge = (status: string): string => {
    const base = 'inline-flex rounded-full px-2 py-0.5 text-xs font-medium';

    switch (status) {
        case 'signed':
            return `${base} bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300`;
        case 'in_progress':
            return `${base} bg-brand-soft text-brand dark:bg-brand-deep dark:text-brand-mint`;
        case 'void':
            return `${base} bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300`;
        default:
            return `${base} bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300`;
    }
};

const statusLabels: Record<string, string> = {
    draft: 'Brouillon',
    in_progress: 'En cours',
    signed: 'Signée',
    void: 'Annulée',
};

const statusLabel = (status: string): string =>
    statusLabels[status] ?? status.replace('_', ' ');

const formatDateTime = (value: string | null): string =>
    value ? new Date(value).toLocaleString('fr-FR') : '—';
</script>

<template>
    <Head title="Consultation" />

    <div class="med-page">
        <section class="med-panel p-6">
            <PageBackButton
                :href="`/app/patients/${props.patient.id}/encounters`"
                label="Retour aux consultations"
                class="mb-4"
            />
            <div
                class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
            >
                <div class="space-y-2">
                    <Heading
                        title="Consultation"
                        :description="`${props.patient.full_name} · Dossier ${props.patient.patient_number}`"
                    />
                    <div
                        class="flex flex-wrap items-center gap-3 text-sm text-muted-foreground"
                    >
                        <span :class="statusBadge(props.encounter.status)">{{
                            statusLabel(props.encounter.status)
                        }}</span>
                        <span v-if="props.encounter.signed_at">
                            Signée par {{ props.encounter.signed_by?.name }} le
                            {{ formatDateTime(props.encounter.signed_at) }}
                        </span>
                        <span v-else>Brouillon — pas encore signé</span>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <Button variant="outline" as-child>
                        <Link
                            :href="`/app/patients/${props.patient.id}/encounters`"
                            >Retour</Link
                        >
                    </Button>
                    <Button
                        v-if="
                            props.encounter.status !== 'signed' &&
                            can('encounters.update')
                        "
                        as-child
                    >
                        <Link
                            :href="`/app/patients/${props.patient.id}/encounters/${props.encounter.id}/edit`"
                        >
                            <Pencil class="size-4" />
                            Modifier
                        </Link>
                    </Button>
                    <Button
                        v-if="
                            props.encounter.status === 'signed' &&
                            can('encounters.amend')
                        "
                        variant="secondary"
                        as-child
                    >
                        <Link
                            :href="`/app/patients/${props.patient.id}/encounters/${props.encounter.id}/amend`"
                        >
                            <FilePlus class="size-4" />
                            Créer un avenant
                        </Link>
                    </Button>
                </div>
            </div>

            <div
                v-if="props.encounter.amendment_reason"
                class="mt-4 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-300"
            >
                Ceci est un avenant. Motif :
                {{ props.encounter.amendment_reason }}
            </div>

            <div class="mt-6 grid gap-4 md:grid-cols-2">
                <div
                    v-for="note in noteSections"
                    :key="note.label"
                    class="rounded-lg border border-sidebar-border/70 p-4 dark:border-sidebar-border"
                >
                    <h3
                        class="text-xs tracking-wide text-muted-foreground uppercase"
                    >
                        {{ note.label }}
                    </h3>
                    <p class="mt-2 text-sm whitespace-pre-line text-foreground">
                        {{ note.value || '—' }}
                    </p>
                </div>
            </div>
        </section>

        <section v-if="props.amendments.length > 0" class="med-panel p-6">
            <h2 class="text-lg font-semibold text-foreground">Avenants</h2>
            <ul class="mt-4 space-y-3">
                <li
                    v-for="amendment in props.amendments"
                    :key="amendment.id"
                    class="flex items-center justify-between rounded-lg border border-sidebar-border/70 p-4 dark:border-sidebar-border"
                >
                    <div class="space-y-1">
                        <p class="text-sm font-medium text-foreground">
                            Avenant ·
                            {{
                                amendment.amendment_reason ??
                                'Aucun motif indiqué'
                            }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            <span :class="statusBadge(amendment.status)">{{
                                statusLabel(amendment.status)
                            }}</span>
                            <span v-if="amendment.signed_at" class="ml-2">
                                Signé le
                                {{ formatDateTime(amendment.signed_at) }}
                            </span>
                        </p>
                    </div>
                    <Button variant="ghost" size="sm" as-child>
                        <Link
                            :href="`/app/patients/${props.patient.id}/encounters/${amendment.id}`"
                            >Voir</Link
                        >
                    </Button>
                </li>
            </ul>
        </section>
    </div>
</template>
