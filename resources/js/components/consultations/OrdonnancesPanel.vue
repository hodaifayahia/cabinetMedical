<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Check,
    Clock3,
    Pill,
    Plus,
    Search,
    Trash2,
    TriangleAlert,
    X,
} from '@lucide/vue';
import { computed, onMounted, ref, watch } from 'vue';
import AiActionButton from '@/components/ai/AiActionButton.vue';
import AiDisclaimer from '@/components/ai/AiDisclaimer.vue';
import AiNotice from '@/components/ai/AiNotice.vue';
import PrescriptionDocumentEditor from '@/components/consultations/PrescriptionDocumentEditor.vue';
import PrescriptionProtocols from '@/components/consultations/PrescriptionProtocols.vue';
import type {
    PrescriptionProtocol,
    ProtocolItem,
} from '@/components/consultations/PrescriptionProtocols.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import type {
    AiFailure,
    ConsultationDraft,
    PrescriptionSuggestion,
} from '@/lib/ai';
import { consultationAiUrl, runAi } from '@/lib/ai';
import { aiWorkspace, takeQueued } from '@/lib/aiWorkspace';
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
    patient: { full_name: string; date_of_birth?: string | null };
    cabinet: DocumentBranding;
    canEdit: boolean;
    aiDraft?: ConsultationDraft;
    protocols?: PrescriptionProtocol[];
}>();

// Same rule as the bilan and courrier headers.
const patientAge = computed(() => {
    if (!props.patient.date_of_birth) {
        return null;
    }

    const birth = new Date(
        String(props.patient.date_of_birth).slice(0, 10) + 'T00:00:00',
    );
    const age = Math.floor(
        (Date.now() - birth.getTime()) / (365.25 * 24 * 3600 * 1000),
    );

    return age >= 0 ? age : null;
});

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
    allergy_override: boolean;
}>({
    allergy_override: false,
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

// --- Protocoles: saved ordonnance sets ---------------------------------------
const applyProtocol = (items: ProtocolItem[], notes: string | null) => {
    if (mode.value === 'history') {
        startNew();
    }

    form.items = items.map((item) => ({ ...blankItem(), ...item }));

    if (notes) {
        form.notes = notes;
    }

    form.clearErrors();
};

// --- Assistant IA: a proposed ordonnance the doctor picks lines from --------
const aiItems = ref<PrescriptionSuggestion[] | null>(null);
const aiWarnings = ref<string[]>([]);
const aiAdvice = ref('');
const aiLoading = ref(false);
const aiFailure = ref<AiFailure | null>(null);

const suggestPrescription = async () => {
    if (mode.value === 'history') {
        startNew();
    }

    aiLoading.value = true;
    aiFailure.value = null;

    try {
        const result = await runAi<{
            items: PrescriptionSuggestion[];
            warnings: string[];
            advice: string;
        }>(consultationAiUrl(props.consultationId, 'prescription'), {
            draft: props.aiDraft ?? {},
            current_items: form.items
                .map((item) => item.medication.trim())
                .filter(Boolean),
        });
        aiItems.value = result.items;
        aiWarnings.value = result.warnings;
        aiAdvice.value = result.advice;
    } catch (error) {
        aiFailure.value = error as AiFailure;
    } finally {
        aiLoading.value = false;
    }
};

const isAiItemAdded = (suggestion: PrescriptionSuggestion): boolean =>
    form.items.some(
        (item) =>
            item.medication.trim().toLocaleLowerCase() ===
            suggestion.medication.trim().toLocaleLowerCase(),
    );

const addAiItem = (suggestion: PrescriptionSuggestion) => {
    if (!props.canEdit || isAiItemAdded(suggestion)) {
        return;
    }

    form.items.push({
        medication: suggestion.medication,
        dosage: suggestion.dosage,
        duration: suggestion.duration,
        instructions: suggestion.instructions,
    });
    form.clearErrors('items');
};

// --- Copilote: publish the ordonnance, pick up what the doctor accepted -----
watch(
    () => form.items.map((item) => item.medication.trim()).filter(Boolean),
    (names) => {
        aiWorkspace.ordonnanceItems = names;
    },
    { immediate: true },
);

const takeCopilotItems = () => {
    const medications = takeQueued('queuedMedications');
    const advice = takeQueued('queuedAdvice');

    if (!props.canEdit || (!medications.length && !advice.length)) {
        return;
    }

    if (mode.value === 'history') {
        startNew();
    }

    medications.forEach((item) =>
        addAiItem({ ...item, in_catalogue: true, reason: '' }),
    );

    if (advice.length) {
        form.notes = [form.notes.trim(), ...advice].filter(Boolean).join('\n');
    }
};

onMounted(takeCopilotItems);
watch(
    () =>
        aiWorkspace.queuedMedications.length + aiWorkspace.queuedAdvice.length,
    (count) => count > 0 && takeCopilotItems(),
);

const addAllAiItems = () => {
    aiItems.value?.forEach(addAiItem);
};

const adviceAdded = computed(
    () => aiAdvice.value !== '' && form.notes.includes(aiAdvice.value),
);

const addAiAdvice = () => {
    if (!aiAdvice.value || adviceAdded.value) {
        return;
    }

    form.notes = [form.notes.trim(), aiAdvice.value].filter(Boolean).join('\n');
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
            // An allergy override covers this ordonnance only.
            form.allergy_override = false;
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
                    <div
                        v-if="canEdit"
                        class="flex flex-wrap items-center gap-2"
                    >
                        <AiActionButton
                            feature="prescription_suggestions"
                            label="Proposer une ordonnance"
                            size="md"
                            :loading="aiLoading"
                            @click="suggestPrescription"
                        />
                        <PrescriptionProtocols
                            :protocols="protocols ?? []"
                            :current-items="form.items"
                            :current-notes="form.notes"
                            :can-edit="canEdit"
                            @apply="applyProtocol"
                        />
                        <Button @click="startNew"
                            ><Plus class="size-4" />Nouvelle ordonnance</Button
                        >
                    </div>
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
                        <AiActionButton
                            v-if="canEdit"
                            feature="prescription_suggestions"
                            :label="
                                aiItems
                                    ? 'Nouvelle proposition'
                                    : 'Proposer avec l’IA'
                            "
                            class="mt-3"
                            :loading="aiLoading"
                            @click="suggestPrescription"
                        />
                        <div class="mt-2">
                            <PrescriptionProtocols
                                :protocols="protocols ?? []"
                                :current-items="form.items"
                                :current-notes="form.notes"
                                :can-edit="canEdit"
                                @apply="applyProtocol"
                            />
                        </div>
                    </div>

                    <AiNotice
                        :failure="aiFailure"
                        class="mt-4"
                        @close="aiFailure = null"
                    />

                    <section
                        v-if="aiItems"
                        class="mt-4 space-y-2 rounded-xl border border-brand/25 bg-brand-soft/40 p-3 dark:border-brand-mint/25 dark:bg-brand-deep/20"
                        data-testid="ordonnance-ai-suggestions"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <p
                                class="text-xs font-semibold text-brand dark:text-brand-mint"
                            >
                                Proposition de l’IA
                            </p>
                            <div class="flex items-center gap-1">
                                <Button
                                    v-if="aiItems.length > 1"
                                    size="sm"
                                    variant="outline"
                                    class="h-7 bg-background text-xs"
                                    @click="addAllAiItems"
                                >
                                    <Plus class="size-3.5" /> Tout ajouter
                                </Button>
                                <Button
                                    size="icon-sm"
                                    variant="ghost"
                                    class="size-7"
                                    aria-label="Fermer la proposition"
                                    @click="aiItems = null"
                                >
                                    <X class="size-3.5" />
                                </Button>
                            </div>
                        </div>

                        <ul
                            v-if="aiWarnings.length"
                            class="space-y-1 rounded-lg bg-amber-50 p-2.5 text-xs text-amber-900 dark:bg-amber-950/30 dark:text-amber-100"
                        >
                            <li
                                v-for="warning in aiWarnings"
                                :key="warning"
                                class="flex gap-1.5"
                            >
                                <TriangleAlert
                                    class="mt-px size-3.5 shrink-0"
                                />
                                {{ warning }}
                            </li>
                        </ul>

                        <p
                            v-if="aiItems.length === 0"
                            class="text-xs text-muted-foreground"
                        >
                            L’IA n’a pas de médicament à ajouter pour l’instant.
                        </p>

                        <div
                            v-for="suggestion in aiItems"
                            :key="suggestion.medication"
                            class="flex items-start gap-2 rounded-lg bg-background p-2.5 shadow-xs"
                        >
                            <Pill
                                class="mt-0.5 size-4 shrink-0 text-brand dark:text-brand-mint"
                            />
                            <div class="min-w-0 flex-1 text-xs">
                                <p class="flex flex-wrap items-center gap-1.5">
                                    <span
                                        class="text-sm font-medium text-foreground"
                                        >{{ suggestion.medication }}</span
                                    >
                                    <span
                                        v-if="!suggestion.in_catalogue"
                                        class="text-[10px] text-muted-foreground"
                                        >hors catalogue</span
                                    >
                                </p>
                                <p class="mt-0.5 text-muted-foreground">
                                    {{
                                        [
                                            suggestion.dosage,
                                            suggestion.duration,
                                            suggestion.instructions,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')
                                    }}
                                </p>
                                <p
                                    v-if="suggestion.reason"
                                    class="mt-0.5 text-muted-foreground italic"
                                >
                                    {{ suggestion.reason }}
                                </p>
                                <p
                                    v-for="conflict in suggestion.allergy_conflicts ??
                                    []"
                                    :key="conflict"
                                    class="mt-1 flex items-start gap-1 rounded-md bg-red-50 px-2 py-1 text-red-800 dark:bg-red-950/40 dark:text-red-200"
                                >
                                    <TriangleAlert
                                        class="mt-px size-3.5 shrink-0"
                                    />
                                    Allergie : {{ conflict }}
                                </p>
                            </div>
                            <Button
                                size="sm"
                                :variant="
                                    isAiItemAdded(suggestion)
                                        ? 'ghost'
                                        : 'outline'
                                "
                                class="h-7 shrink-0 text-xs"
                                :disabled="isAiItemAdded(suggestion)"
                                @click="addAiItem(suggestion)"
                            >
                                <template v-if="isAiItemAdded(suggestion)"
                                    ><Check class="size-3.5" /> Ajouté</template
                                >
                                <template v-else
                                    ><Plus class="size-3.5" /> Ajouter</template
                                >
                            </Button>
                        </div>

                        <div
                            v-if="aiAdvice"
                            class="rounded-lg bg-background p-2.5 text-xs shadow-xs"
                        >
                            <div
                                class="flex items-center justify-between gap-2"
                            >
                                <p class="font-semibold text-muted-foreground">
                                    Conseils au patient
                                </p>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    class="h-7 text-xs"
                                    :disabled="adviceAdded"
                                    @click="addAiAdvice"
                                >
                                    <Check
                                        v-if="adviceAdded"
                                        class="size-3.5"
                                    />
                                    <Plus v-else class="size-3.5" />
                                    {{
                                        adviceAdded
                                            ? 'Ajoutés'
                                            : 'Ajouter aux notes'
                                    }}
                                </Button>
                            </div>
                            <p class="mt-1 text-foreground">{{ aiAdvice }}</p>
                        </div>

                        <AiDisclaimer />
                    </section>

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
                        <label
                            v-if="form.errors.items?.startsWith('Allergie')"
                            class="flex items-start gap-2 rounded-lg border border-rose-300 bg-rose-50 p-2 text-sm text-rose-900 dark:border-rose-500/40 dark:bg-rose-500/10 dark:text-rose-200"
                        >
                            <input
                                v-model="form.allergy_override"
                                type="checkbox"
                                class="mt-0.5 accent-rose-600"
                            />
                            Prescrire malgré l’allergie (décision médicale,
                            enregistrée dans le journal d’audit)
                        </label>
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
                        :patient-age="patientAge"
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
