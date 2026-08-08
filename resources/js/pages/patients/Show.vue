<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { usePage } from '@inertiajs/vue3';
import { Pencil, Stethoscope } from '@lucide/vue';
import { computed } from 'vue';
import PageBackButton from '@/components/PageBackButton.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import type { PatientDetail } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Patients', href: '/app/patients' }],
    },
});

const props = defineProps<{
    patient: PatientDetail;
}>();

const page = usePage();

const can = (permission: string): boolean =>
    page.props.auth.user?.permissions?.includes(permission) ?? false;

const age = computed<string>(() => {
    if (!props.patient.date_of_birth) {
        return '—';
    }

    const birth = new Date(props.patient.date_of_birth);
    const now = new Date();
    let years = now.getFullYear() - birth.getFullYear();
    const monthDelta = now.getMonth() - birth.getMonth();

    if (
        monthDelta < 0 ||
        (monthDelta === 0 && now.getDate() < birth.getDate())
    ) {
        years -= 1;
    }

    return `${years} an${years > 1 ? 's' : ''}`;
});

const genderLabels: Record<string, string> = {
    female: 'Femme',
    femme: 'Femme',
    male: 'Homme',
    homme: 'Homme',
    other: 'Autre',
    autre: 'Autre',
};

const formatDate = (value: string | null): string => {
    if (!value) {
        return '—';
    }

    return new Intl.DateTimeFormat('fr-DZ').format(
        new Date(value.slice(0, 10) + 'T00:00:00'),
    );
};

const details = computed(() => [
    {
        label: 'Sexe',
        value: props.patient.gender
            ? (genderLabels[props.patient.gender.toLocaleLowerCase('fr-DZ')] ??
              props.patient.gender)
            : '—',
    },
    {
        label: 'Date de naissance',
        value: formatDate(props.patient.date_of_birth),
    },
    { label: 'Âge', value: age.value },
    { label: 'Groupe sanguin', value: props.patient.blood_group ?? '—' },
    { label: 'Téléphone', value: props.patient.phone ?? '—' },
    {
        label: 'Téléphone secondaire',
        value: props.patient.secondary_phone ?? '—',
    },
    { label: 'E-mail', value: props.patient.email ?? '—' },
    { label: 'Ville', value: props.patient.city ?? '—' },
    { label: 'Adresse', value: props.patient.address ?? '—' },
    {
        label: "Contact d'urgence",
        value: props.patient.emergency_contact_name ?? '—',
    },
    {
        label: "Téléphone d'urgence",
        value: props.patient.emergency_contact_phone ?? '—',
    },
]);
</script>

<template>
    <Head :title="props.patient.full_name" />

    <div class="med-page">
        <div>
            <PageBackButton
                href="/app/patients"
                label="Retour aux patients"
                class="mb-4"
            />
            <PageHeader
                :title="props.patient.full_name"
                :description="`Dossier ${props.patient.patient_number}`"
            >
                <template #actions>
                    <Button
                        v-if="can('consultations.view')"
                        variant="outline"
                        as-child
                    >
                        <Link
                            :href="`/app/patients/${props.patient.id}/consultation-history`"
                        >
                            <Stethoscope class="size-4" />
                            Historique des consultations
                        </Link>
                    </Button>
                    <Button v-if="can('patients.update')" as-child>
                        <Link :href="`/app/patients/${props.patient.id}/edit`">
                            <Pencil class="size-4" />
                            Modifier
                        </Link>
                    </Button>
                </template>
            </PageHeader>
        </div>

        <section class="med-panel p-6">
            <dl class="grid gap-x-6 gap-y-4 sm:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="detail in details"
                    :key="detail.label"
                    class="grid gap-1"
                >
                    <dt
                        class="text-xs tracking-wide text-muted-foreground uppercase"
                    >
                        {{ detail.label }}
                    </dt>
                    <dd class="text-sm font-medium text-foreground">
                        {{ detail.value }}
                    </dd>
                </div>
            </dl>

            <div v-if="props.patient.notes" class="mt-6 grid gap-1">
                <dt
                    class="text-xs tracking-wide text-muted-foreground uppercase"
                >
                    Notes
                </dt>
                <dd class="text-sm whitespace-pre-line text-foreground">
                    {{ props.patient.notes }}
                </dd>
            </div>
        </section>
    </div>
</template>
