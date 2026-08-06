<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ArrowLeft, Clock3, Pill, Plus, Search, Trash2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import PrescriptionDocumentEditor from '@/components/consultations/PrescriptionDocumentEditor.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import type {
    ClinicalDocumentTemplate,
    DocumentBranding,
    MedicationOption,
} from '@/types/clinicalDocuments';

type Item = {
    medication: string;
    dosage: string;
    duration: string;
    instructions: string;
};

type PrescriptionRow = {
    id: number;
    document_id: number | null;
    template_id: string | null;
    prescribed_at: string | null;
    items: Item[];
    notes: string | null;
};

const props = defineProps<{
    consultationId: number;
    prescriptions: PrescriptionRow[];
    medications: MedicationOption[];
    templates: ClinicalDocumentTemplate[];
    modelTemplateId: string | null;
    prescriptionDate: string;
    showPrescriptionDate: boolean;
    patient: { full_name: string };
    cabinet: DocumentBranding;
    canEdit: boolean;
}>();

const emit = defineEmits<{
    'update:modelTemplateId': [value: string | null];
    'update:prescriptionDate': [value: string];
    'update:showPrescriptionDate': [value: boolean];
}>();

const today = new Date().toISOString().slice(0, 10);
const mode = ref<'history' | 'editor'>('history');
const medicationSearch = ref('');
const medicationSearchFocused = ref(false);
const titleBox = ref(false);
const paperSize = ref<'A4' | 'A5'>('A5');

const selectedTemplateId = computed({
    get: () => props.modelTemplateId,
    set: (value: string | null) => emit('update:modelTemplateId', value),
});

const blankItem = (): Item => ({
    medication: '',
    dosage: '',
    duration: '',
    instructions: '',
});

const templateId = (template: ClinicalDocumentTemplate): string =>
    `${template.source}:${template.key}`;

const ordonnanceTemplates = computed(() =>
    props.templates.filter(
        (template) =>
            template.category === 'ordonnance' ||
            template.category === 'general',
    ),
);

const selectedTemplate = computed(
    () =>
        ordonnanceTemplates.value.find(
            (template) => templateId(template) === selectedTemplateId.value,
        ) ?? null,
);

const medicationSearchResults = computed(() => {
    const query = medicationSearch.value.trim().toLocaleLowerCase();

    if (!query) {
        return [];
    }

    const selectedMedicationNames = new Set(
        form.items.map((item) => item.medication.trim().toLocaleLowerCase()),
    );

    return props.medications
        .filter((medication) => {
            const searchableText =
                `${medication.name} ${medication.dci ?? ''} ${medication.form ?? ''} ${medication.dosage ?? ''}`.toLocaleLowerCase();

            return (
                searchableText.includes(query) &&
                !selectedMedicationNames.has(
                    medication.name.trim().toLocaleLowerCase(),
                )
            );
        })
        .slice(0, 8);
});

watch(
    ordonnanceTemplates,
    (templates) => {
        if (
            !templates.some(
                (template) => templateId(template) === selectedTemplateId.value,
            )
        ) {
            const first = templates[0];
            selectedTemplateId.value = first ? templateId(first) : null;
            paperSize.value = first?.default_paper_size ?? 'A5';
        }
    },
    { immediate: true },
);

watch(selectedTemplateId, () => {
    if (selectedTemplate.value) {
        paperSize.value = selectedTemplate.value.default_paper_size;
    }
});

const form = useForm<{
    prescribed_at: string;
    notes: string;
    items: Item[];
    source: 'built_in';
    template_key: string | null;
    paper_size: 'A4' | 'A5';
}>({
    prescribed_at: today,
    notes: '',
    items: [],
    source: 'built_in',
    template_key: 'ordonnance',
    paper_size: 'A5',
});

watch(
    () => props.prescriptionDate,
    (date) => {
        if (date && form.prescribed_at !== date) {
            form.prescribed_at = date;
        }
    },
    { immediate: true },
);

watch(
    () => form.prescribed_at,
    (date) => {
        if (date !== props.prescriptionDate) {
            emit('update:prescriptionDate', date);
        }
    },
);

const medicationDetails = (medication: MedicationOption): string =>
    [medication.dci, medication.form, medication.dosage]
        .filter(Boolean)
        .join(' · ');

const removeItem = (index: number) => {
    form.items.splice(index, 1);
};

const medicationQuantity = (medication: MedicationOption): string => {
    const productForm = medication.form?.trim();

    if (!productForm) {
        return '';
    }

    const normalizedForm = productForm.toLocaleLowerCase();

    if (normalizedForm.includes('sachet')) {
        return '1 sachet';
    }

    if (
        normalizedForm.includes('comprim') ||
        normalizedForm.includes('gelul') ||
        normalizedForm.includes('capsul')
    ) {
        return '1 boîte';
    }

    return `1 ${productForm}`;
};

const selectMedication = (item: Item, medication: MedicationOption) => {
    item.medication = medication.name;

    if (!item.dosage.trim()) {
        item.dosage = medication.dosage?.trim() ?? '';
    }

    if (!item.duration.trim()) {
        item.duration = medicationQuantity(medication);
    }
};

const selectMedicationFromSearch = (medication: MedicationOption) => {
    if (mode.value === 'history') {
        startNew();
    }

    const item = blankItem();
    selectMedication(item, medication);
    form.items.push(item);
    medicationSearch.value = '';
    medicationSearchFocused.value = false;
};

const startNew = () => {
    form.reset();
    form.items = [];
    form.prescribed_at = today;
    form.clearErrors();
    medicationSearch.value = '';
    medicationSearchFocused.value = false;
    mode.value = 'editor';
};

const restoreModelPreset = (modelId: string | null) => {
    if (!modelId) {
        return;
    }

    const preset = props.prescriptions.find(
        (prescription) => prescription.template_id === modelId,
    );

    if (!preset) {
        return;
    }

    form.items = preset.items.length
        ? preset.items.map((item) => ({ ...blankItem(), ...item }))
        : [];
    form.notes = preset.notes ?? '';
};

watch(selectedTemplateId, (modelId, previousModelId) => {
    if (!modelId || modelId === previousModelId) {
        return;
    }

    if (mode.value === 'history' && props.canEdit) {
        startNew();
        restoreModelPreset(modelId);
    } else if (mode.value === 'editor') {
        restoreModelPreset(modelId);
    }
});

const displayDate = (date: string | null): string => {
    if (!date) {
        return '—';
    }

    const [year, month, day] = date.slice(0, 10).split('-');

    return year && month && day ? `${day}/${month}/${year}` : date;
};

const openPrescription = (prescription: PrescriptionRow) => {
    form.prescribed_at = prescription.prescribed_at?.slice(0, 10) ?? today;
    form.items = prescription.items.length
        ? prescription.items.map((item) => ({ ...blankItem(), ...item }))
        : [];
    form.notes = prescription.notes ?? '';
    form.clearErrors();
    medicationSearch.value = '';
    medicationSearchFocused.value = false;
    mode.value = 'editor';
};

const save = () => {
    if (!selectedTemplate.value) {
        form.setError('template_key', 'Choisissez un modèle.');

        return;
    }

    const items = form.items.filter((item) => item.medication.trim() !== '');

    if (!items.length) {
        form.setError('items', 'Ajoutez au moins un médicament.');

        return;
    }

    form.items = items;
    form.source = selectedTemplate.value.source;
    form.template_key = selectedTemplate.value.key;
    form.paper_size = paperSize.value;

    form.post(`/app/consultations/${props.consultationId}/prescriptions`, {
        preserveScroll: true,
        onSuccess: () => {
            mode.value = 'editor';
        },
    });
};
</script>

<template>
    <div class="min-h-0">
        <main class="min-w-0">
            <section
                v-if="mode === 'history'"
                class="rounded-xl border border-sidebar-border/70 bg-background p-5 dark:border-sidebar-border"
            >
                <div
                    class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
                >
                    <div>
                        <div class="flex items-center gap-2 text-primary">
                            <Pill class="size-4" /><span
                                class="text-xs font-semibold tracking-wide uppercase"
                                >Ordonnances</span
                            >
                        </div>
                        <h1 class="mt-2 text-xl font-semibold">Historique</h1>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Retrouvez les prescriptions du patient et ouvrez-les
                            dans l’éditeur intégré.
                        </p>
                    </div>
                    <Button v-if="canEdit" @click="startNew"
                        ><Plus class="size-4" />Nouvelle ordonnance</Button
                    >
                </div>
                <div v-if="prescriptions.length" class="mt-6 space-y-2">
                    <button
                        v-for="prescription in prescriptions"
                        :key="prescription.id"
                        type="button"
                        class="flex w-full items-center gap-3 rounded-lg border p-3 text-left transition-colors hover:border-primary/40 hover:bg-primary/[0.03]"
                        @click="openPrescription(prescription)"
                    >
                        <span
                            class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary"
                            ><Pill class="size-4"
                        /></span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-medium">{{
                                displayDate(prescription.prescribed_at)
                            }}</span>
                            <span
                                class="mt-0.5 block truncate text-xs text-muted-foreground"
                                >{{
                                    prescription.items
                                        .map((item) => item.medication)
                                        .join(', ') ||
                                    'Ordonnance sans médicament'
                                }}</span
                            >
                        </span>
                        <Badge variant="outline" class="hidden sm:inline-flex"
                            >Modifier</Badge
                        >
                    </button>
                </div>
                <div
                    v-else
                    class="mt-6 rounded-xl border border-dashed p-12 text-center"
                >
                    <span
                        class="mx-auto flex size-12 items-center justify-center rounded-full bg-muted text-muted-foreground"
                        ><Clock3 class="size-5"
                    /></span>
                    <p class="mt-3 text-sm font-medium">Aucune ordonnance</p>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Cliquez sur « Nouvelle ordonnance » pour commencer.
                    </p>
                </div>
            </section>

            <div v-else class="grid gap-4 lg:grid-cols-[27rem_minmax(0,1fr)]">
                <aside
                    class="h-fit rounded-xl border border-sidebar-border/70 bg-background p-4 dark:border-sidebar-border"
                >
                    <Button
                        variant="outline"
                        size="sm"
                        class="mb-4 w-full justify-start"
                        @click="mode = 'history'"
                        ><ArrowLeft class="size-4" />Historique</Button
                    >
                    <div class="rounded-lg bg-primary/5 p-4">
                        <div class="flex items-center gap-2 text-primary">
                            <Pill class="size-4" /><span
                                class="text-xs font-semibold tracking-wide uppercase"
                                >Nouvelle ordonnance</span
                            >
                        </div>
                        <p
                            class="mt-3 text-sm leading-relaxed text-muted-foreground"
                        >
                            Sélectionnez un médicament pour afficher ses champs,
                            puis recherchez le suivant en dessous.
                        </p>
                    </div>

                    <section class="mt-4 space-y-3">
                        <div
                            v-for="(item, index) in form.items"
                            :key="index"
                            class="rounded-lg border border-sidebar-border/70 bg-muted/10 p-3 dark:border-sidebar-border"
                        >
                            <div class="flex items-center gap-2">
                                <Input
                                    :id="`medication-${index}`"
                                    v-model="item.medication"
                                    class="h-10 min-w-0 flex-1"
                                    :aria-label="`Médicament ${index + 1}`"
                                    placeholder="Médicament"
                                    :disabled="!canEdit"
                                    readonly
                                />
                                <Input
                                    v-model="item.duration"
                                    class="h-10 w-32 shrink-0"
                                    :aria-label="`Quantité du médicament ${index + 1}`"
                                    placeholder="Qté (1 boîte)"
                                    :disabled="!canEdit"
                                />
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="size-10 shrink-0"
                                    aria-label="Supprimer le médicament"
                                    :disabled="!canEdit"
                                    @click="removeItem(index)"
                                    ><Trash2 class="size-4 text-destructive"
                                /></Button>
                            </div>
                            <Input
                                v-model="item.dosage"
                                class="mt-2"
                                :aria-label="`Posologie du médicament ${index + 1}`"
                                placeholder="Posologie (1 cp x 3/j)"
                                :disabled="!canEdit"
                            />
                            <Input
                                v-model="item.instructions"
                                class="mt-2"
                                :aria-label="`Instructions du médicament ${index + 1}`"
                                placeholder="Instructions (après les repas…)"
                                :disabled="!canEdit"
                            />
                        </div>

                        <div v-if="canEdit" class="relative">
                            <Search
                                class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                            />
                            <Input
                                v-model="medicationSearch"
                                class="h-11 pl-10"
                                aria-label="Rechercher ou saisir un médicament"
                                placeholder="Rechercher ou saisir un médicament"
                                autocomplete="off"
                                @focus="medicationSearchFocused = true"
                                @input="medicationSearchFocused = true"
                            />
                            <div
                                v-if="
                                    medicationSearchFocused &&
                                    medicationSearchResults.length
                                "
                                class="absolute top-full right-0 left-0 z-30 mt-1 overflow-hidden rounded-lg border bg-background p-1 shadow-lg"
                            >
                                <button
                                    v-for="medication in medicationSearchResults"
                                    :key="medication.id"
                                    type="button"
                                    class="flex w-full items-center gap-3 rounded-md px-3 py-2.5 text-left hover:bg-muted"
                                    @mousedown.prevent
                                    @click="
                                        selectMedicationFromSearch(medication)
                                    "
                                >
                                    <Pill
                                        class="size-4 shrink-0 text-primary"
                                    />
                                    <span class="min-w-0 flex-1">
                                        <span
                                            class="block truncate text-sm font-medium"
                                            >{{ medication.name }}</span
                                        >
                                        <span
                                            class="mt-0.5 block truncate text-xs text-muted-foreground"
                                            >{{
                                                medicationDetails(medication) ||
                                                'Référence médicament'
                                            }}</span
                                        >
                                    </span>
                                </button>
                            </div>
                        </div>

                        <InputError :message="form.errors.items" />
                        <Textarea
                            v-model="form.notes"
                            rows="3"
                            aria-label="Conseils ou notes pour le patient"
                            placeholder="Conseils ou notes pour le patient…"
                            :disabled="!canEdit"
                        />
                    </section>
                </aside>

                <div class="min-w-0">
                    <PrescriptionDocumentEditor
                        :template="selectedTemplate"
                        :paper-size="paperSize"
                        :prescribed-at="form.prescribed_at"
                        :items="form.items"
                        :notes="form.notes"
                        :patient-name="patient.full_name"
                        :doctor-name="cabinet.doctor_name"
                        :specialty="cabinet.specialty"
                        :order-number="cabinet.order_number"
                        :clinic-name="cabinet.clinic_name"
                        :phone="cabinet.phone"
                        :email="cabinet.email"
                        :clinic-address="cabinet.address"
                        :city="cabinet.city"
                        :footer="cabinet.footer"
                        :logo-url="cabinet.logo_url"
                        :title-box="titleBox"
                        :show-date="showPrescriptionDate"
                        :can-edit="canEdit"
                        @save="save"
                        @new-model="startNew"
                        @toggle-title-box="titleBox = !titleBox"
                    />
                </div>
            </div>
        </main>
    </div>
</template>
