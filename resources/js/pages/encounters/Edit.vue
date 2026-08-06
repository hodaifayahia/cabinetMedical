<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import EncounterForm from '@/components/encounters/EncounterForm.vue';
import Heading from '@/components/Heading.vue';
import PageBackButton from '@/components/PageBackButton.vue';
import type {
    EncounterDetail,
    EncounterPatientSummary,
} from '@/types/encounter';

const props = defineProps<{
    patient: EncounterPatientSummary;
    encounter: EncounterDetail;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Patients', href: '/app/patients' }],
    },
});

const page = usePage();

const canSign = computed<boolean>(
    () =>
        page.props.auth.user?.permissions?.includes('encounters.sign') ?? false,
);

const formatDate = (value: string | null): string =>
    value ? new Date(value).toLocaleDateString('fr-FR') : '—';
</script>

<template>
    <Head title="Modifier la consultation" />

    <div class="med-page">
        <section class="med-panel p-6">
            <PageBackButton
                :href="`/app/patients/${props.patient.id}/encounters/${props.encounter.id}`"
                label="Retour à la consultation"
                class="mb-4"
            />
            <Heading
                title="Modifier la consultation"
                :description="`${props.patient.full_name} · ${formatDate(props.encounter.occurred_at)}`"
            />

            <EncounterForm
                class="mt-6"
                :encounter="props.encounter"
                :submit-url="`/app/patients/${props.patient.id}/encounters/${props.encounter.id}`"
                :back-url="`/app/patients/${props.patient.id}/encounters/${props.encounter.id}`"
                :sign-url="`/app/patients/${props.patient.id}/encounters/${props.encounter.id}/sign`"
                :can-sign="canSign"
            />
        </section>
    </div>
</template>
