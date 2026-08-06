<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Pencil, Plus, Search, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import ConfigurationTabs from '@/components/configuration/ConfigurationTabs.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PageBackButton from '@/components/PageBackButton.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import type { MedicationItem, Paginator } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Configuration', href: '/app/configuration' },
            { title: 'Médicaments', href: '/app/configuration/medications' },
        ],
    },
});

const props = defineProps<{
    medications: Paginator<MedicationItem>;
    filters: { search: string };
    forms: string[];
}>();

usePage();

const search = ref(props.filters.search ?? '');
const showForm = ref(false);
const editing = ref<MedicationItem | null>(null);
const deleting = ref<MedicationItem | null>(null);

const form = useForm<{
    name: string;
    dci: string;
    form: string;
    dosage: string;
    notes: string;
    is_active: boolean;
}>({
    name: '',
    dci: '',
    form: '',
    dosage: '',
    notes: '',
    is_active: true,
});

const FORM_CUSTOM = '__custom__';
const formSelection = ref<string>('');
const isCustomForm = computed(() => formSelection.value === FORM_CUSTOM);

const selectForm = (value: unknown) => {
    const next = typeof value === 'string' ? value : '';
    formSelection.value = next;
    form.form = next === FORM_CUSTOM ? '' : next;
};

const submitSearch = () => {
    router.get(
        '/app/configuration/medications',
        { search: search.value },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};

const openCreate = () => {
    editing.value = null;
    form.reset();
    form.clearErrors();
    form.is_active = true;
    formSelection.value = '';
    showForm.value = true;
};

const openEdit = (medication: MedicationItem) => {
    editing.value = medication;
    form.clearErrors();
    form.name = medication.name;
    form.dci = medication.dci ?? '';
    form.form = medication.form ?? '';
    formSelection.value = medication.form
        ? props.forms.includes(medication.form)
            ? medication.form
            : FORM_CUSTOM
        : '';
    form.dosage = medication.dosage ?? '';
    form.notes = medication.notes ?? '';
    form.is_active = medication.is_active;
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
        form.put(`/app/configuration/medications/${editing.value.id}`, options);
    } else {
        form.post('/app/configuration/medications', options);
    }
};

const doDelete = () => {
    if (!deleting.value) {
        return;
    }

    router.delete(`/app/configuration/medications/${deleting.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            deleting.value = null;
        },
    });
};

const localizedPaginationLabel = (label: string): string =>
    label.replace('Previous', 'Précédent').replace('Next', 'Suivant');
</script>

<template>
    <Head title="Médicaments" />

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
                    title="Médicaments"
                    description="Gérez le catalogue de référence des médicaments du cabinet."
                />

                <Button @click="openCreate">
                    <Plus class="size-4" />
                    Ajouter un médicament
                </Button>
            </div>

            <form
                class="mt-6 flex max-w-md items-center gap-2"
                @submit.prevent="submitSearch"
            >
                <Input
                    v-model="search"
                    type="search"
                    placeholder="Rechercher par nom, dénomination commune internationale (DCI) ou forme"
                    aria-label="Rechercher des médicaments"
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
                            <th class="px-4 py-3 font-medium">Nom</th>
                            <th class="px-4 py-3 font-medium">DCI</th>
                            <th class="px-4 py-3 font-medium">Forme</th>
                            <th class="px-4 py-3 font-medium">Dosage</th>
                            <th class="px-4 py-3 font-medium">Statut</th>
                            <th class="px-4 py-3 text-right font-medium">
                                Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody
                        class="divide-y divide-sidebar-border/70 dark:divide-sidebar-border"
                    >
                        <tr v-if="medications.data.length === 0">
                            <td
                                class="px-4 py-8 text-center text-muted-foreground"
                                colspan="6"
                            >
                                Aucun médicament trouvé. Cliquez sur « Ajouter
                                un médicament » pour en créer un.
                            </td>
                        </tr>
                        <tr
                            v-for="medication in medications.data"
                            :key="medication.id"
                            class="bg-background"
                        >
                            <td class="px-4 py-3 font-medium text-foreground">
                                {{ medication.name }}
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ medication.dci ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ medication.form ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ medication.dosage ?? '—' }}
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="
                                        medication.is_active
                                            ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300'
                                            : 'bg-muted text-muted-foreground'
                                    "
                                >
                                    {{
                                        medication.is_active
                                            ? 'Actif'
                                            : 'Inactif'
                                    }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div
                                    class="flex items-center justify-end gap-2"
                                >
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        aria-label="Modifier"
                                        @click="openEdit(medication)"
                                    >
                                        <Pencil class="size-4" />
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        aria-label="Supprimer"
                                        @click="deleting = medication"
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
                    Affichage de {{ medications.from ?? 0 }} à
                    {{ medications.to ?? 0 }}
                    sur {{ medications.total }} médicaments.
                </p>

                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        v-for="link in medications.links"
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
                            ? 'Modifier le médicament'
                            : 'Ajouter un médicament'
                    }}</DialogTitle>
                    <DialogDescription>
                        Informations de référence utilisées lors de la rédaction
                        des ordonnances.
                    </DialogDescription>
                </DialogHeader>

                <form class="space-y-4" @submit.prevent="submit">
                    <div class="grid gap-2">
                        <Label for="name">Nom commercial</Label>
                        <Input
                            id="name"
                            v-model="form.name"
                            required
                            autocomplete="off"
                        />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="dci"
                                >Dénomination commune internationale
                                (DCI)</Label
                            >
                            <Input
                                id="dci"
                                v-model="form.dci"
                                autocomplete="off"
                            />
                            <InputError :message="form.errors.dci" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="form">Forme</Label>
                            <Select
                                :model-value="formSelection"
                                @update:model-value="selectForm"
                            >
                                <SelectTrigger id="form" class="w-full">
                                    <SelectValue
                                        placeholder="Sélectionner une forme"
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="option in forms"
                                        :key="option"
                                        :value="option"
                                    >
                                        {{ option }}
                                    </SelectItem>
                                    <SelectItem :value="FORM_CUSTOM"
                                        >Personnalisée…</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                            <Input
                                v-if="isCustomForm"
                                v-model="form.form"
                                placeholder="Saisissez une nouvelle forme (p. ex. comprimé)"
                                autocomplete="off"
                            />
                            <InputError :message="form.errors.form" />
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <Label for="dosage">Dosage</Label>
                        <Input
                            id="dosage"
                            v-model="form.dosage"
                            placeholder="p. ex. 500 mg"
                            autocomplete="off"
                        />
                        <InputError :message="form.errors.dosage" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="notes">Remarques</Label>
                        <Textarea id="notes" v-model="form.notes" rows="3" />
                        <InputError :message="form.errors.notes" />
                    </div>

                    <label class="flex items-center gap-2">
                        <Checkbox
                            :model-value="form.is_active"
                            @update:model-value="
                                (value) => (form.is_active = value === true)
                            "
                        />
                        <span class="text-sm font-medium text-foreground"
                            >Actif</span
                        >
                    </label>

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
                                    : 'Ajouter le médicament'
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
                    <DialogTitle>Supprimer le médicament</DialogTitle>
                    <DialogDescription>
                        Supprimer « {{ deleting?.name }} » du catalogue ? Cette
                        action est irréversible.
                    </DialogDescription>
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
