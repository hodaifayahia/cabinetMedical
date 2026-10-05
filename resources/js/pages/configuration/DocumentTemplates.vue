<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Copy, FileText, Pencil, Plus, Search, Trash2 } from '@lucide/vue';
import { computed, nextTick, ref } from 'vue';
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

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Configuration', href: '/app/configuration' }],
    },
});

type CategoryOption = { value: string; label: string };
type PlaceholderToken = { token: string; label: string };

type DocumentTemplateRow = {
    id: number;
    template_key: string;
    title: string;
    category: string;
    group: string | null;
    paper_size: string;
    body: string;
    is_active: boolean;
    updated_at: string | null;
};

const props = defineProps<{
    templates: DocumentTemplateRow[];
    filters: { search: string };
    options: {
        categories: CategoryOption[];
        paperSizes: CategoryOption[];
        placeholders: PlaceholderToken[];
    };
}>();

const routeBase = '/app/configuration/document-templates';

const search = ref(props.filters.search ?? '');
const showForm = ref(false);
const editing = ref<DocumentTemplateRow | null>(null);
const deleting = ref<DocumentTemplateRow | null>(null);
const bodyField = ref<HTMLTextAreaElement | null>(null);

const form = useForm({
    title: '',
    category: props.options.categories[0]?.value ?? 'courrier',
    group: '',
    paper_size: props.options.paperSizes[0]?.value ?? 'A4',
    body: '',
    is_active: true,
});

const categoryLabel = (value: string): string =>
    props.options.categories.find((option) => option.value === value)?.label ??
    value;

const normalized = (value: string | null | undefined): string =>
    (value ?? '').toLocaleLowerCase();

const filtered = computed(() => {
    const query = normalized(search.value).trim();

    if (query === '') {
        return props.templates;
    }

    return props.templates.filter((template) =>
        [template.title, template.group, categoryLabel(template.category)]
            .map(normalized)
            .join(' ')
            .includes(query),
    );
});

const blank = () => {
    form.clearErrors();
    form.defaults({
        title: '',
        category: props.options.categories[0]?.value ?? 'courrier',
        group: '',
        paper_size: props.options.paperSizes[0]?.value ?? 'A4',
        body: '',
        is_active: true,
    });
    form.reset();
};

const openCreate = () => {
    editing.value = null;
    blank();
    showForm.value = true;
};

const openEdit = (row: DocumentTemplateRow) => {
    editing.value = row;
    form.clearErrors();
    form.title = row.title;
    form.category = row.category;
    form.group = row.group ?? '';
    form.paper_size = row.paper_size;
    form.body = row.body;
    form.is_active = row.is_active;
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

const insertToken = (token: string) => {
    const el = bodyField.value;

    if (!el) {
        const separator =
            form.body === '' || form.body.endsWith('\n') ? '' : ' ';
        form.body = `${form.body}${separator}${token}`;

        return;
    }

    const start = el.selectionStart ?? form.body.length;
    const end = el.selectionEnd ?? form.body.length;
    form.body = form.body.slice(0, start) + token + form.body.slice(end);

    void nextTick(() => {
        el.focus();
        const caret = start + token.length;
        el.setSelectionRange(caret, caret);
    });
};
</script>

<template>
    <Head title="Modèles de documents" />

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
                    title="Modèles de documents"
                    description="Créez vos propres modèles d’ordonnances, de bilans et de courriers. Ils apparaissent dans la consultation, à côté des modèles intégrés."
                />

                <Button @click="openCreate">
                    <Plus class="size-4" />
                    Nouveau modèle
                </Button>
            </div>

            <div class="mt-6 flex max-w-md items-center gap-2">
                <Input
                    v-model="search"
                    type="search"
                    placeholder="Rechercher un modèle…"
                    aria-label="Rechercher un modèle"
                />
                <Search class="size-4 text-muted-foreground" />
            </div>

            <div class="med-table-wrap mt-6">
                <table class="med-table">
                    <thead
                        class="bg-muted/40 text-left text-xs tracking-wide text-muted-foreground uppercase"
                    >
                        <tr>
                            <th class="px-4 py-3 font-medium">Nom</th>
                            <th class="px-4 py-3 font-medium">Type</th>
                            <th class="px-4 py-3 font-medium">Groupe</th>
                            <th class="px-4 py-3 font-medium">Format</th>
                            <th class="px-4 py-3 font-medium">Statut</th>
                            <th class="px-4 py-3 text-right font-medium">
                                Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody
                        class="divide-y divide-sidebar-border/70 dark:divide-sidebar-border"
                    >
                        <tr v-if="filtered.length === 0">
                            <td
                                class="px-4 py-8 text-center text-muted-foreground"
                                colspan="6"
                            >
                                Aucun modèle pour le moment. Cliquez sur
                                « Nouveau modèle » pour en créer un.
                            </td>
                        </tr>
                        <tr
                            v-for="row in filtered"
                            :key="row.id"
                            class="bg-background"
                        >
                            <td
                                class="px-4 py-3 font-medium text-foreground"
                            >
                                <span class="inline-flex items-center gap-2">
                                    <FileText
                                        class="size-4 shrink-0 text-brand"
                                    />
                                    {{ row.title }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ categoryLabel(row.category) }}
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ row.group ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ row.paper_size }}
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="
                                        row.is_active
                                            ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300'
                                            : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300'
                                    "
                                >
                                    {{ row.is_active ? 'Actif' : 'Inactif' }}
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
        </section>

        <Dialog v-model:open="showForm">
            <DialogContent class="sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>
                        {{
                            editing
                                ? 'Modifier le modèle'
                                : 'Nouveau modèle de document'
                        }}
                    </DialogTitle>
                    <DialogDescription>
                        Choisissez le type et le format, puis rédigez le
                        contenu. Utilisez les variables pour insérer
                        automatiquement les informations du patient et de la
                        consultation.
                    </DialogDescription>
                </DialogHeader>

                <form class="space-y-5" @submit.prevent="submit">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2 sm:col-span-2">
                            <Label for="template-title">
                                Nom du modèle
                                <span class="text-destructive">*</span>
                            </Label>
                            <Input
                                id="template-title"
                                v-model="form.title"
                                autocomplete="off"
                                placeholder="Ex. Certificat médical du cabinet"
                            />
                            <InputError :message="form.errors.title" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="template-category">
                                Type de document
                                <span class="text-destructive">*</span>
                            </Label>
                            <select
                                id="template-category"
                                v-model="form.category"
                                class="med-native-control w-full"
                            >
                                <option
                                    v-for="option in options.categories"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </option>
                            </select>
                            <InputError :message="form.errors.category" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="template-paper">
                                Format de page
                                <span class="text-destructive">*</span>
                            </Label>
                            <select
                                id="template-paper"
                                v-model="form.paper_size"
                                class="med-native-control w-full"
                            >
                                <option
                                    v-for="option in options.paperSizes"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </option>
                            </select>
                            <InputError :message="form.errors.paper_size" />
                        </div>

                        <div class="grid gap-2 sm:col-span-2">
                            <Label for="template-group">
                                Groupe (facultatif)
                            </Label>
                            <Input
                                id="template-group"
                                v-model="form.group"
                                autocomplete="off"
                                placeholder="Ex. Certificats, Mes courriers…"
                            />
                            <InputError :message="form.errors.group" />
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <Label for="template-body">
                            Contenu du document
                            <span class="text-destructive">*</span>
                        </Label>
                        <textarea
                            id="template-body"
                            ref="bodyField"
                            v-model="form.body"
                            rows="10"
                            class="med-native-control min-h-48 w-full font-mono text-sm leading-6"
                            placeholder="Rédigez le document ici. Les lignes commençant par ## deviennent des titres."
                        />
                        <InputError :message="form.errors.body" />
                    </div>

                    <div
                        class="rounded-xl border border-sidebar-border/70 bg-muted/30 p-4 dark:border-sidebar-border"
                    >
                        <p
                            class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                        >
                            Variables disponibles — cliquez pour insérer
                        </p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <button
                                v-for="placeholder in options.placeholders"
                                :key="placeholder.token"
                                type="button"
                                class="inline-flex items-center gap-1 rounded-lg border border-sidebar-border/70 bg-background px-2 py-1 text-xs text-muted-foreground transition hover:border-brand hover:text-brand dark:border-sidebar-border"
                                :title="placeholder.label"
                                @click="insertToken(placeholder.token)"
                            >
                                <Copy class="size-3" />
                                {{ placeholder.label }}
                            </button>
                        </div>
                    </div>

                    <label
                        class="flex items-center gap-2 text-sm text-foreground"
                    >
                        <input
                            v-model="form.is_active"
                            type="checkbox"
                            class="size-4 rounded border-input"
                        />
                        Modèle actif (visible pendant la consultation)
                    </label>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="showForm = false"
                        >
                            Annuler
                        </Button>
                        <Button type="submit" :disabled="form.processing">
                            {{
                                editing
                                    ? 'Enregistrer les modifications'
                                    : 'Créer le modèle'
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
                    <DialogTitle>Supprimer le modèle</DialogTitle>
                    <DialogDescription>
                        Cette action est irréversible. Les documents déjà créés
                        ne sont pas affectés.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" @click="deleting = null">
                        Annuler
                    </Button>
                    <Button variant="destructive" @click="doDelete">
                        Supprimer
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
