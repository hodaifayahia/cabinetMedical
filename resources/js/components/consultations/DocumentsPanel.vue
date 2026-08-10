<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import {
    Download,
    File as FileIcon,
    FileText,
    FileUp,
    Image,
    RefreshCw,
    Trash2,
    UploadCloud,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { UploadedConsultationFile } from '@/types/clinicalDocuments';

type DocumentTab = 'documents' | 'previous';

const props = defineProps<{
    consultationId: number;
    files: UploadedConsultationFile[];
    canEdit: boolean;
}>();

const activeTab = ref<DocumentTab>('documents');
const selectedFile = ref<UploadedConsultationFile | null>(
    props.files[0] ?? null,
);
const fileInput = ref<HTMLInputElement | null>(null);
const fileInputKey = ref(0);
const previewFailed = ref(false);

const form = useForm<{
    file: File | null;
    title: string;
}>({
    file: null,
    title: '',
});

watch(
    () => props.files,
    (files) => {
        const refreshed = files.find(
            (file) => file.id === selectedFile.value?.id,
        );

        if (refreshed) {
            selectedFile.value = refreshed;
        } else {
            selectedFile.value = files[0] ?? null;
        }
    },
);

watch(
    () => selectedFile.value?.id,
    () => {
        previewFailed.value = false;
    },
);

const fileLabel = computed(() => form.file?.name ?? 'Choisir un fichier');

const openFilePicker = () => {
    fileInput.value?.click();
};

const chooseFile = (event: Event) => {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    form.file = file;
    form.title = file ? file.name.replace(/\.[^.]+$/, '') : '';
};

const resetForm = () => {
    form.reset();
    form.clearErrors();
    fileInputKey.value += 1;
};

const submitUpload = () => {
    if (!form.file || form.processing) {
        return;
    }

    form.post(`/app/consultations/${props.consultationId}/uploaded-documents`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: resetForm,
    });
};

const removeFile = (file: UploadedConsultationFile) => {
    if (!window.confirm(`Supprimer « ${file.title} » ?`)) {
        return;
    }

    router.delete(
        `/app/consultations/${props.consultationId}/uploaded-documents/${file.id}`,
        {
            preserveScroll: true,
            onSuccess: () => {
                if (selectedFile.value?.id === file.id) {
                    selectedFile.value = null;
                }
            },
        },
    );
};

const formatFileSize = (size: number | null): string => {
    if (!size) {
        return 'Taille inconnue';
    }

    if (size < 1024 * 1024) {
        return `${Math.max(1, Math.round(size / 1024))} Ko`;
    }

    return `${(size / (1024 * 1024)).toFixed(1)} Mo`;
};

const formatDate = (value: string | null): string => {
    if (!value) {
        return 'Date inconnue';
    }

    return new Intl.DateTimeFormat('fr-FR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    }).format(new Date(value));
};

const extension = (file: UploadedConsultationFile): string =>
    (file.original_filename ?? file.title).split('.').pop()?.toLowerCase() ??
    '';

const isImage = (file: UploadedConsultationFile): boolean =>
    (file.mime_type?.startsWith('image/') ?? false) ||
    ['jpg', 'jpeg', 'png', 'webp'].includes(extension(file));

const isPdf = (file: UploadedConsultationFile): boolean =>
    file.mime_type === 'application/pdf' || extension(file) === 'pdf';

const refreshPreview = () => {
    previewFailed.value = false;
    router.reload({ only: ['uploadedFiles'] });
};
</script>

<template>
    <section
        class="flex min-h-0 flex-1 flex-col overflow-hidden rounded-xl border border-sidebar-border/70 bg-background dark:border-sidebar-border"
    >
        <div
            class="flex flex-wrap items-center justify-between gap-3 border-b border-sidebar-border/70 px-5 py-4 dark:border-sidebar-border"
        >
            <div>
                <h2 class="text-2xl font-bold tracking-tight text-foreground">
                    Documents
                </h2>
                <div class="mt-2 h-1 w-20 rounded-full bg-brand" />
            </div>
            <div class="flex items-center gap-2">
                <input
                    :key="fileInputKey"
                    ref="fileInput"
                    class="sr-only"
                    type="file"
                    accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx,application/pdf,image/*"
                    @change="chooseFile"
                />
                <Button
                    v-if="canEdit"
                    class="bg-brand text-brand-foreground shadow-sm hover:bg-brand-deep hover:text-white"
                    @click="openFilePicker"
                >
                    <FileUp class="size-4" />
                    Ajouter un document
                </Button>
            </div>
        </div>

        <div
            class="flex shrink-0 border-b border-sidebar-border/70 px-5 dark:border-sidebar-border"
        >
            <button
                type="button"
                class="relative px-4 py-3 text-sm font-semibold transition"
                :class="
                    activeTab === 'documents'
                        ? 'bg-muted/70 text-foreground'
                        : 'text-muted-foreground hover:text-foreground'
                "
                @click="activeTab = 'documents'"
            >
                Documents
            </button>
            <button
                type="button"
                class="relative px-4 py-3 text-sm font-semibold transition"
                :class="
                    activeTab === 'previous'
                        ? 'bg-muted/70 text-foreground'
                        : 'text-muted-foreground hover:text-foreground'
                "
                @click="activeTab = 'previous'"
            >
                Documents précédents
            </button>
        </div>

        <div
            v-if="activeTab === 'documents'"
            class="grid min-h-0 flex-1 gap-5 p-5 lg:grid-cols-[minmax(260px,0.5fr)_minmax(0,1.5fr)]"
        >
            <div class="flex min-h-0 flex-col gap-4">
                <form
                    v-if="canEdit && form.file"
                    class="rounded-xl border border-dashed border-brand/50 bg-canvas p-4"
                    @submit.prevent="submitUpload"
                >
                    <div class="flex items-start gap-3">
                        <div
                            class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-brand-soft text-brand"
                        >
                            <FileText class="size-5" />
                        </div>
                        <div class="min-w-0">
                            <p
                                class="truncate text-sm font-semibold text-foreground"
                            >
                                {{ fileLabel }}
                            </p>
                            <p class="mt-0.5 text-xs text-muted-foreground">
                                Le fichier sera ajouté au dossier du patient.
                            </p>
                        </div>
                    </div>
                    <div class="mt-4 grid gap-2">
                        <Label for="uploaded-file-title">Titre</Label>
                        <Input
                            id="uploaded-file-title"
                            v-model="form.title"
                            placeholder="Titre du document"
                            maxlength="200"
                        />
                        <InputError :message="form.errors.title" />
                    </div>
                    <div class="mt-4 flex items-center justify-end gap-2">
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            @click="resetForm"
                        >
                            Annuler
                        </Button>
                        <Button
                            type="submit"
                            size="sm"
                            :disabled="form.processing"
                        >
                            <UploadCloud class="size-4" />
                            Importer
                        </Button>
                    </div>
                    <InputError :message="form.errors.file" />
                </form>

                <button
                    v-else-if="canEdit"
                    type="button"
                    class="flex min-h-28 items-center justify-center rounded-xl border border-dashed border-sidebar-border/80 bg-muted/20 p-4 text-center transition hover:border-brand hover:bg-canvas"
                    @click="openFilePicker"
                >
                    <span>
                        <UploadCloud class="mx-auto size-6 text-brand" />
                        <span
                            class="mt-2 block text-sm font-semibold text-foreground"
                        >
                            Importer un fichier
                        </span>
                        <span class="mt-1 block text-xs text-muted-foreground">
                            PDF, image, Word ou Excel · 20 Mo maximum
                        </span>
                    </span>
                </button>

                <div class="min-h-0 flex-1 overflow-y-auto">
                    <p
                        class="mb-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                    >
                        Fichiers du dossier ({{ files.length }})
                    </p>
                    <div v-if="files.length" class="space-y-2">
                        <button
                            v-for="file in files"
                            :key="file.id"
                            type="button"
                            class="flex w-full items-center gap-3 rounded-xl border p-3 text-left transition"
                            :class="
                                selectedFile?.id === file.id
                                    ? 'border-brand bg-brand-soft shadow-sm'
                                    : 'border-sidebar-border/70 hover:border-brand/50 hover:bg-muted/30 dark:border-sidebar-border'
                            "
                            @click="selectedFile = file"
                        >
                            <div
                                class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground"
                            >
                                <Image v-if="isImage(file)" class="size-4" />
                                <FileText v-else class="size-4" />
                            </div>
                            <span class="min-w-0 flex-1">
                                <span
                                    class="block truncate text-sm font-semibold text-foreground"
                                >
                                    {{ file.title }}
                                </span>
                                <span
                                    class="mt-0.5 block text-xs text-muted-foreground"
                                >
                                    {{ formatDate(file.created_at) }} ·
                                    {{ formatFileSize(file.file_size) }}
                                </span>
                            </span>
                            <Download
                                class="size-4 shrink-0 text-muted-foreground"
                            />
                        </button>
                    </div>
                    <p
                        v-else
                        class="rounded-xl border border-dashed px-3 py-8 text-center text-sm text-muted-foreground"
                    >
                        Aucun fichier importé.
                    </p>
                </div>
            </div>

            <div
                class="flex min-h-0 flex-col rounded-xl border border-sidebar-border/70 bg-muted/10 dark:border-sidebar-border"
            >
                <div
                    class="flex items-center justify-between gap-3 border-b border-sidebar-border/70 px-4 py-3 dark:border-sidebar-border"
                >
                    <div class="min-w-0">
                        <p class="truncate text-sm font-bold text-foreground">
                            {{
                                selectedFile?.title ??
                                'Aucun document sélectionné'
                            }}
                        </p>
                        <p
                            v-if="selectedFile"
                            class="mt-0.5 truncate text-xs text-muted-foreground"
                        >
                            {{ selectedFile.original_filename }}
                        </p>
                    </div>
                    <div
                        v-if="selectedFile"
                        class="flex shrink-0 items-center gap-1"
                    >
                        <a
                            :href="selectedFile.download_url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex size-8 items-center justify-center rounded-lg text-muted-foreground transition hover:bg-muted hover:text-foreground"
                            title="Ouvrir le document"
                        >
                            <Download class="size-4" />
                            <span class="sr-only">Ouvrir le document</span>
                        </a>
                        <button
                            v-if="canEdit"
                            type="button"
                            class="inline-flex size-8 items-center justify-center rounded-lg text-muted-foreground transition hover:bg-red-50 hover:text-red-600"
                            title="Supprimer le document"
                            @click="removeFile(selectedFile)"
                        >
                            <Trash2 class="size-4" />
                            <span class="sr-only">Supprimer le document</span>
                        </button>
                        <button
                            type="button"
                            class="inline-flex size-8 items-center justify-center rounded-lg text-muted-foreground transition hover:bg-muted hover:text-foreground"
                            title="Actualiser l'aperçu"
                            @click="refreshPreview"
                        >
                            <RefreshCw class="size-4" />
                            <span class="sr-only">Actualiser l'aperçu</span>
                        </button>
                    </div>
                </div>
                <div
                    class="flex min-h-[22rem] flex-1 items-center justify-center overflow-hidden p-3 lg:min-h-0"
                >
                    <img
                        v-if="
                            selectedFile &&
                            isImage(selectedFile) &&
                            !previewFailed
                        "
                        :src="selectedFile.download_url"
                        :alt="selectedFile.title"
                        class="max-h-full max-w-full rounded-lg object-contain shadow-sm"
                        @error="previewFailed = true"
                    />
                    <object
                        v-else-if="
                            selectedFile &&
                            isPdf(selectedFile) &&
                            !previewFailed
                        "
                        :data="selectedFile.download_url"
                        type="application/pdf"
                        :aria-label="selectedFile.title"
                        class="h-full min-h-[22rem] w-full rounded-lg border-0"
                    >
                        <p
                            class="p-6 text-center text-sm text-muted-foreground"
                        >
                            Le navigateur ne peut pas afficher ce PDF.
                            <a
                                :href="selectedFile.download_url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="font-semibold text-primary underline"
                                >Ouvrir le document</a
                            >
                        </p>
                    </object>
                    <div v-else-if="selectedFile" class="text-center">
                        <FileIcon class="mx-auto size-12 text-brand" />
                        <p class="mt-3 text-sm font-semibold text-foreground">
                            Aperçu non disponible pour ce format.
                        </p>
                        <a
                            :href="selectedFile.download_url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="mt-3 inline-flex items-center gap-2 rounded-lg bg-brand px-3 py-2 text-sm font-semibold text-white hover:bg-brand-deep"
                        >
                            <Download class="size-4" />
                            Télécharger le fichier
                        </a>
                    </div>
                    <div v-else class="text-center text-muted-foreground">
                        <FileText class="mx-auto size-12 opacity-40" />
                        <p class="mt-3 text-sm">
                            Sélectionnez un document pour l'afficher.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <div v-else class="min-h-0 flex-1 overflow-y-auto p-5">
            <div
                v-if="files.length"
                class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3"
            >
                <button
                    v-for="file in files"
                    :key="file.id"
                    type="button"
                    class="rounded-xl border border-sidebar-border/70 bg-background p-4 text-left transition hover:-translate-y-px hover:border-brand/60 hover:shadow-sm dark:border-sidebar-border"
                    @click="
                        selectedFile = file;
                        activeTab = 'documents';
                    "
                >
                    <div class="flex items-start justify-between gap-3">
                        <div
                            class="flex size-10 items-center justify-center rounded-lg bg-brand-soft text-brand"
                        >
                            <Image v-if="isImage(file)" class="size-5" />
                            <FileText v-else class="size-5" />
                        </div>
                        <span class="text-xs text-muted-foreground">{{
                            formatDate(file.created_at)
                        }}</span>
                    </div>
                    <p class="mt-4 truncate text-sm font-bold text-foreground">
                        {{ file.title }}
                    </p>
                    <p class="mt-1 truncate text-xs text-muted-foreground">
                        {{ file.original_filename }}
                    </p>
                </button>
            </div>
            <p
                v-else
                class="rounded-xl border border-dashed px-3 py-12 text-center text-sm text-muted-foreground"
            >
                Aucun document précédent.
            </p>
        </div>
    </section>
</template>
