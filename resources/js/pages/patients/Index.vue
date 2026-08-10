<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Eye, Pencil, Plus, Search, Users } from '@lucide/vue';
import { ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
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
import type { Paginator, PatientListItem, PatientOption } from '@/types';

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
}>();

const page = usePage();
const search = ref(props.filters.search ?? '');
const showCreate = ref(false);

const can = (permission: string): boolean =>
    page.props.auth.user?.permissions?.includes(permission) ?? false;

const submitSearch = () => {
    router.get(
        '/app/patients',
        { search: search.value },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};

const genderLabels: Record<string, string> = {
    female: 'Femme',
    femme: 'Femme',
    male: 'Homme',
    homme: 'Homme',
    other: 'Autre',
    autre: 'Autre',
};

const formatGender = (value: string | null): string =>
    value ? (genderLabels[value.toLocaleLowerCase('fr-DZ')] ?? value) : '—';

const formatDate = (value: string | null): string => {
    if (!value) {
        return '—';
    }

    return new Intl.DateTimeFormat('fr-DZ').format(
        new Date(value.slice(0, 10) + 'T00:00:00'),
    );
};

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

    <div class="med-page overflow-x-auto">
        <PageHeader
            title="Patients"
            description="Rechercher, enregistrer et gérer les dossiers patients."
        >
            <template #actions>
                <Button
                    v-if="can('patients.create')"
                    @click="showCreate = true"
                >
                    <Plus class="size-4" />
                    Ajouter un patient
                </Button>
            </template>
        </PageHeader>

        <section class="med-panel p-6">
            <form
                class="flex max-w-2xl flex-col gap-2 sm:flex-row"
                @submit.prevent="submitSearch"
            >
                <div class="relative min-w-0 flex-1">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <Input
                        v-model="search"
                        type="search"
                        class="pl-9"
                        placeholder="Nom, numéro de dossier, téléphone ou e-mail"
                        aria-label="Rechercher des patients"
                    />
                </div>
                <Button type="submit" variant="secondary">
                    <Search class="size-4" />
                    Rechercher
                </Button>
            </form>

            <div class="med-table-wrap mt-6">
                <table class="med-table">
                    <thead
                        class="bg-muted/40 text-left text-xs tracking-wide text-muted-foreground uppercase"
                    >
                        <tr>
                            <th class="px-4 py-3 font-medium">Dossier</th>
                            <th class="px-4 py-3 font-medium">Nom</th>
                            <th class="px-4 py-3 font-medium">Sexe</th>
                            <th class="px-4 py-3 font-medium">
                                Date de naissance
                            </th>
                            <th class="px-4 py-3 font-medium">Téléphone</th>
                            <th class="px-4 py-3 font-medium">Ville</th>
                            <th class="px-4 py-3 text-right font-medium">
                                Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody
                        class="divide-y divide-sidebar-border/70 dark:divide-sidebar-border"
                    >
                        <tr v-if="patients.data.length === 0">
                            <td colspan="7">
                                <div class="med-empty">
                                    <Users class="med-empty-icon" />
                                    <p class="med-empty-title">
                                        Aucun patient trouvé
                                    </p>
                                    <p class="med-empty-hint">
                                        Ajustez votre recherche ou enregistrez
                                        un nouveau dossier patient.
                                    </p>
                                </div>
                            </td>
                        </tr>
                        <tr
                            v-for="patient in patients.data"
                            :key="patient.id"
                            class="bg-background"
                        >
                            <td
                                class="px-4 py-3 font-mono text-xs text-muted-foreground"
                            >
                                {{ patient.patient_number }}
                            </td>
                            <td class="px-4 py-3 font-medium text-foreground">
                                {{ patient.full_name }}
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ formatGender(patient.gender) }}
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ formatDate(patient.date_of_birth) }}
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ patient.phone ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ patient.city ?? '—' }}
                            </td>
                            <td class="px-4 py-3">
                                <div
                                    class="flex items-center justify-end gap-2"
                                >
                                    <Button variant="ghost" size="sm" as-child>
                                        <Link
                                            :href="`/app/patients/${patient.id}`"
                                            :aria-label="`Voir ${patient.full_name}`"
                                        >
                                            <Eye class="size-4" />
                                        </Link>
                                    </Button>
                                    <Button
                                        v-if="can('patients.update')"
                                        variant="ghost"
                                        size="sm"
                                        as-child
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
                class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
            >
                <p class="text-sm text-muted-foreground">
                    Affichage de {{ patients.from ?? 0 }} à
                    {{ patients.to ?? 0 }} sur {{ patients.total }} patient{{
                        patients.total > 1 ? 's' : ''
                    }}.
                </p>

                <div class="flex flex-wrap items-center gap-2">
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
    </div>
</template>
