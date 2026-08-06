<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PageBackButton from '@/components/PageBackButton.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { EncounterPatientSummary } from '@/types/encounter';

const props = defineProps<{
    patient: EncounterPatientSummary;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Patients', href: '/app/patients' }],
    },
});

const today = computed<string>(() => new Date().toISOString().split('T')[0]);

const form = useForm({
    occurred_at: today.value,
});

const submit = () => {
    form.post(`/app/patients/${props.patient.id}/encounters`, {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Nouvelle consultation" />

    <div class="med-page">
        <section class="med-panel p-6">
            <PageBackButton
                :href="`/app/patients/${props.patient.id}/encounters`"
                label="Retour aux consultations"
                class="mb-4"
            />
            <Heading
                title="Nouvelle consultation"
                :description="`${props.patient.full_name} · Dossier ${props.patient.patient_number}`"
            />

            <form class="mt-6 max-w-md space-y-6" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="occurred_at">Date de consultation</Label>
                    <Input
                        id="occurred_at"
                        v-model="form.occurred_at"
                        type="date"
                        :max="today"
                        required
                    />
                    <InputError :message="form.errors.occurred_at" />
                </div>

                <div class="flex items-center gap-3">
                    <Button type="submit" :disabled="form.processing">
                        {{
                            form.processing
                                ? 'Création…'
                                : 'Créer la consultation'
                        }}
                    </Button>
                    <Button variant="outline" as-child>
                        <Link
                            :href="`/app/patients/${props.patient.id}/encounters`"
                            >Annuler</Link
                        >
                    </Button>
                </div>
            </form>
        </section>
    </div>
</template>
