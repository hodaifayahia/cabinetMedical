<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import PageBackButton from '@/components/PageBackButton.vue';
import PatientForm from '@/components/patients/PatientForm.vue';
import type { PatientDetail, PatientOption } from '@/types';

const props = defineProps<{
    patient: PatientDetail;
    genders: PatientOption[];
    bloodGroups: PatientOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Patients', href: '/app/patients' }],
    },
});
</script>

<template>
    <Head :title="`Modifier ${props.patient.full_name}`" />

    <div class="med-page">
        <section class="med-panel p-6">
            <PageBackButton
                :href="`/app/patients/${props.patient.id}`"
                label="Retour au patient"
                class="mb-4"
            />
            <Heading
                :title="`Modifier ${props.patient.full_name}`"
                :description="`Dossier ${props.patient.patient_number}`"
            />

            <PatientForm
                class="mt-6"
                :patient="props.patient"
                :genders="genders"
                :blood-groups="bloodGroups"
                method="put"
                :submit-url="`/app/patients/${props.patient.id}`"
                submit-label="Enregistrer les modifications"
                :cancel-url="`/app/patients/${props.patient.id}`"
            />
        </section>
    </div>
</template>
