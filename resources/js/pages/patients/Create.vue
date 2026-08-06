<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import PageBackButton from '@/components/PageBackButton.vue';
import PatientForm from '@/components/patients/PatientForm.vue';
import type { PatientOption } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Patients', href: '/app/patients' },
            { title: 'Nouveau patient', href: '/app/patients/create' },
        ],
    },
});

defineProps<{
    genders: PatientOption[];
    bloodGroups: PatientOption[];
}>();
</script>

<template>
    <Head title="Nouveau patient" />

    <div class="med-page">
        <section class="med-panel p-6">
            <PageBackButton
                href="/app/patients"
                label="Retour aux patients"
                class="mb-4"
            />
            <Heading
                title="Nouveau patient"
                description="Créer un nouveau dossier patient."
            />

            <PatientForm
                class="mt-6"
                :genders="genders"
                :blood-groups="bloodGroups"
                method="post"
                submit-url="/app/patients"
                submit-label="Créer le patient"
                cancel-url="/app/patients"
            />
        </section>
    </div>
</template>
