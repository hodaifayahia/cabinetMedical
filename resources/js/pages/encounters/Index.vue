<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Eye, Pencil, Plus } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import PageBackButton from '@/components/PageBackButton.vue';
import { Button } from '@/components/ui/button';
import type { Paginator } from '@/types';
import type {
    EncounterListItem,
    EncounterPatientSummary,
} from '@/types/encounter';

const props = defineProps<{
    patient: EncounterPatientSummary;
    encounters: Paginator<EncounterListItem>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Patients', href: '/app/patients' }],
    },
});

const page = usePage();

const can = (permission: string): boolean =>
    page.props.auth.user?.permissions?.includes(permission) ?? false;

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

const formatDate = (value: string | null): string =>
    value ? new Date(value).toLocaleDateString('fr-FR') : '—';

const formatDateTime = (value: string | null): string =>
    value ? new Date(value).toLocaleString('fr-FR') : '—';
</script>

<template>
    <Head :title="`Consultations · ${props.patient.full_name}`" />

    <div class="med-page overflow-x-auto">
        <section class="med-panel p-6">
            <PageBackButton
                :href="`/app/patients/${props.patient.id}`"
                label="Retour au patient"
                class="mb-4"
            />
            <div
                class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
            >
                <Heading
                    :title="`Consultations`"
                    :description="`${props.patient.full_name} · Dossier ${props.patient.patient_number}`"
                />

                <div class="flex items-center gap-2">
                    <Button variant="outline" as-child>
                        <Link :href="`/app/patients/${props.patient.id}`"
                            >Retour au patient</Link
                        >
                    </Button>
                    <Button v-if="can('encounters.create')" as-child>
                        <Link
                            :href="`/app/patients/${props.patient.id}/encounters/create`"
                        >
                            <Plus class="size-4" />
                            Nouvelle consultation
                        </Link>
                    </Button>
                </div>
            </div>

            <div class="med-table-wrap mt-6">
                <table class="med-table">
                    <thead
                        class="bg-muted/40 text-left text-xs tracking-wide text-muted-foreground uppercase"
                    >
                        <tr>
                            <th class="px-4 py-3 font-medium">Date</th>
                            <th class="px-4 py-3 font-medium">Praticien</th>
                            <th class="px-4 py-3 font-medium">Statut</th>
                            <th class="px-4 py-3 font-medium">Signature</th>
                            <th class="px-4 py-3 text-right font-medium">
                                Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody
                        class="divide-y divide-sidebar-border/70 dark:divide-sidebar-border"
                    >
                        <tr v-if="encounters.data.length === 0">
                            <td
                                class="px-4 py-8 text-center text-muted-foreground"
                                colspan="5"
                            >
                                Aucune consultation enregistrée pour le moment.
                            </td>
                        </tr>
                        <tr
                            v-for="encounter in encounters.data"
                            :key="encounter.id"
                            class="bg-background"
                        >
                            <td class="px-4 py-3 font-medium text-foreground">
                                {{ formatDate(encounter.occurred_at) }}
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ encounter.provider?.name ?? '—' }}
                            </td>
                            <td class="px-4 py-3">
                                <span :class="statusBadge(encounter.status)">{{
                                    statusLabel(encounter.status)
                                }}</span>
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{
                                    encounter.signed_by
                                        ? `${encounter.signed_by.name} · ${formatDateTime(encounter.signed_at)}`
                                        : '—'
                                }}
                            </td>
                            <td class="px-4 py-3">
                                <div
                                    class="flex items-center justify-end gap-2"
                                >
                                    <Button variant="ghost" size="sm" as-child>
                                        <Link
                                            :href="`/app/patients/${props.patient.id}/encounters/${encounter.id}`"
                                            aria-label="Voir la consultation"
                                        >
                                            <Eye class="size-4" />
                                        </Link>
                                    </Button>
                                    <Button
                                        v-if="
                                            encounter.status !== 'signed' &&
                                            can('encounters.update')
                                        "
                                        variant="ghost"
                                        size="sm"
                                        as-child
                                    >
                                        <Link
                                            :href="`/app/patients/${props.patient.id}/encounters/${encounter.id}/edit`"
                                            aria-label="Modifier la consultation"
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
                    Affichage de {{ encounters.from ?? 0 }} à
                    {{ encounters.to ?? 0 }} sur
                    {{ encounters.total }} consultations.
                </p>

                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        v-for="link in encounters.links"
                        :key="link.label"
                        :variant="link.active ? 'default' : 'outline'"
                        size="sm"
                        :disabled="!link.url"
                        as-child
                    >
                        <Link v-if="link.url" :href="link.url" preserve-scroll>
                            <span v-html="link.label" />
                        </Link>
                        <span v-else v-html="link.label" />
                    </Button>
                </div>
            </div>
        </section>
    </div>
</template>
