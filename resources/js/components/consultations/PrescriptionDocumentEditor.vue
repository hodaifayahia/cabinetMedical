<script setup lang="ts">
import {
    AlignCenter,
    AlignJustify,
    AlignLeft,
    AlignRight,
    Bold,
    FileText,
    Heading1,
    Heading2,
    Highlighter,
    Image,
    Italic,
    Link,
    List,
    ListOrdered,
    Minus,
    Palette,
    Printer,
    Redo2,
    Save,
    Sparkles,
    Strikethrough,
    Table2,
    Type,
    Underline,
    Undo2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { printClinicalDocument } from '@/lib/printClinicalDocument';
import type { ClinicalDocumentTemplate } from '@/types/clinicalDocuments';

type PrescriptionItem = {
    medication: string;
    dosage: string;
    duration: string;
    instructions: string;
};

const props = defineProps<{
    template: ClinicalDocumentTemplate | null;
    paperSize: 'A4' | 'A5';
    prescribedAt: string;
    items: PrescriptionItem[];
    notes: string;
    patientName: string;
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
    newModel: [];
    toggleTitleBox: [];
}>();

const pageEditor = ref<HTMLElement | null>(null);
const fontFamily = ref('Times New Roman');
const fontSize = ref('12pt');
const variable = ref('');

const execute = (command: string, value?: string) => {
    pageEditor.value?.focus();
    window.document.execCommand(command, false, value);
};

const insertVariable = () => {
    if (variable.value) {
        execute('insertText', `{{${variable.value}}}`);
        variable.value = '';
    }
};

const insertTable = () => {
    execute(
        'insertHTML',
        '<table style="width:100%;border-collapse:collapse"><tbody><tr><td style="border:1px solid #333;padding:6px">Médicament</td><td style="border:1px solid #333;padding:6px">Posologie</td></tr><tr><td style="border:1px solid #333;padding:6px"> </td><td style="border:1px solid #333;padding:6px"> </td></tr></tbody></table><p><br></p>',
    );
};

const insertLink = () => {
    const url = window.prompt('Adresse du lien');

    if (url) {
        execute('createLink', url);
    }
};

const insertImage = () => {
    const url = window.prompt('URL de l’image');

    if (url) {
        execute('insertImage', url);
    }
};

const displayDate = (date: string): string => {
    if (!date) {
        return '—';
    }

    const [year, month, day] = date.slice(0, 10).split('-');

    return year && month && day ? `${day}/${month}/${year}` : date;
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
        class="overflow-hidden rounded-xl border border-sidebar-border/70 bg-background dark:border-sidebar-border"
    >
        <div
            class="border-b border-sidebar-border/70 bg-background dark:border-sidebar-border"
        >
            <div class="flex flex-wrap items-center gap-1.5 border-b px-3 py-2">
                <Button size="sm" class="h-8 rounded-full px-3 text-xs">
                    ORDONNANCES
                </Button>
                <Button variant="secondary" size="sm" class="h-8 px-3 text-xs">
                    Ordonnance
                </Button>
                <Badge
                    variant="outline"
                    class="h-8 gap-1 rounded-md px-3 text-xs"
                >
                    <FileText class="size-3.5" />
                    {{ template?.title ?? 'Modèle A5 complet' }}
                </Badge>
                <Button
                    variant="outline"
                    size="sm"
                    class="h-8 border-amber-300 bg-amber-50 px-3 text-xs text-amber-800 hover:bg-amber-100 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-200"
                    @click="emit('toggleTitleBox')"
                >
                    Restaurer titre encadré
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
                        @click="emit('newModel')"
                    >
                        <span class="text-base leading-none">+</span>
                        Nouveau modèle
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        class="h-8 border-red-200 px-3 text-xs text-red-600 hover:bg-red-50 dark:border-red-900 dark:text-red-300"
                    >
                        Supprimer
                    </Button>
                </div>
            </div>

            <div
                class="flex flex-wrap items-center gap-1 border-b bg-slate-50/80 px-3 py-2 dark:bg-slate-950/30"
            >
                <select
                    v-model="fontFamily"
                    aria-label="Police"
                    class="h-8 w-36 rounded-md border bg-background px-2 text-xs"
                    title="Police"
                    @change="execute('fontName', fontFamily)"
                >
                    <option>Times New Roman</option>
                    <option>Arial</option>
                    <option>Calibri</option>
                    <option>Georgia</option>
                </select>
                <select
                    v-model="fontSize"
                    aria-label="Taille"
                    class="h-8 w-16 rounded-md border bg-background px-2 text-xs"
                    title="Taille"
                    @change="
                        execute(
                            'fontSize',
                            fontSize === '10pt'
                                ? '2'
                                : fontSize === '14pt'
                                  ? '5'
                                  : '3',
                        )
                    "
                >
                    <option value="10pt">10pt</option>
                    <option value="12pt">12pt</option>
                    <option value="14pt">14pt</option>
                    <option value="16pt">16pt</option>
                </select>
                <span class="mx-1 h-5 w-px bg-border" />
                <Button
                    variant="ghost"
                    size="icon"
                    class="size-8"
                    aria-label="Gras"
                    title="Gras"
                    @mousedown.prevent
                    @click="execute('bold')"
                    ><Bold class="size-4"
                /></Button>
                <Button
                    variant="ghost"
                    size="icon"
                    class="size-8 italic"
                    aria-label="Italique"
                    title="Italique"
                    @mousedown.prevent
                    @click="execute('italic')"
                    ><Italic class="size-4"
                /></Button>
                <Button
                    variant="ghost"
                    size="icon"
                    class="size-8 underline"
                    aria-label="Souligné"
                    title="Souligné"
                    @mousedown.prevent
                    @click="execute('underline')"
                    ><Underline class="size-4"
                /></Button>
                <Button
                    variant="ghost"
                    size="icon"
                    class="size-8"
                    aria-label="Barré"
                    title="Barré"
                    @mousedown.prevent
                    @click="execute('strikeThrough')"
                    ><Strikethrough class="size-4"
                /></Button>
                <label
                    class="relative flex size-8 cursor-pointer items-center justify-center rounded-md hover:bg-muted"
                    title="Couleur du texte"
                >
                    <Palette class="size-4" />
                    <input
                        class="absolute inset-0 cursor-pointer opacity-0"
                        type="color"
                        aria-label="Couleur du texte"
                        @change="
                            execute(
                                'foreColor',
                                ($event.target as HTMLInputElement).value,
                            )
                        "
                    />
                </label>
                <label
                    class="relative flex size-8 cursor-pointer items-center justify-center rounded-md hover:bg-muted"
                    title="Surlignage"
                >
                    <Highlighter class="size-4" />
                    <input
                        class="absolute inset-0 cursor-pointer opacity-0"
                        type="color"
                        aria-label="Surlignage"
                        value="#fff2a8"
                        @change="
                            execute(
                                'hiliteColor',
                                ($event.target as HTMLInputElement).value,
                            )
                        "
                    />
                </label>
                <span class="mx-1 h-5 w-px bg-border" />
                <Button
                    variant="ghost"
                    size="icon"
                    class="size-8"
                    aria-label="Titre 1"
                    title="Titre 1"
                    @mousedown.prevent
                    @click="execute('formatBlock', 'h1')"
                    ><Heading1 class="size-4"
                /></Button>
                <Button
                    variant="ghost"
                    size="icon"
                    class="size-8"
                    aria-label="Titre 2"
                    title="Titre 2"
                    @mousedown.prevent
                    @click="execute('formatBlock', 'h2')"
                    ><Heading2 class="size-4"
                /></Button>
                <Button
                    variant="ghost"
                    size="icon"
                    class="size-8"
                    aria-label="Texte normal"
                    title="Texte normal"
                    @mousedown.prevent
                    @click="execute('formatBlock', 'p')"
                    ><Type class="size-4"
                /></Button>
                <span class="mx-1 h-5 w-px bg-border" />
                <Button
                    variant="ghost"
                    size="icon"
                    class="size-8"
                    aria-label="Aligner à gauche"
                    title="Aligner à gauche"
                    @mousedown.prevent
                    @click="execute('justifyLeft')"
                    ><AlignLeft class="size-4"
                /></Button>
                <Button
                    variant="ghost"
                    size="icon"
                    class="size-8"
                    aria-label="Centrer"
                    title="Centrer"
                    @mousedown.prevent
                    @click="execute('justifyCenter')"
                    ><AlignCenter class="size-4"
                /></Button>
                <Button
                    variant="ghost"
                    size="icon"
                    class="size-8"
                    aria-label="Aligner à droite"
                    title="Aligner à droite"
                    @mousedown.prevent
                    @click="execute('justifyRight')"
                    ><AlignRight class="size-4"
                /></Button>
                <Button
                    variant="ghost"
                    size="icon"
                    class="size-8"
                    aria-label="Justifier"
                    title="Justifier"
                    @mousedown.prevent
                    @click="execute('justifyFull')"
                    ><AlignJustify class="size-4"
                /></Button>
            </div>

            <div
                class="flex flex-wrap items-center gap-1 bg-slate-50/80 px-3 pb-2 dark:bg-slate-950/30"
            >
                <Button
                    variant="ghost"
                    size="icon"
                    class="size-8"
                    aria-label="Liste à puces"
                    title="Liste à puces"
                    @mousedown.prevent
                    @click="execute('insertUnorderedList')"
                    ><List class="size-4"
                /></Button>
                <Button
                    variant="ghost"
                    size="icon"
                    class="size-8"
                    aria-label="Liste numérotée"
                    title="Liste numérotée"
                    @mousedown.prevent
                    @click="execute('insertOrderedList')"
                    ><ListOrdered class="size-4"
                /></Button>
                <Button
                    variant="ghost"
                    size="icon"
                    class="size-8"
                    aria-label="Ligne horizontale"
                    title="Ligne horizontale"
                    @mousedown.prevent
                    @click="execute('insertHorizontalRule')"
                    ><Minus class="size-4"
                /></Button>
                <span class="mx-1 h-5 w-px bg-border" />
                <Button
                    variant="ghost"
                    size="icon"
                    class="size-8"
                    aria-label="Insérer un tableau"
                    title="Insérer un tableau"
                    @mousedown.prevent
                    @click="insertTable"
                    ><Table2 class="size-4"
                /></Button>
                <Button
                    variant="ghost"
                    size="icon"
                    class="size-8"
                    aria-label="Insérer une image"
                    title="Insérer une image"
                    @mousedown.prevent
                    @click="insertImage"
                    ><Image class="size-4"
                /></Button>
                <Button
                    variant="ghost"
                    size="icon"
                    class="size-8"
                    aria-label="Insérer un lien"
                    title="Insérer un lien"
                    @mousedown.prevent
                    @click="insertLink"
                    ><Link class="size-4"
                /></Button>
                <select
                    v-model="variable"
                    aria-label="Insérer une variable"
                    class="ml-1 h-8 min-w-32 rounded-md border bg-background px-2 text-xs"
                    title="Insérer une variable"
                    @change="insertVariable"
                >
                    <option value="">Variable</option>
                    <option value="patient.name">Patient · Nom</option>
                    <option value="patient.birth_date">
                        Patient · Date de naissance
                    </option>
                    <option value="doctor.name">Médecin · Nom</option>
                    <option value="prescription.date">Ordonnance · Date</option>
                </select>
                <span class="mx-1 h-5 w-px bg-border" />
                <Button
                    variant="ghost"
                    size="icon"
                    class="size-8"
                    aria-label="Annuler"
                    title="Annuler"
                    @mousedown.prevent
                    @click="execute('undo')"
                    ><Undo2 class="size-4"
                /></Button>
                <Button
                    variant="ghost"
                    size="icon"
                    class="size-8"
                    aria-label="Rétablir"
                    title="Rétablir"
                    @mousedown.prevent
                    @click="execute('redo')"
                    ><Redo2 class="size-4"
                /></Button>
                <Button
                    variant="outline"
                    size="sm"
                    class="ml-1 h-8 gap-1.5 px-3 text-xs text-primary"
                    aria-label="Assistant IA"
                    title="Assistant IA"
                >
                    <Sparkles class="size-3.5" />
                    IA
                </Button>
            </div>
        </div>

        <div
            class="min-h-0 overflow-auto bg-slate-100 p-4 sm:p-7 dark:bg-slate-900/50"
        >
            <article
                ref="pageEditor"
                data-clinical-print-page
                contenteditable="true"
                aria-label="Contenu de l’ordonnance"
                spellcheck="false"
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
                                Age : <span class="font-semibold">— ans</span>
                            </p>
                        </div>
                    </div>
                </div>

                <div class="mt-5 text-center">
                    <h1
                        class="mx-auto max-w-[430px] text-center text-[16px] font-bold tracking-wide text-brand"
                        :class="
                            titleBox ? 'border border-slate-800 px-3 py-2' : ''
                        "
                    >
                        ORDONNANCE
                    </h1>
                </div>

                <div class="relative mt-6">
                    <img
                        v-if="logoUrl"
                        :src="logoUrl"
                        alt=""
                        class="prescription-watermark"
                        aria-hidden="true"
                        contenteditable="false"
                    />
                    <div
                        v-if="items.some((item) => item.medication.trim())"
                        class="relative space-y-4"
                    >
                        <div
                            v-for="(item, index) in items"
                            :key="index"
                            class="grid grid-cols-[minmax(0,1fr)_auto] gap-x-4"
                        >
                            <div class="min-w-0">
                                <p class="font-bold uppercase">
                                    {{ index + 1 }}.
                                    {{
                                        item.medication ||
                                        'Médicament à compléter'
                                    }}
                                </p>
                                <p v-if="item.dosage" class="pl-3 text-[11px]">
                                    {{ item.dosage }}
                                </p>
                                <p
                                    v-if="item.instructions"
                                    class="pl-3 text-[11px] text-slate-700"
                                >
                                    {{ item.instructions }}
                                </p>
                            </div>
                            <p
                                class="text-right text-[11px] font-medium whitespace-nowrap"
                            >
                                Qté: <span>{{ item.duration }}</span>
                            </p>
                        </div>
                    </div>
                    <p v-else class="relative mt-4 text-slate-400">
                        Ajoutez un médicament depuis le formulaire pour le voir
                        apparaître ici.
                    </p>
                    <p
                        v-if="notes"
                        class="relative mt-7 border-t border-dashed border-slate-400 pt-3"
                    >
                        {{ notes }}
                    </p>
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
.prescription-watermark {
    position: absolute;
    top: 15%;
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
</style>
