<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PageBackButton from '@/components/PageBackButton.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type {
    EncounterDetail,
    EncounterPatientSummary,
} from '@/types/encounter';

const props = defineProps<{
    patient: EncounterPatientSummary;
    originalEncounter: EncounterDetail;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Patients', href: '/app/patients' }],
    },
});

const sections = [
    { key: 'reason_for_visit', label: 'Motif de consultation' },
    { key: 'clinical_examination', label: 'Examen clinique' },
    { key: 'diagnosis_assessment', label: 'Diagnostic et évaluation' },
    { key: 'treatment_plan', label: 'Plan de traitement' },
] as const;

const form = useForm({
    amendment_reason: '',
    reason_for_visit: '',
    clinical_examination: '',
    diagnosis_assessment: '',
    treatment_plan: '',
});

const formatDate = (value: string | null): string =>
    value ? new Date(value).toLocaleDateString('fr-FR') : '—';

const backUrl = computed<string>(
    () =>
        `/app/patients/${props.patient.id}/encounters/${props.originalEncounter.id}`,
);

const submit = () => {
    form.post(`${backUrl.value}/amend`, { preserveScroll: true });
};
</script>

<template>
    <Head title="Créer un avenant" />

    <div class="med-page">
        <section class="med-panel p-6">
            <PageBackButton
                :href="backUrl"
                label="Retour à la consultation"
                class="mb-4"
            />
            <Heading
                title="Créer un avenant"
                :description="`${props.patient.full_name} · Consultation du ${formatDate(props.originalEncounter.occurred_at)}`"
            />

            <form class="mt-6 space-y-8" @submit.prevent="submit">
                <div class="grid max-w-2xl gap-2">
                    <Label for="amendment_reason">Motif de l’avenant</Label>
                    <Textarea
                        id="amendment_reason"
                        v-model="form.amendment_reason"
                        placeholder="Pourquoi cet avenant est-il nécessaire ? (précision, correction, information manquante…)"
                        class="min-h-[100px]"
                        required
                    />
                    <InputError :message="form.errors.amendment_reason" />
                </div>

                <div
                    class="rounded-lg border border-sidebar-border/70 bg-muted/30 p-4 dark:border-sidebar-border"
                >
                    <h3
                        class="text-xs tracking-wide text-muted-foreground uppercase"
                    >
                        Consultation d’origine (référence)
                    </h3>
                    <dl class="mt-3 grid gap-4 md:grid-cols-2">
                        <div v-for="section in sections" :key="section.key">
                            <dt
                                class="text-xs font-medium text-muted-foreground"
                            >
                                {{ section.label }}
                            </dt>
                            <dd
                                class="mt-1 text-sm whitespace-pre-line text-foreground"
                            >
                                {{
                                    props.originalEncounter.notes[
                                        section.key
                                    ] || '—'
                                }}
                            </dd>
                        </div>
                    </dl>
                </div>

                <div>
                    <h3 class="text-sm font-medium text-foreground">
                        Corrections
                    </h3>
                    <p class="text-sm text-muted-foreground">
                        Laissez un champ vide pour conserver le texte d’origine.
                    </p>
                    <div class="mt-4 grid gap-6 md:grid-cols-2">
                        <div
                            v-for="section in sections"
                            :key="section.key"
                            class="grid gap-2"
                        >
                            <Label :for="`correct_${section.key}`">{{
                                section.label
                            }}</Label>
                            <Textarea
                                :id="`correct_${section.key}`"
                                v-model="form[section.key]"
                                placeholder="Laisser vide pour conserver le texte d’origine…"
                                class="min-h-[150px]"
                            />
                            <InputError :message="form.errors[section.key]" />
                        </div>
                    </div>
                </div>

                <div
                    class="flex items-center gap-3 border-t border-sidebar-border/70 pt-4 dark:border-sidebar-border"
                >
                    <Button type="submit" :disabled="form.processing">
                        {{ form.processing ? 'Création…' : 'Créer l’avenant' }}
                    </Button>
                    <Button variant="outline" as-child>
                        <Link :href="backUrl">Annuler</Link>
                    </Button>
                </div>
            </form>
        </section>
    </div>
</template>
