<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Search, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import ConfigurationTabs from '@/components/configuration/ConfigurationTabs.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PageBackButton from '@/components/PageBackButton.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type {
    Paginator,
    ReferentialField,
    ReferentialMeta,
    ReferentialRow,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Configuration', href: '/app/configuration' }],
    },
});

const props = defineProps<{
    items: Paginator<ReferentialRow>;
    filters: { search: string };
    meta: ReferentialMeta;
}>();

type ReferentialTranslation = {
    title: string;
    description: string;
    labels: Record<string, string>;
};

const referentialTranslations: Record<string, ReferentialTranslation> = {
    'bilan-types': {
        title: 'Catégories de bilans',
        description: 'Catégories disponibles dans l’espace Bilans.',
        labels: {
            name: 'Nom',
            description: 'Description',
        },
    },
    exams: {
        title: 'Examens complémentaires',
        description: 'Catalogue des examens complémentaires.',
        labels: {
            name: 'Nom',
            category: 'Catégorie',
        },
    },
    practitioners: {
        title: 'Répertoire des médecins',
        description: 'Médecins correspondants et praticiens du cabinet.',
        labels: {
            name: 'Nom',
            specialty: 'Spécialité',
            phone: 'Téléphone',
            email: 'E-mail',
            address: 'Adresse',
            order_number: 'Numéro d’ordre',
        },
    },
    'consultation-fees': {
        title: 'Tarifs de consultation',
        description: 'Tarifs des actes médicaux utilisés pour la facturation.',
        labels: {
            label: 'Libellé',
            amount: 'Montant (DA)',
            category: 'Catégorie',
        },
    },
    acts: {
        title: 'Catégories et actes',
        description: 'Actes médicaux et leurs tarifs.',
        labels: {
            code: 'Code',
            name: 'Nom',
            price: 'Tarif (DA)',
            category: 'Catégorie',
        },
    },
    'payment-methods': {
        title: 'Moyens de paiement',
        description: 'Moyens de paiement acceptés au cabinet.',
        labels: {
            name: 'Nom',
        },
    },
};

const translation = computed(
    () => referentialTranslations[props.meta.slug] ?? null,
);
const localizedTitle = computed(
    () => translation.value?.title ?? props.meta.title,
);
const localizedDescription = computed(
    () => translation.value?.description ?? props.meta.description,
);
const localizedLabel = (key: string, fallback: string): string =>
    translation.value?.labels[key] ?? fallback;
const localizedPaginationLabel = (label: string): string =>
    label.replace('Previous', 'Précédent').replace('Next', 'Suivant');

const routeBase = `/app/configuration/ref/${props.meta.slug}`;

const search = ref(props.filters.search ?? '');
const showForm = ref(false);
const editing = ref<ReferentialRow | null>(null);
const deleting = ref<ReferentialRow | null>(null);

const blankData = (): Record<string, string> =>
    Object.fromEntries(props.meta.fields.map((field) => [field.key, '']));

const form = useForm<Record<string, string>>(blankData());

const fieldError = (key: string): string | undefined =>
    (form.errors as Record<string, string>)[key];

const formatCell = (value: string | number | null | undefined): string =>
    value === null || value === undefined || value === '' ? '—' : String(value);

const submitSearch = () => {
    router.get(
        routeBase,
        { search: search.value },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};

const openCreate = () => {
    editing.value = null;
    form.clearErrors();
    form.defaults(blankData());
    form.reset();
    showForm.value = true;
};

const openEdit = (row: ReferentialRow) => {
    editing.value = row;
    form.clearErrors();
    props.meta.fields.forEach((field) => {
        const value = row[field.key];
        form[field.key] =
            value === null || value === undefined ? '' : String(value);
    });
    showForm.value = true;
};

const submit = () => {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            showForm.value = false;
        },
    } as const;

    if (editing.value) {
        form.put(`${routeBase}/${editing.value.id}`, options);
    } else {
        form.post(routeBase, options);
    }
};

const doDelete = () => {
    if (!deleting.value) {
        return;
    }

    router.delete(`${routeBase}/${deleting.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            deleting.value = null;
        },
    });
};

const inputType = (field: ReferentialField): string =>
    field.type === 'money' || field.type === 'number' ? 'number' : 'text';
</script>

<template>
    <Head :title="localizedTitle" />

    <div class="med-page">
        <PageBackButton
            href="/app/configuration"
            label="Retour à la configuration du cabinet"
        />
        <ConfigurationTabs />

        <section class="med-panel p-6">
            <div
                class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
            >
                <Heading
                    :title="localizedTitle"
                    :description="localizedDescription"
                />

                <Button @click="openCreate">
                    <Plus class="size-4" />
                    Ajouter
                </Button>
            </div>

            <form
                class="mt-6 flex max-w-md items-center gap-2"
                @submit.prevent="submitSearch"
            >
                <Input
                    v-model="search"
                    type="search"
                    placeholder="Rechercher…"
                    aria-label="Rechercher"
                />
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
                            <th
                                v-for="column in meta.columns"
                                :key="column.key"
                                class="px-4 py-3 font-medium"
                            >
                                {{ localizedLabel(column.key, column.label) }}
                            </th>
                            <th class="px-4 py-3 text-right font-medium">
                                Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody
                        class="divide-y divide-sidebar-border/70 dark:divide-sidebar-border"
                    >
                        <tr v-if="items.data.length === 0">
                            <td
                                class="px-4 py-8 text-center text-muted-foreground"
                                :colspan="meta.columns.length + 1"
                            >
                                Aucune entrée. Cliquez sur « Ajouter » pour en
                                créer une.
                            </td>
                        </tr>
                        <tr
                            v-for="row in items.data"
                            :key="row.id"
                            class="bg-background"
                        >
                            <td
                                v-for="column in meta.columns"
                                :key="column.key"
                                class="px-4 py-3"
                                :class="
                                    column.key === meta.columns[0].key
                                        ? 'font-medium text-foreground'
                                        : 'text-muted-foreground'
                                "
                            >
                                {{ formatCell(row[column.key]) }}
                            </td>
                            <td class="px-4 py-3">
                                <div
                                    class="flex items-center justify-end gap-2"
                                >
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        aria-label="Modifier"
                                        @click="openEdit(row)"
                                    >
                                        <Pencil class="size-4" />
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        aria-label="Supprimer"
                                        @click="deleting = row"
                                    >
                                        <Trash2
                                            class="size-4 text-destructive"
                                        />
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
                    Affichage de {{ items.from ?? 0 }} à {{ items.to ?? 0 }} sur
                    {{ items.total }} entrées.
                </p>
                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        v-for="link in items.links"
                        :key="link.label"
                        :variant="link.active ? 'default' : 'outline'"
                        size="sm"
                        :disabled="!link.url"
                        as-child
                    >
                        <Link v-if="link.url" :href="link.url" preserve-scroll>
                            <span
                                v-html="localizedPaginationLabel(link.label)"
                            />
                        </Link>
                        <span
                            v-else
                            v-html="localizedPaginationLabel(link.label)"
                        />
                    </Button>
                </div>
            </div>
        </section>

        <Dialog v-model:open="showForm">
            <DialogContent class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{{
                        editing
                            ? `Modifier : ${localizedTitle}`
                            : `Ajouter : ${localizedTitle}`
                    }}</DialogTitle>
                    <DialogDescription>
                        {{ localizedDescription }}
                    </DialogDescription>
                </DialogHeader>

                <form class="space-y-4" @submit.prevent="submit">
                    <div
                        v-for="field in meta.fields"
                        :key="field.key"
                        class="grid gap-2"
                    >
                        <Label :for="field.key">
                            {{ localizedLabel(field.key, field.label)
                            }}<span
                                v-if="field.required"
                                class="text-destructive"
                            >
                                *</span
                            >
                        </Label>
                        <Textarea
                            v-if="field.type === 'textarea'"
                            :id="field.key"
                            v-model="form[field.key]"
                            rows="3"
                        />
                        <select
                            v-else-if="field.type === 'select'"
                            :id="field.key"
                            v-model="form[field.key]"
                            class="med-native-control w-full"
                        >
                            <option value="">Sélectionner une catégorie</option>
                            <option
                                v-for="option in field.options ?? []"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </select>
                        <Input
                            v-else
                            :id="field.key"
                            v-model="form[field.key]"
                            :type="inputType(field)"
                            :step="field.type === 'money' ? 'any' : undefined"
                            :placeholder="field.placeholder"
                            autocomplete="off"
                        />
                        <InputError :message="fieldError(field.key)" />
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="showForm = false"
                            >Annuler</Button
                        >
                        <Button type="submit" :disabled="form.processing">
                            {{
                                editing
                                    ? 'Enregistrer les modifications'
                                    : 'Ajouter'
                            }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog
            :open="deleting !== null"
            @update:open="
                (value) => {
                    if (!value) deleting = null;
                }
            "
        >
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Supprimer l’entrée</DialogTitle>
                    <DialogDescription
                        >Cette action est irréversible.</DialogDescription
                    >
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" @click="deleting = null"
                        >Annuler</Button
                    >
                    <Button variant="destructive" @click="doDelete"
                        >Supprimer</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
