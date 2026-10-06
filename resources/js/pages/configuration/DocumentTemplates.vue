<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    Eye,
    FileDown,
    FileText,
    FileUp,
    Pencil,
    Plus,
    Search,
    Trash2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import ConfigurationTabs from '@/components/configuration/ConfigurationTabs.vue';
import RichDocumentEditor from '@/components/documents/RichDocumentEditor.vue';
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
import {
    SAMPLE_TEMPLATE_VALUES,
    htmlIsEffectivelyEmpty,
    renderTemplateHtml,
    templateBodyToHtml,
} from '@/lib/documentTemplateBody';
import { importDocxFile } from '@/lib/docxImport';
import { downloadTemplateDocx } from '@/lib/templateDocxExport';

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
    body_format?: 'text' | 'html';
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
const view = ref<'edit' | 'preview'>('edit');
const importInput = ref<HTMLInputElement | null>(null);
const importing = ref(false);
const exporting = ref(false);
const editorFullscreen = ref(false);
let fullscreenExitedAt = 0;

const form = useForm({
    title: '',
    category: props.options.categories[0]?.value ?? 'courrier',
    group: '',
    paper_size: props.options.paperSizes[0]?.value ?? 'A4',
    body: '',
    body_format: 'html' as 'text' | 'html',
    is_active: true,
});

const paperSize = computed<'A4' | 'A5'>(() =>
    form.paper_size === 'A5' ? 'A5' : 'A4',
);

const preview = computed(() =>
    renderTemplateHtml(form.body, 'html', SAMPLE_TEMPLATE_VALUES, {
        keepUnknown: true,
    }),
);

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
        body_format: 'html',
        is_active: true,
    });
    form.reset();
};

const openCreate = () => {
    editing.value = null;
    view.value = 'edit';
    blank();
    showForm.value = true;
};

const openEdit = (row: DocumentTemplateRow) => {
    editing.value = row;
    view.value = 'edit';
    form.clearErrors();
    form.defaults({
        title: row.title,
        category: row.category,
        group: row.group ?? '',
        paper_size: row.paper_size,
        // Legacy line-based bodies ("## " headings) open as rich text and are
        // saved back as HTML.
        body: templateBodyToHtml(row.body, row.body_format ?? 'text'),
        body_format: 'html',
        is_active: row.is_active,
    });
    form.reset();
    showForm.value = true;
};

const submit = () => {
    form.body_format = 'html';

    if (htmlIsEffectivelyEmpty(form.body)) {
        form.setError('body', 'Le contenu du modèle ne peut pas être vide.');
        view.value = 'edit';

        return;
    }

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

const closeForm = () => {
    if (
        form.isDirty &&
        !window.confirm(
            'Fermer l’éditeur ? Les modifications non enregistrées seront perdues.',
        )
    ) {
        return;
    }

    showForm.value = false;
};

const onFullscreenChange = (value: boolean) => {
    editorFullscreen.value = value;

    if (!value) {
        fullscreenExitedAt = Date.now();
    }
};

const onDialogEscape = (event: KeyboardEvent) => {
    // Escape first leaves the editor's full screen; it must not also close
    // the dialog (and lose the template being written).
    if (editorFullscreen.value || Date.now() - fullscreenExitedAt < 600) {
        event.preventDefault();

        return;
    }

    if (
        form.isDirty &&
        !window.confirm(
            'Fermer l’éditeur ? Les modifications non enregistrées seront perdues.',
        )
    ) {
        event.preventDefault();
    }
};

const pickWordFile = () => importInput.value?.click();

const onWordFilePicked = async (event: Event) => {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';

    if (!file) {
        return;
    }

    if (
        !htmlIsEffectivelyEmpty(form.body) &&
        !window.confirm(
            'Remplacer le contenu actuel du modèle par celui du fichier Word ?',
        )
    ) {
        return;
    }

    importing.value = true;

    try {
        const { html, warnings } = await importDocxFile(file);
        form.body = html;
        form.clearErrors('body');
        view.value = 'edit';

        if (form.title.trim() === '') {
            form.title = file.name.replace(/\.docx$/i, '').slice(0, 200);
        }

        toast.success(
            'Document Word importé. Vous pouvez maintenant le modifier comme dans Word.',
        );
        warnings.forEach((warning) => toast.warning(warning));
    } catch (error) {
        toast.error(
            error instanceof Error
                ? error.message
                : 'Impossible d’importer ce fichier Word.',
        );
    } finally {
        importing.value = false;
    }
};

const exportWord = async () => {
    if (htmlIsEffectivelyEmpty(form.body)) {
        toast.error('Le modèle est vide : rien à exporter.');

        return;
    }

    exporting.value = true;

    try {
        await downloadTemplateDocx(`${routeBase}/export-docx`, {
            title: form.title,
            body: form.body,
            body_format: 'html',
            paper_size: form.paper_size,
        });
    } catch (error) {
        toast.error(
            error instanceof Error
                ? error.message
                : 'Le fichier Word n’a pas pu être généré.',
        );
    } finally {
        exporting.value = false;
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
                                Aucun modèle pour le moment. Cliquez sur «
                                Nouveau modèle » pour en créer un.
                            </td>
                        </tr>
                        <tr
                            v-for="row in filtered"
                            :key="row.id"
                            class="bg-background"
                        >
                            <td class="px-4 py-3 font-medium text-foreground">
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

        <Dialog
            :open="showForm"
            @update:open="
                (value) => {
                    if (!value) closeForm();
                }
            "
        >
            <DialogContent
                class="flex h-[calc(100dvh-1.5rem)] w-[calc(100vw-1.5rem)] max-w-none flex-col gap-3 p-4 sm:max-w-none sm:p-5"
                @escape-key-down="onDialogEscape"
                @interact-outside="(event) => event.preventDefault()"
            >
                <DialogHeader class="pr-8">
                    <DialogTitle>
                        {{
                            editing
                                ? 'Modifier le modèle'
                                : 'Nouveau modèle de document'
                        }}
                    </DialogTitle>
                    <DialogDescription>
                        Rédigez et mettez en forme le document comme dans Word,
                        ou importez un fichier Word (.docx) existant. Les
                        variables sont remplacées automatiquement par les
                        informations du patient et de la consultation.
                    </DialogDescription>
                </DialogHeader>

                <form
                    class="flex min-h-0 flex-1 flex-col gap-3"
                    @submit.prevent="submit"
                >
                    <div
                        class="grid gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,2fr)_minmax(0,1.2fr)_minmax(0,1fr)_minmax(0,1.2fr)_auto] lg:items-end"
                    >
                        <div class="grid gap-1.5">
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

                        <div class="grid gap-1.5">
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

                        <div class="grid gap-1.5">
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

                        <div class="grid gap-1.5">
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

                        <label
                            class="flex h-9 items-center gap-2 text-sm text-foreground lg:pb-0.5"
                        >
                            <input
                                v-model="form.is_active"
                                type="checkbox"
                                class="size-4 rounded border-input"
                            />
                            Modèle actif
                        </label>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <div
                            class="inline-flex rounded-lg border border-sidebar-border/70 p-0.5 dark:border-sidebar-border"
                            role="tablist"
                        >
                            <button
                                type="button"
                                role="tab"
                                :aria-selected="view === 'edit'"
                                class="inline-flex items-center gap-1.5 rounded-md px-3 py-1 text-xs font-medium transition"
                                :class="
                                    view === 'edit'
                                        ? 'bg-brand text-white'
                                        : 'text-muted-foreground hover:text-foreground'
                                "
                                @click="view = 'edit'"
                            >
                                <Pencil class="size-3.5" />
                                Édition
                            </button>
                            <button
                                type="button"
                                role="tab"
                                :aria-selected="view === 'preview'"
                                class="inline-flex items-center gap-1.5 rounded-md px-3 py-1 text-xs font-medium transition"
                                :class="
                                    view === 'preview'
                                        ? 'bg-brand text-white'
                                        : 'text-muted-foreground hover:text-foreground'
                                "
                                @click="view = 'preview'"
                            >
                                <Eye class="size-3.5" />
                                Aperçu avec un patient fictif
                            </button>
                        </div>

                        <div class="ml-auto flex flex-wrap items-center gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                :disabled="importing"
                                @click="pickWordFile"
                            >
                                <FileUp class="size-4" />
                                {{
                                    importing
                                        ? 'Import en cours…'
                                        : 'Importer un fichier Word (.docx)'
                                }}
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                :disabled="exporting"
                                @click="exportWord"
                            >
                                <FileDown class="size-4" />
                                Télécharger en Word (.docx)
                            </Button>
                            <input
                                ref="importInput"
                                type="file"
                                class="hidden"
                                accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                                aria-label="Fichier Word à importer"
                                @change="onWordFilePicked"
                            />
                        </div>
                    </div>

                    <div class="flex min-h-0 flex-1 flex-col">
                        <RichDocumentEditor
                            v-show="view === 'edit'"
                            v-model="form.body"
                            class="min-h-0 flex-1"
                            :paper-size="paperSize"
                            :placeholders="options.placeholders"
                            placeholder="Rédigez le document ici, ou importez un fichier Word (.docx)…"
                            label="Contenu du modèle"
                            @fullscreen-change="onFullscreenChange"
                        />
                        <div
                            v-if="view === 'preview'"
                            class="rde-canvas min-h-0 flex-1 overflow-auto rounded-xl border border-sidebar-border/70 px-3 py-6 sm:px-8 dark:border-sidebar-border"
                        >
                            <div class="rde-paper" :data-paper="paperSize">
                                <!-- Rendered from the editor's own schema-bound HTML. -->
                                <div class="rde-content" v-html="preview" />
                            </div>
                        </div>
                        <InputError class="mt-1" :message="form.errors.body" />
                    </div>

                    <div
                        class="flex flex-col-reverse gap-2 sm:flex-row sm:items-center sm:justify-end"
                    >
                        <Button
                            type="button"
                            variant="outline"
                            @click="closeForm"
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
                    </div>
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
