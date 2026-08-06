<script setup lang="ts">
import { AlertTriangle, Download, FileText, Printer } from '@lucide/vue';
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type {
    ClinicalDocument,
    ClinicalOnlyOfficeSettings,
} from '@/types/clinicalDocuments';

type OnlyOfficeEditor = {
    destroyEditor: () => void;
    requestClose?: () => void;
};

type DocsWindow = Window & {
    DocsAPI?: {
        DocEditor: new (
            id: string,
            config: Record<string, unknown>,
        ) => OnlyOfficeEditor;
    };
};

const props = defineProps<{
    document: ClinicalDocument | null;
    settings: ClinicalOnlyOfficeSettings;
    inline?: boolean;
}>();

const emit = defineEmits<{
    close: [];
}>();

const editor = ref<OnlyOfficeEditor | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);
let scriptPromise: Promise<void> | null = null;

const docsWindow = window as DocsWindow;

const loadScript = (): Promise<void> => {
    if (docsWindow.DocsAPI) {
        return Promise.resolve();
    }

    if (scriptPromise) {
        return scriptPromise;
    }

    scriptPromise = new Promise<void>((resolve, reject) => {
        const existing = document.querySelector<HTMLScriptElement>(
            'script[data-onlyoffice-api="true"]',
        );

        if (existing) {
            existing.addEventListener('load', () => resolve(), { once: true });
            existing.addEventListener(
                'error',
                () => reject(new Error('ONLYOFFICE est inaccessible.')),
                { once: true },
            );

            return;
        }

        const script = document.createElement('script');
        script.src = `${props.settings.url}/web-apps/apps/api/documents/api.js`;
        script.dataset.onlyofficeApi = 'true';
        script.onload = () => resolve();
        script.onerror = () =>
            reject(
                new Error(
                    'Le serveur de documents ONLYOFFICE est inaccessible.',
                ),
            );
        document.head.appendChild(script);
    }).catch((reason: unknown) => {
        scriptPromise = null;

        throw reason;
    });

    return scriptPromise;
};

const destroy = () => {
    editor.value?.destroyEditor();
    editor.value = null;
    loading.value = false;
};

const close = () => {
    destroy();
    error.value = null;
    emit('close');
};

const requestClose = () => {
    if (editor.value?.requestClose) {
        editor.value.requestClose();

        return;
    }

    close();
};

const mountEditor = async (clinicalDocument: ClinicalDocument) => {
    destroy();
    loading.value = true;
    error.value = null;

    if (!clinicalDocument.editor_config) {
        loading.value = false;
        error.value =
            'Ce document enregistré doit d’abord être converti au format Word.';

        return;
    }

    try {
        await nextTick();
        await loadScript();
        await nextTick();

        if (!docsWindow.DocsAPI) {
            throw new Error('L’API de l’éditeur ONLYOFFICE est indisponible.');
        }

        editor.value = new docsWindow.DocsAPI.DocEditor(
            `clinical-onlyoffice-${clinicalDocument.id}`,
            {
                ...clinicalDocument.editor_config,
                events: {
                    onAppReady: () => {
                        loading.value = false;
                    },
                    onDocumentReady: () => {
                        loading.value = false;
                    },
                    onError: (event: {
                        data?: {
                            errorCode?: number;
                            errorDescription?: string;
                        };
                    }) => {
                        loading.value = false;
                        error.value =
                            event.data?.errorDescription ??
                            `ONLYOFFICE n’a pas pu ouvrir ce document${event.data?.errorCode ? ` (erreur ${event.data.errorCode})` : ''}.`;
                    },
                    onRequestClose: close,
                },
            },
        );
    } catch (reason) {
        loading.value = false;
        error.value =
            reason instanceof Error
                ? reason.message
                : 'Impossible de charger ONLYOFFICE.';
    }
};

watch(
    () => props.document,
    (clinicalDocument) => {
        if (clinicalDocument) {
            void mountEditor(clinicalDocument);
        } else {
            destroy();
        }
    },
    { immediate: true },
);

onBeforeUnmount(destroy);
</script>

<template>
    <section
        v-if="props.inline && document"
        aria-label="Éditeur de document ONLYOFFICE"
        class="relative flex h-[min(72dvh,760px)] min-h-[520px] flex-col overflow-hidden rounded-xl border bg-background shadow-sm"
    >
        <header
            class="flex min-h-12 items-center justify-between gap-3 border-b px-3 py-2"
        >
            <div class="flex min-w-0 items-center gap-2.5">
                <span
                    class="flex size-8 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary"
                >
                    <FileText class="size-4" />
                </span>
                <div class="min-w-0">
                    <p class="truncate text-sm font-semibold">
                        {{ document.title }}
                    </p>
                    <p
                        class="mt-0.5 flex items-center gap-2 text-[11px] text-muted-foreground"
                    >
                        <span>{{ document.created_at }}</span>
                        <Badge
                            variant="outline"
                            class="px-1.5 py-0 text-[10px]"
                        >
                            {{ document.paper_size }}
                        </Badge>
                        <span class="hidden sm:inline"
                            >Édition directe dans ONLYOFFICE</span
                        >
                    </p>
                </div>
            </div>

            <div class="flex shrink-0 items-center gap-2">
                <Button
                    v-if="document.download_url"
                    variant="outline"
                    size="sm"
                    class="h-8"
                    as-child
                >
                    <a
                        :href="document.download_url"
                        target="_blank"
                        aria-label="Télécharger le document"
                    >
                        <Download class="size-3.5" />
                        <span class="hidden sm:inline">Télécharger</span>
                    </a>
                </Button>
                <Button
                    variant="ghost"
                    size="sm"
                    class="h-8"
                    aria-label="Fermer l’éditeur"
                    @click="close"
                >
                    Fermer
                </Button>
            </div>
        </header>

        <div class="relative min-h-0 flex-1 overflow-hidden bg-muted/30">
            <div
                v-if="error"
                class="absolute inset-0 z-20 flex flex-col items-center justify-center gap-3 bg-background p-8 text-center"
            >
                <AlertTriangle class="size-8 text-destructive" />
                <p class="font-medium">Impossible d'ouvrir le document Word</p>
                <p class="max-w-xl text-sm text-muted-foreground">
                    {{ error }}
                </p>
            </div>
            <div
                v-if="loading"
                class="absolute inset-0 z-10 flex items-center justify-center bg-background/90 text-sm text-muted-foreground"
            >
                Chargement de ONLYOFFICE…
            </div>
            <div
                :id="`clinical-onlyoffice-${document.id}`"
                class="h-full min-h-0 w-full"
            />
        </div>
    </section>

    <Dialog
        v-else
        :open="document !== null"
        @update:open="
            (open) => {
                if (!open) requestClose();
            }
        "
    >
        <DialogContent
            class="h-[calc(100dvh-1rem)] w-[calc(100vw-1rem)] grid-rows-[auto_minmax(0,1fr)] gap-0 overflow-hidden p-0 sm:max-w-[calc(100vw-1rem)]"
            :show-close-button="false"
            @escape-key-down.prevent="requestClose"
            @pointer-down-outside.prevent
        >
            <DialogHeader
                class="grid min-h-12 grid-cols-[minmax(0,1fr)_auto] items-center gap-3 border-b px-3 py-2 text-left"
            >
                <div class="flex min-w-0 items-center gap-2.5">
                    <span
                        class="flex size-8 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary"
                    >
                        <FileText class="size-4" />
                    </span>
                    <div class="min-w-0">
                        <DialogTitle class="truncate text-sm leading-tight">
                            {{ document?.title }}
                        </DialogTitle>
                        <DialogDescription
                            class="mt-0.5 flex items-center gap-2 text-[11px] leading-tight"
                        >
                            <span>{{ document?.created_at }}</span>
                            <Badge
                                variant="outline"
                                class="px-1.5 py-0 text-[10px]"
                            >
                                {{ document?.paper_size }}
                            </Badge>
                        </DialogDescription>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <span
                        class="hidden items-center gap-1.5 text-xs text-muted-foreground lg:flex"
                    >
                        <Printer class="size-3.5" />
                        Pour imprimer : Fichier → Imprimer dans ONLYOFFICE
                    </span>
                    <Button
                        v-if="document?.download_url"
                        variant="outline"
                        size="sm"
                        class="h-8"
                        as-child
                    >
                        <a
                            :href="document.download_url"
                            target="_blank"
                            aria-label="Télécharger le document"
                        >
                            <Download class="size-3.5" />
                            <span class="hidden sm:inline">Télécharger</span>
                        </a>
                    </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        class="h-8"
                        aria-label="Fermer l’éditeur"
                        @click="requestClose"
                    >
                        Fermer
                    </Button>
                </div>
            </DialogHeader>

            <div class="relative h-full min-h-0 overflow-hidden bg-muted/30">
                <div
                    v-if="error"
                    class="absolute inset-0 z-20 flex flex-col items-center justify-center gap-3 bg-background p-8 text-center"
                >
                    <AlertTriangle class="size-8 text-destructive" />
                    <p class="font-medium">
                        Impossible d’ouvrir le document Word
                    </p>
                    <p class="max-w-xl text-sm text-muted-foreground">
                        {{ error }}
                    </p>
                </div>
                <div
                    v-if="loading"
                    class="absolute inset-0 z-10 flex items-center justify-center bg-background/90 text-sm text-muted-foreground"
                >
                    Chargement de ONLYOFFICE…
                </div>
                <div
                    v-if="document"
                    :id="`clinical-onlyoffice-${document.id}`"
                    class="h-full min-h-0 w-full"
                />
            </div>
        </DialogContent>
    </Dialog>
</template>
