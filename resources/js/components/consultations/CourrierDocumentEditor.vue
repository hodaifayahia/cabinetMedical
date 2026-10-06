<script setup lang="ts">
import { FileText, Printer, Save } from '@lucide/vue';
import { EditorContent } from '@tiptap/vue-3';
import { computed, ref } from 'vue';
import RichDocumentToolbar from '@/components/documents/RichDocumentToolbar.vue';
import { useDocumentFullscreen } from '@/components/documents/useDocumentFullscreen';
import { useRichDocumentEditor } from '@/components/documents/useRichDocumentEditor';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { printClinicalDocument } from '@/lib/printClinicalDocument';
import type { ClinicalDocumentTemplate } from '@/types/clinicalDocuments';

const props = defineProps<{
    template: ClinicalDocumentTemplate | null;
    paperSize: 'A4' | 'A5';
    content: string;
    patientName: string;
    patientAge: number | null;
    prescribedAt: string;
    doctorName: string | null;
    specialty: string | null;
    orderNumber: string | null;
    clinicName: string | null;
    phone: string | null;
    email: string | null;
    clinicAddress: string | null;
    city: string | null;
    footer: string | null;
    logoUrl: string | null;
    titleBox: boolean;
    showDate: boolean;
    canEdit: boolean;
}>();

const emit = defineEmits<{
    save: [];
    newCourrier: [];
    toggleTitleBox: [];
    updateContent: [value: string];
}>();

const root = ref<HTMLElement | null>(null);
const { isFullscreen, toggle: toggleFullscreen } = useDocumentFullscreen(root);

// The courrier body is edited with the same Word-like editor as the
// templates (Configuration › Modèles de documents). Only the body is
// editable: the letterhead, title and footer come from the cabinet identity.
const { editor } = useRichDocumentEditor({
    content: () => props.content,
    editable: () => props.canEdit,
    placeholder: 'Choisissez un modèle à gauche ou rédigez le courrier ici…',
    onUpdate: (html) => emit('updateContent', html),
});

const displayDate = (date: string): string => {
    if (!date) {
        return '—';
    }

    const [year, month, day] = date.slice(0, 10).split('-');

    return year && month && day ? day + '/' + month + '/' + year : date;
};

const displayDoctor = computed(() => props.doctorName?.trim() ?? '');
const clinicAddressLine = computed(() =>
    [props.clinicAddress, props.city]
        .map((value) => value?.trim())
        .filter(Boolean)
        .join(', '),
);
const contactFooter = computed(
    () =>
        props.footer?.trim() ||
        [props.phone, props.email, clinicAddressLine.value]
            .map((value) => value?.trim())
            .filter(Boolean)
            .join(' | '),
);

const printDocument = (paperSize: 'A4' | 'A5') => {
    printClinicalDocument(paperSize);
};
</script>

<template>
    <section
        ref="root"
        class="flex flex-col overflow-hidden bg-background"
        :class="
            isFullscreen
                ? 'fixed inset-0 z-[200] h-dvh w-screen'
                : 'rounded-xl border border-sidebar-border/70 dark:border-sidebar-border'
        "
    >
        <div
            class="border-b border-sidebar-border/70 bg-background dark:border-sidebar-border"
        >
            <div class="flex flex-wrap items-center gap-1.5 border-b px-3 py-2">
                <Button size="sm" class="h-8 rounded-full px-3 text-xs">
                    COURRIERS
                </Button>
                <Button variant="secondary" size="sm" class="h-8 px-3 text-xs">
                    Documents &amp; certificats
                </Button>
                <Badge
                    variant="outline"
                    class="h-8 max-w-56 gap-1 rounded-md px-3 text-xs"
                >
                    <FileText class="size-3.5 shrink-0" />
                    <span class="truncate">{{
                        template?.title ?? 'Courrier médical'
                    }}</span>
                </Badge>
                <Button
                    variant="outline"
                    size="sm"
                    class="h-8 border-amber-300 bg-amber-50 px-3 text-xs text-amber-800 hover:bg-amber-100 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-200"
                    @click="emit('toggleTitleBox')"
                >
                    {{ titleBox ? 'Retirer le cadre' : 'Restaurer le cadre' }}
                </Button>
                <div class="ml-auto flex flex-wrap items-center gap-1.5">
                    <Button
                        :disabled="!canEdit"
                        size="sm"
                        class="h-8 px-3 text-xs"
                        @click="emit('save')"
                    >
                        <Save class="size-3.5" />
                        Sauvegarder
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        class="h-8 px-2 text-xs"
                        @click="printDocument('A4')"
                    >
                        <Printer class="size-3.5" />
                        Imprimer A4
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        class="h-8 px-2 text-xs"
                        @click="printDocument('A5')"
                    >
                        <Printer class="size-3.5" />
                        Imprimer A5
                    </Button>
                    <Button
                        v-if="canEdit"
                        variant="outline"
                        size="sm"
                        class="h-8 px-3 text-xs"
                        @click="emit('newCourrier')"
                    >
                        <span class="text-base leading-none">+</span>
                        Nouveau courrier
                    </Button>
                </div>
            </div>

            <RichDocumentToolbar
                :editor="editor"
                :fullscreen="isFullscreen"
                :disabled="!canEdit"
                @toggle-fullscreen="toggleFullscreen"
            />
        </div>

        <div
            class="min-h-0 flex-1 overflow-auto bg-slate-100 p-4 sm:p-7 dark:bg-slate-900/50"
        >
            <article
                data-clinical-print-page
                aria-label="Courrier médical"
                class="mx-auto min-h-[720px] w-full max-w-[700px] bg-white px-8 py-7 text-[13px] leading-relaxed text-slate-900 shadow-[0_8px_30px_rgba(15,23,42,0.15)] outline-none sm:px-10 sm:py-8"
                :class="paperSize === 'A4' ? 'min-h-[920px]' : 'min-h-[720px]'"
            >
                <div class="relative">
                    <div
                        class="grid grid-cols-[1fr_auto_1fr] items-start gap-3 border-x border-slate-300 px-2 pb-3"
                    >
                        <div class="border-l-2 border-slate-700 pl-2">
                            <p v-if="displayDoctor" class="font-bold">
                                {{ displayDoctor }}
                            </p>
                            <p v-if="specialty" class="text-[10px]">
                                {{ specialty }}
                            </p>
                            <p v-if="orderNumber" class="mt-1 text-[9px]">
                                N° d'ordre : {{ orderNumber }}
                            </p>
                            <p v-if="clinicAddressLine" class="text-[9px]">
                                {{ clinicAddressLine }}
                            </p>
                        </div>
                        <div class="min-w-44 text-center">
                            <img
                                v-if="logoUrl"
                                :src="logoUrl"
                                alt=""
                                class="mx-auto mb-2 max-h-14 max-w-40 object-contain"
                            />
                            <p
                                v-if="clinicName"
                                class="text-[16px] leading-none font-black italic"
                            >
                                {{ clinicName }}
                            </p>
                            <div
                                v-if="clinicName && !logoUrl"
                                class="mt-4 flex items-center justify-center gap-1.5"
                            >
                                <span class="flex items-center gap-0.5">
                                    <span
                                        class="size-3 rounded-full bg-brand"
                                    />
                                    <span
                                        class="size-3 rounded-full bg-orange-500"
                                    />
                                    <span
                                        class="bg-brand-soft0 size-3 rounded-full"
                                    />
                                </span>
                                <span
                                    class="text-[15px] font-medium tracking-tight"
                                    >{{ clinicName }}</span
                                >
                            </div>
                        </div>
                        <div
                            class="border-r-2 border-slate-700 pr-2 text-right"
                            dir="rtl"
                        >
                            <p
                                v-if="specialty"
                                class="text-[14px] leading-none font-black"
                            >
                                {{ specialty }}
                            </p>
                            <p v-if="displayDoctor" class="mt-1 text-[10px]">
                                {{ displayDoctor }}
                            </p>
                            <p v-if="phone" class="mt-2 text-[9px]" dir="ltr">
                                Mob : {{ phone }}
                            </p>
                        </div>
                    </div>
                    <div
                        class="mt-2 grid grid-cols-[1fr_auto] items-end gap-4 border-b-2 border-slate-700 pb-2"
                    >
                        <div class="text-[10px] leading-tight">
                            <p>
                                Nom et prénom:
                                <span class="font-semibold">{{
                                    patientName || 'Nom Prénom'
                                }}</span>
                            </p>
                            <p v-if="clinicAddressLine">
                                {{ clinicAddressLine }}
                            </p>
                        </div>
                        <div class="text-right text-[10px] leading-tight">
                            <p v-if="showDate">
                                Le :
                                <span class="font-semibold">{{
                                    displayDate(prescribedAt)
                                }}</span>
                            </p>
                            <p>
                                Age :
                                <span class="font-semibold"
                                    >{{ patientAge ?? '—' }} ans</span
                                >
                            </p>
                        </div>
                    </div>
                </div>

                <div class="mt-5 text-center">
                    <h1
                        class="mx-auto max-w-[520px] text-center text-[16px] font-bold tracking-wide text-brand"
                        :class="
                            titleBox ? 'border border-slate-800 px-3 py-2' : ''
                        "
                    >
                        {{ template?.title || 'DOCUMENT MÉDICAL' }}
                    </h1>
                </div>

                <div class="relative mt-8 min-h-80">
                    <img
                        v-if="logoUrl"
                        :src="logoUrl"
                        alt=""
                        class="courrier-watermark"
                        aria-hidden="true"
                        contenteditable="false"
                    />
                    <EditorContent
                        :editor="editor"
                        class="courrier-content relative z-[1] min-h-80 text-[13px] leading-7"
                        aria-label="Contenu du courrier médical"
                    />
                </div>

                <footer
                    v-if="clinicName || contactFooter"
                    class="relative mt-16 border-t border-dashed border-slate-700 pt-2 text-center text-[10px] text-slate-600"
                >
                    <p v-if="clinicName">{{ clinicName }}</p>
                    <p v-if="contactFooter">{{ contactFooter }}</p>
                </footer>
            </article>
        </div>
    </section>
</template>

<style scoped>
.courrier-watermark {
    position: absolute;
    top: 8%;
    left: 50%;
    z-index: 0;
    width: min(58%, 20rem);
    max-height: 24rem;
    object-fit: contain;
    opacity: 0.055;
    pointer-events: none;
    transform: translateX(-50%);
    user-select: none;
}

.courrier-content :deep(h1),
.courrier-content :deep(h2),
.courrier-content :deep(h3) {
    margin: 1rem 0 0.5rem;
    font-weight: 700;
}

.courrier-content :deep(p) {
    margin: 0.55rem 0;
}

.courrier-content :deep(table) {
    margin: 1rem 0;
}
</style>
