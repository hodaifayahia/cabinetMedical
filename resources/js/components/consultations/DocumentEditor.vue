<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Clock3,
    FileText,
    FileType2,
    Plus,
    Search,
} from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import OnlyOfficeClinicalEditor from '@/components/consultations/OnlyOfficeClinicalEditor.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type {
    ClinicalDocument,
    ClinicalDocumentTemplate,
    ClinicalOnlyOfficeSettings,
} from '@/types/clinicalDocuments';

const props = defineProps<{
    consultationId: number;
    category: 'bilan' | 'courrier';
    templates: ClinicalDocumentTemplate[];
    documents: ClinicalDocument[];
    onlyoffice: ClinicalOnlyOfficeSettings;
    canEdit: boolean;
}>();

const search = ref('');
const selectedTemplateId = ref<string | null>(null);
const paperSize = ref<'A4' | 'A5'>('A4');
const selectedDocument = ref<ClinicalDocument | null>(null);
const convertingDocumentId = ref<number | null>(null);

const categoryLabel = computed(() =>
    props.category === 'bilan' ? 'Bilans' : 'Courriers & certificats',
);

const categoryDescription = computed(() =>
    props.category === 'bilan'
        ? 'Examens, demandes et résultats au format Word'
        : 'Certificats, lettres et comptes rendus au format Word',
);

const templateId = (template: ClinicalDocumentTemplate): string =>
    `${template.source}:${template.key}`;

const availableTemplates = computed(() =>
    props.templates.filter(
        (template) =>
            (template.category === props.category ||
                template.category === 'general') &&
            `${template.title} ${template.group} ${template.description ?? ''}`
                .toLocaleLowerCase()
                .includes(search.value.trim().toLocaleLowerCase()),
    ),
);

const groupedTemplates = computed(() => {
    const groups = new Map<string, ClinicalDocumentTemplate[]>();

    for (const template of availableTemplates.value) {
        const items = groups.get(template.group) ?? [];
        items.push(template);
        groups.set(template.group, items);
    }

    return [...groups.entries()].map(([label, items]) => ({ label, items }));
});

const selectedTemplate = computed(
    () =>
        props.templates.find(
            (template) => templateId(template) === selectedTemplateId.value,
        ) ?? null,
);

const savedDocuments = computed(() =>
    props.documents.filter((document) => document.category === props.category),
);

watch(
    () => props.templates,
    () => {
        const currentIsAvailable = props.templates.some(
            (template) => templateId(template) === selectedTemplateId.value,
        );

        if (!currentIsAvailable) {
            const first = props.templates.find(
                (template) =>
                    template.category === props.category ||
                    template.category === 'general',
            );
            selectedTemplateId.value = first ? templateId(first) : null;
            paperSize.value = first?.default_paper_size ?? 'A4';
        }
    },
    { immediate: true },
);

const selectTemplate = (template: ClinicalDocumentTemplate) => {
    selectedTemplateId.value = templateId(template);
    paperSize.value = template.default_paper_size;
};

const createForm = useForm<{
    source: 'built_in';
    category: 'bilan' | 'courrier';
    paper_size: 'A4' | 'A5';
    template_key: string | null;
    title: string | null;
}>({
    source: 'built_in',
    category: props.category,
    paper_size: 'A4',
    template_key: null,
    title: null,
});

const createDocument = () => {
    if (!selectedTemplate.value) {
        return;
    }

    const existingIds = new Set(props.documents.map((document) => document.id));
    createForm.source = selectedTemplate.value.source;
    createForm.category = props.category;
    createForm.paper_size = paperSize.value;
    createForm.template_key = selectedTemplate.value.key;
    createForm.title = selectedTemplate.value.title;

    createForm.post(
        `/app/consultations/${props.consultationId}/word-documents`,
        {
            preserveScroll: true,
            onSuccess: async () => {
                await nextTick();
                selectedDocument.value =
                    props.documents.find(
                        (document) =>
                            document.category === props.category &&
                            !existingIds.has(document.id),
                    ) ?? null;
            },
        },
    );
};

const convertForm = useForm<{ paper_size: 'A4' | 'A5' }>({
    paper_size: 'A4',
});

const openDocument = (document: ClinicalDocument) => {
    if (document.has_word_file) {
        selectedDocument.value = document;

        return;
    }

    convertingDocumentId.value = document.id;
    convertForm.paper_size = paperSize.value;
    convertForm.post(
        `/app/consultations/${props.consultationId}/word-documents/${document.id}/convert`,
        {
            preserveScroll: true,
            onSuccess: async () => {
                await nextTick();
                selectedDocument.value =
                    props.documents.find((item) => item.id === document.id) ??
                    null;
                convertingDocumentId.value = null;
            },
            onFinish: () => {
                convertingDocumentId.value = null;
            },
        },
    );
};
</script>

<template>
    <div class="grid min-h-0 gap-4 xl:grid-cols-[19rem_minmax(0,1fr)]">
        <aside
            class="flex min-h-0 flex-col rounded-xl border border-sidebar-border/70 bg-background dark:border-sidebar-border"
        >
            <div class="border-b p-3">
                <p
                    class="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    Modèles Word
                </p>
                <div class="relative mt-2">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <Input
                        v-model="search"
                        class="h-9 pl-8"
                        aria-label="Rechercher un modèle"
                        placeholder="Rechercher un modèle…"
                    />
                </div>
            </div>

            <div class="max-h-[62vh] min-h-0 flex-1 overflow-y-auto p-2">
                <section
                    v-for="group in groupedTemplates"
                    :key="group.label"
                    class="mb-3 last:mb-0"
                >
                    <p
                        class="px-2 py-1 text-[10px] font-semibold tracking-wide text-muted-foreground uppercase"
                    >
                        {{ group.label }}
                    </p>
                    <button
                        v-for="template in group.items"
                        :key="templateId(template)"
                        type="button"
                        class="mb-1 flex w-full items-start gap-2 rounded-md border px-2.5 py-2 text-left transition-colors"
                        :class="
                            selectedTemplateId === templateId(template)
                                ? 'border-primary bg-primary/5'
                                : 'border-transparent hover:bg-muted'
                        "
                        @click="selectTemplate(template)"
                    >
                        <FileType2
                            class="mt-0.5 size-4 shrink-0 text-primary"
                        />
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-medium">
                                {{ template.title }}
                            </span>
                            <span
                                v-if="template.description"
                                class="mt-0.5 line-clamp-2 block text-[11px] text-muted-foreground"
                            >
                                {{ template.description }}
                            </span>
                        </span>
                    </button>
                </section>

                <p
                    v-if="!availableTemplates.length"
                    class="px-3 py-10 text-center text-sm text-muted-foreground"
                >
                    Aucun modèle correspondant.
                </p>
            </div>
        </aside>

        <section class="min-w-0 space-y-4">
            <div
                class="rounded-xl border border-sidebar-border/70 bg-background p-5 dark:border-sidebar-border"
            >
                <div
                    class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between"
                >
                    <div>
                        <h3 class="text-base font-semibold">
                            {{ categoryLabel }}
                        </h3>
                        <p class="mt-1 text-sm text-muted-foreground">
                            {{ categoryDescription }}
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <span class="mr-1 text-xs text-muted-foreground">
                            Format d'impression
                        </span>
                        <div class="flex rounded-md border p-0.5">
                            <Button
                                size="sm"
                                class="h-7 px-3"
                                :variant="
                                    paperSize === 'A4' ? 'default' : 'ghost'
                                "
                                @click="paperSize = 'A4'"
                            >
                                A4
                            </Button>
                            <Button
                                size="sm"
                                class="h-7 px-3"
                                :variant="
                                    paperSize === 'A5' ? 'default' : 'ghost'
                                "
                                @click="paperSize = 'A5'"
                            >
                                A5
                            </Button>
                        </div>
                        <Button
                            :disabled="
                                !canEdit ||
                                !selectedTemplate ||
                                createForm.processing
                            "
                            @click="createDocument"
                        >
                            <Plus class="size-4" />
                            Créer et ouvrir
                        </Button>
                    </div>
                </div>

                <div
                    v-if="selectedTemplate"
                    class="mt-4 flex items-center gap-3 rounded-lg border bg-muted/20 p-3"
                >
                    <span
                        class="flex size-9 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary"
                    >
                        <FileText class="size-4" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium">
                            {{ selectedTemplate.title }}
                        </p>
                        <p class="truncate text-xs text-muted-foreground">
                            {{ selectedTemplate.group }} · {{ paperSize }} ·
                            édition dans ONLYOFFICE
                        </p>
                    </div>
                </div>

                <div
                    v-if="onlyoffice.warning"
                    class="mt-4 flex gap-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-xs text-amber-900"
                >
                    <AlertTriangle class="size-4 shrink-0" />
                    {{ onlyoffice.warning }}
                </div>
            </div>

            <div
                class="rounded-xl border border-sidebar-border/70 bg-background p-5 dark:border-sidebar-border"
            >
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <h4 class="font-semibold">Enregistrés</h4>
                        <p class="text-sm text-muted-foreground">
                            {{ savedDocuments.length }} document(s) pour ce
                            patient
                        </p>
                    </div>
                    <Clock3 class="size-5 text-muted-foreground" />
                </div>

                <div
                    v-if="savedDocuments.length"
                    class="mt-4 divide-y rounded-lg border"
                >
                    <button
                        v-for="document in savedDocuments"
                        :key="document.id"
                        type="button"
                        class="flex w-full items-center gap-3 p-3 text-left transition-colors hover:bg-muted/50"
                        :disabled="convertingDocumentId === document.id"
                        @click="openDocument(document)"
                    >
                        <FileText class="size-4 shrink-0 text-primary" />
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium">
                                {{ document.title }}
                            </span>
                            <span class="text-xs text-muted-foreground">
                                {{ document.created_at }} ·
                                {{ document.paper_size }}
                            </span>
                        </span>
                        <Badge variant="outline">
                            {{
                                document.has_word_file
                                    ? 'Ouvrir dans ONLYOFFICE'
                                    : 'Convertir en Word'
                            }}
                        </Badge>
                    </button>
                </div>
                <p
                    v-else
                    class="mt-4 rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground"
                >
                    Aucun document enregistré dans cette rubrique.
                </p>
            </div>
        </section>

        <OnlyOfficeClinicalEditor
            :document="selectedDocument"
            :settings="onlyoffice"
            @close="selectedDocument = null"
        />
    </div>
</template>
