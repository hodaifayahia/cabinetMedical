<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import {
    ArrowRight,
    BookmarkPlus,
    Check,
    FileText,
    FlaskConical,
    Plus,
    Search,
    Trash2,
} from '@lucide/vue';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import AiActionButton from '@/components/ai/AiActionButton.vue';
import AiDisclaimer from '@/components/ai/AiDisclaimer.vue';
import AiNotice from '@/components/ai/AiNotice.vue';
import BilanDocumentEditor from '@/components/consultations/BilanDocumentEditor.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import type { AiFailure, ConsultationDraft, ExamSuggestion } from '@/lib/ai';
import { consultationAiUrl, runAi } from '@/lib/ai';
import { aiWorkspace, takeQueued } from '@/lib/aiWorkspace';
import type {
    BilanTemplate,
    ClinicalDocument,
    DocumentBranding,
    ExamOption,
} from '@/types/clinicalDocuments';

type BilanCategory = string;
type MainTab = 'bilans' | 'groupes' | 'precedents';

const props = defineProps<{
    consultationId: number;
    exams: ExamOption[];
    bilanCategories: {
        key: string;
        label: string;
        hint: string | null;
    }[];
    bilanTemplates: BilanTemplate[];
    documents: ClinicalDocument[];
    patient: {
        full_name: string;
        date_of_birth: string | null;
    };
    cabinet: DocumentBranding;
    canEdit: boolean;
    aiDraft?: ConsultationDraft;
}>();

const mainTab = ref<MainTab>('bilans');
const category = ref<BilanCategory>(props.bilanCategories[0]?.key ?? '');
const search = ref('');
const selectedIds = ref<number[]>([]);
const notes = ref('');
const bilanDate = ref(new Date().toISOString().slice(0, 10));
const showDate = ref(true);
const titleBox = ref(true);
const paperSize = ref<'A4' | 'A5'>('A4');
const templateMode = ref(false);
const templateDialogOpen = ref(false);
const appliedTemplateId = ref<number | null>(null);
const templateNameInput = ref<HTMLInputElement | null>(null);
const templateForm = useForm<{ name: string; exam_ids: number[] }>({
    name: '',
    exam_ids: [],
});

const categoryColors = [
    'border-brand',
    'border-rose-500',
    'border-amber-500',
    'border-brand',
];

const categories = computed(() =>
    props.bilanCategories.map((item, index) => ({
        ...item,
        hint: item.hint ?? 'Examens complémentaires',
        color: categoryColors[index % categoryColors.length],
    })),
);

const selectedExams = computed(() =>
    selectedIds.value
        .map((id) => props.exams.find((exam) => exam.id === id))
        .filter((exam): exam is ExamOption => Boolean(exam)),
);

const selectedIdSet = computed(() => new Set(selectedIds.value));

const matchingExams = computed(() => {
    const query = search.value.trim().toLocaleLowerCase();

    return props.exams.filter((exam) => {
        if (exam.category !== category.value) {
            return false;
        }

        return query === '' || exam.name.toLocaleLowerCase().includes(query);
    });
});

const filteredExams = computed(() => {
    if (search.value.trim() === '') {
        return matchingExams.value.slice(0, 3);
    }

    return matchingExams.value.slice(0, 10);
});

const previousDocuments = computed(() =>
    props.documents.filter((document) => document.category === 'bilan'),
);

const patientAge = computed(() => {
    if (!props.patient.date_of_birth) {
        return null;
    }

    const birth = new Date(String(props.patient.date_of_birth) + 'T00:00:00');
    const age = Math.floor(
        (Date.now() - birth.getTime()) / (365.25 * 24 * 3600 * 1000),
    );

    return age >= 0 ? age : null;
});

const addExam = (exam: ExamOption) => {
    if (!props.canEdit || selectedIdSet.value.has(exam.id)) {
        return;
    }

    selectedIds.value.push(exam.id);
};

const removeExam = (id: number) => {
    selectedIds.value = selectedIds.value.filter(
        (selectedId) => selectedId !== id,
    );
};

const addCategory = (key: BilanCategory) => {
    if (!props.canEdit) {
        return;
    }

    const ids = props.exams
        .filter((exam) => exam.category === key)
        .map((exam) => exam.id);

    selectedIds.value = Array.from(new Set([...selectedIds.value, ...ids]));
    mainTab.value = 'bilans';
    category.value = key;
};

const newBilan = () => {
    selectedIds.value = [];
    notes.value = '';
    search.value = '';
    mainTab.value = 'bilans';
    templateMode.value = false;
    appliedTemplateId.value = null;
};

/**
 * Start composing a reusable selection. The exam picker is unchanged — only
 * what "Sauvegarder" does differs, so the practitioner builds a template the
 * same way they build a bilan.
 */
const newTemplate = () => {
    if (!props.canEdit) {
        return;
    }

    selectedIds.value = [];
    search.value = '';
    mainTab.value = 'bilans';
    appliedTemplateId.value = null;
    templateMode.value = true;
};

/** Load a saved selection into the picker, replacing what is there. */
const applyTemplate = (templateId: number | null) => {
    if (!props.canEdit || templateId === null) {
        return;
    }

    const template = props.bilanTemplates.find(
        (item) => item.id === templateId,
    );

    if (!template) {
        return;
    }

    selectedIds.value = template.exam_ids.filter((id) =>
        props.exams.some((exam) => exam.id === id),
    );
    appliedTemplateId.value = template.id;
    mainTab.value = 'bilans';
};

const openTemplateDialog = async () => {
    templateForm.clearErrors();
    templateForm.name = '';
    templateDialogOpen.value = true;

    await nextTick();
    templateNameInput.value?.focus();
};

const submitTemplate = () => {
    templateForm
        .transform((data) => ({
            ...data,
            exam_ids: selectedExams.value.map((exam) => exam.id),
        }))
        .post('/app/bilan-templates', {
            preserveScroll: true,
            onSuccess: () => {
                templateDialogOpen.value = false;
                templateForm.reset();
                templateMode.value = false;
            },
        });
};

const deleteTemplate = (template: BilanTemplate) => {
    if (!props.canEdit) {
        return;
    }

    router.delete('/app/bilan-templates/' + template.id, {
        preserveScroll: true,
        onSuccess: () => {
            if (appliedTemplateId.value === template.id) {
                appliedTemplateId.value = null;
            }
        },
    });
};

const saveBilan = () => {
    if (!props.canEdit || selectedExams.value.length === 0) {
        return;
    }

    // The same button serves both flows: naming a template, or filing the
    // bilan in the patient's dossier.
    if (templateMode.value) {
        void openTemplateDialog();

        return;
    }

    const content = [
        'BILAN',
        '',
        'Faire SVP:',
        ...selectedExams.value.map(
            (exam, index) => String(index + 1) + '. ' + exam.name,
        ),
        notes.value.trim() ? '\nNotes: ' + notes.value.trim() : '',
    ]
        .filter(Boolean)
        .join('\n');

    router.post(
        '/app/consultations/' + props.consultationId + '/documents',
        {
            category: 'bilan',
            title: 'Bilan du ' + displayDate(bilanDate.value),
            content,
        },
        { preserveScroll: true },
    );
};

// --- Assistant IA: exams suggested from the visit and the dossier ----------
const examSuggestions = ref<ExamSuggestion[] | null>(null);
const examSuggestionNote = ref('');
const examSuggestionLoading = ref(false);
const examSuggestionFailure = ref<AiFailure | null>(null);

const suggestExams = async () => {
    examSuggestionLoading.value = true;
    examSuggestionFailure.value = null;

    try {
        const result = await runAi<{ exams: ExamSuggestion[]; note: string }>(
            consultationAiUrl(props.consultationId, 'exams'),
            { draft: props.aiDraft ?? {} },
        );
        examSuggestions.value = result.exams;
        examSuggestionNote.value = result.note;
    } catch (error) {
        examSuggestionFailure.value = error as AiFailure;
    } finally {
        examSuggestionLoading.value = false;
    }
};

const noteLines = computed(
    () =>
        new Set(
            notes.value
                .split('\n')
                .map((line) => line.replace(/^-\s*/, '').trim().toLowerCase()),
        ),
);

const isSuggestionAdded = (suggestion: ExamSuggestion): boolean =>
    suggestion.exam_id !== null
        ? selectedIdSet.value.has(suggestion.exam_id)
        : noteLines.value.has(suggestion.name.toLowerCase());

const addSuggestedExam = (suggestion: ExamSuggestion) => {
    if (!props.canEdit || isSuggestionAdded(suggestion)) {
        return;
    }

    const exam =
        suggestion.exam_id !== null
            ? props.exams.find((item) => item.id === suggestion.exam_id)
            : undefined;

    if (exam) {
        addExam(exam);

        return;
    }

    // Not in the cabinet catalogue: it still reaches the printed request.
    notes.value = [notes.value.trim(), '- ' + suggestion.name]
        .filter(Boolean)
        .join('\n');
};

// --- Copilote: publish the bilan, pick up what the doctor accepted ----------
watch(
    selectedExams,
    (exams) => {
        aiWorkspace.bilanExams = exams.map((exam) => exam.name);
    },
    { immediate: true },
);

const takeCopilotExams = () => {
    takeQueued('queuedExams').forEach((queued) =>
        addSuggestedExam({
            name: queued.name,
            exam_id: queued.exam_id,
            reason: '',
            priority: 'recommandé',
        }),
    );
};

onMounted(takeCopilotExams);
watch(
    () => aiWorkspace.queuedExams.length,
    (count) => count > 0 && takeCopilotExams(),
);

const addAllSuggestedExams = () => {
    examSuggestions.value?.forEach(addSuggestedExam);
};

const priorityTone = (priority: ExamSuggestion['priority']): string =>
    priority === 'urgent'
        ? 'bg-red-50 text-red-700 dark:bg-red-950/40 dark:text-red-300'
        : priority === 'optionnel'
          ? 'bg-muted text-muted-foreground'
          : 'bg-brand-soft text-brand dark:bg-brand-deep/40 dark:text-brand-mint';

const displayDate = (date: string | null): string => {
    if (!date) {
        return '—';
    }

    const [year, month, day] = date.slice(0, 10).split('-');

    return year && month && day ? day + '/' + month + '/' + year : date;
};
</script>

<template>
    <div
        class="grid h-full min-h-[calc(100vh-13rem)] gap-4 xl:grid-cols-[minmax(330px,0.78fr)_minmax(600px,1.22fr)]"
    >
        <section
            class="flex h-full min-h-0 flex-col overflow-hidden rounded-xl border border-sidebar-border/70 bg-background dark:border-sidebar-border"
        >
            <div
                class="border-b border-sidebar-border/70 bg-slate-50/70 p-4 dark:border-sidebar-border dark:bg-slate-950/30"
            >
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p
                            class="flex items-center gap-2 text-sm font-bold tracking-wide text-foreground uppercase"
                        >
                            <FlaskConical class="size-4 text-brand" />
                            Nouveau bilan
                        </p>
                        <p class="mt-1 text-xs leading-5 text-muted-foreground">
                            Sélectionnez les examens, puis vérifiez le document
                            à droite.
                        </p>
                    </div>
                    <span
                        class="rounded-full bg-brand-soft px-2.5 py-1 text-xs font-semibold text-brand dark:bg-brand-deep/40 dark:text-brand-mint"
                    >
                        {{ selectedExams.length }} examen{{
                            selectedExams.length === 1 ? '' : 's'
                        }}
                    </span>
                </div>

                <label
                    v-if="bilanTemplates.length"
                    class="mt-3 grid gap-1 text-[11px] text-muted-foreground"
                >
                    Modèle enregistré
                    <select
                        :value="appliedTemplateId ?? ''"
                        class="h-9 rounded-md border border-input bg-background px-2 text-xs text-foreground"
                        :disabled="!canEdit"
                        aria-label="Charger un modèle de bilan"
                        @change="
                            applyTemplate(
                                ($event.target as HTMLSelectElement).value
                                    ? Number(
                                          ($event.target as HTMLSelectElement)
                                              .value,
                                      )
                                    : null,
                            )
                        "
                    >
                        <option value="">Choisir un modèle…</option>
                        <option
                            v-for="template in bilanTemplates"
                            :key="template.id"
                            :value="template.id"
                        >
                            {{ template.name }} ({{ template.exam_ids.length }})
                        </option>
                    </select>
                </label>

                <p
                    v-if="templateMode"
                    class="mt-3 flex items-start gap-2 rounded-md border border-brand/40 bg-brand-soft/60 px-2.5 py-2 text-[11px] leading-4 text-brand dark:bg-brand-deep/25 dark:text-brand-mint"
                >
                    <BookmarkPlus class="mt-px size-3.5 shrink-0" />
                    <span>
                        Mode modèle : choisissez les examens, puis « Sauvegarder
                        le modèle » pour le nommer.
                    </span>
                </p>

                <div v-if="canEdit" class="mt-3">
                    <AiActionButton
                        feature="exam_suggestions"
                        label="Suggérer des examens"
                        :loading="examSuggestionLoading"
                        @click="suggestExams"
                    />
                </div>
                <AiNotice
                    :failure="examSuggestionFailure"
                    class="mt-3"
                    @close="examSuggestionFailure = null"
                />
                <div
                    v-if="examSuggestions"
                    class="mt-3 max-h-80 space-y-2 overflow-y-auto rounded-xl border border-brand/25 bg-brand-soft/40 p-3 dark:border-brand-mint/25 dark:bg-brand-deep/20"
                    data-testid="bilan-ai-suggestions"
                >
                    <div class="flex items-center justify-between gap-2">
                        <p
                            class="text-xs font-semibold text-brand dark:text-brand-mint"
                        >
                            {{ examSuggestions.length }} examen{{
                                examSuggestions.length === 1 ? '' : 's'
                            }}
                            proposé{{ examSuggestions.length === 1 ? '' : 's' }}
                        </p>
                        <div class="flex items-center gap-1">
                            <Button
                                v-if="examSuggestions.length > 1"
                                size="sm"
                                variant="outline"
                                class="h-7 bg-background text-xs"
                                @click="addAllSuggestedExams"
                            >
                                <Plus class="size-3.5" /> Tout ajouter
                            </Button>
                            <button
                                type="button"
                                class="rounded-md px-2 py-1 text-xs text-muted-foreground hover:bg-background/70 hover:text-foreground"
                                @click="examSuggestions = null"
                            >
                                Fermer
                            </button>
                        </div>
                    </div>
                    <p
                        v-if="examSuggestions.length === 0"
                        class="text-xs text-muted-foreground"
                    >
                        L’IA ne juge aucun examen complémentaire nécessaire pour
                        l’instant.
                    </p>
                    <div
                        v-for="suggestion in examSuggestions"
                        :key="suggestion.name"
                        class="flex items-start gap-2 rounded-lg bg-background p-2.5 shadow-xs"
                    >
                        <div class="min-w-0 flex-1">
                            <p class="flex flex-wrap items-center gap-1.5">
                                <span class="text-sm font-medium">{{
                                    suggestion.name
                                }}</span>
                                <span
                                    class="rounded-full px-1.5 py-px text-[10px] font-semibold"
                                    :class="priorityTone(suggestion.priority)"
                                    >{{ suggestion.priority }}</span
                                >
                                <span
                                    v-if="suggestion.exam_id === null"
                                    class="text-[10px] text-muted-foreground"
                                    title="Absent de votre catalogue : il sera ajouté aux notes du bilan."
                                    >hors catalogue</span
                                >
                            </p>
                            <p
                                v-if="suggestion.reason"
                                class="mt-0.5 text-xs text-muted-foreground"
                            >
                                {{ suggestion.reason }}
                            </p>
                        </div>
                        <Button
                            size="sm"
                            :variant="
                                isSuggestionAdded(suggestion)
                                    ? 'ghost'
                                    : 'outline'
                            "
                            class="h-7 shrink-0 text-xs"
                            :disabled="isSuggestionAdded(suggestion)"
                            @click="addSuggestedExam(suggestion)"
                        >
                            <template v-if="isSuggestionAdded(suggestion)"
                                ><Check class="size-3.5" /> Ajouté</template
                            >
                            <template v-else
                                ><Plus class="size-3.5" /> Ajouter</template
                            >
                        </Button>
                    </div>
                    <p
                        v-if="examSuggestionNote"
                        class="text-xs text-muted-foreground"
                    >
                        {{ examSuggestionNote }}
                    </p>
                    <AiDisclaimer />
                </div>
            </div>

            <div
                class="flex border-b border-sidebar-border/70 px-3 pt-2 dark:border-sidebar-border"
            >
                <button
                    v-for="tab in [
                        { key: 'bilans', label: 'Bilans' },
                        { key: 'groupes', label: 'Groupe des bilans' },
                        { key: 'precedents', label: 'Bilans précédents' },
                    ]"
                    :key="tab.key"
                    type="button"
                    class="relative flex-1 px-2 py-2 text-xs font-medium transition"
                    :class="
                        mainTab === tab.key
                            ? 'text-foreground after:absolute after:right-2 after:bottom-0 after:left-2 after:h-0.5 after:bg-brand'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    @click="mainTab = tab.key as MainTab"
                >
                    {{ tab.label }}
                </button>
            </div>

            <div
                v-if="mainTab === 'bilans'"
                class="min-h-0 flex-1 overflow-y-auto p-3"
            >
                <div
                    class="grid grid-cols-3 gap-1 rounded-lg bg-muted/50 p-1"
                    role="tablist"
                    aria-label="Catégorie d'examen"
                >
                    <button
                        v-for="item in categories"
                        :key="item.key"
                        type="button"
                        class="rounded-md px-2 py-2 text-xs font-semibold transition"
                        :class="
                            category === item.key
                                ? 'bg-background text-foreground shadow-sm'
                                : 'text-muted-foreground hover:text-foreground'
                        "
                        @click="category = item.key"
                    >
                        <span class="block">{{ item.label }}</span>
                    </button>
                </div>

                <div class="relative mt-3">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <input
                        v-model="search"
                        type="search"
                        class="h-10 w-full rounded-lg border border-input bg-background pr-3 pl-9 text-sm ring-offset-background outline-none placeholder:text-muted-foreground focus:ring-2 focus:ring-ring"
                        :placeholder="
                            'Rechercher un examen ' +
                            (categories.find((item) => item.key === category)
                                ?.label ?? '') +
                            '…'
                        "
                        aria-label="Rechercher un examen"
                    />
                    <div
                        v-if="search.trim()"
                        class="mt-3 flex items-center justify-between"
                    >
                        <p class="text-xs text-muted-foreground">
                            {{ filteredExams.length }} résultat{{
                                filteredExams.length === 1 ? '' : 's'
                            }}
                            <span v-if="matchingExams.length > 10">
                                sur {{ matchingExams.length }}</span
                            >
                        </p>
                        <p class="text-[11px] text-muted-foreground">
                            10 maximum · Cliquez pour ajouter
                        </p>
                    </div>
                    <div
                        :class="
                            search.trim()
                                ? 'mt-2 max-h-[min(55vh,32rem)] overflow-y-auto rounded-lg border bg-background p-1 shadow-xl'
                                : 'mt-2 space-y-2'
                        "
                    >
                        <button
                            v-for="exam in filteredExams"
                            :key="exam.id"
                            type="button"
                            class="group flex w-full items-center gap-3 rounded-lg border border-l-4 border-sidebar-border/70 bg-background px-3 py-3 text-left transition hover:-translate-y-px hover:border-brand hover:shadow-sm dark:border-sidebar-border"
                            :class="[
                                categories.find(
                                    (item) => item.key === exam.category,
                                )?.color ?? 'border-brand',
                                selectedIdSet.has(exam.id)
                                    ? 'bg-brand-soft/60 dark:bg-brand-deep/20'
                                    : '',
                            ]"
                            :disabled="!canEdit || selectedIdSet.has(exam.id)"
                            @click="addExam(exam)"
                        >
                            <span class="min-w-0 flex-1">
                                <span
                                    class="block truncate text-sm font-semibold text-foreground"
                                    >{{ exam.name }}</span
                                >
                                <span
                                    class="mt-0.5 block text-[11px] text-muted-foreground"
                                >
                                    {{
                                        categories.find(
                                            (item) =>
                                                item.key === exam.category,
                                        )?.hint
                                    }}
                                </span>
                            </span>
                            <Check
                                v-if="selectedIdSet.has(exam.id)"
                                class="size-4 shrink-0 text-emerald-600"
                            />
                            <ArrowRight
                                v-else
                                class="size-4 shrink-0 text-emerald-600 transition group-hover:translate-x-0.5"
                            />
                        </button>
                        <p
                            v-if="search.trim() && filteredExams.length === 0"
                            class="rounded-lg border border-dashed px-3 py-8 text-center text-sm text-muted-foreground"
                        >
                            Aucun examen trouvé dans cette catégorie.
                        </p>
                    </div>
                </div>

                <div
                    class="mt-4 rounded-lg border border-sidebar-border/70 bg-muted/20 p-3 dark:border-sidebar-border"
                >
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-xs font-semibold text-foreground">
                            Examens sélectionnés
                        </p>
                        <span class="text-[11px] text-muted-foreground">{{
                            selectedExams.length
                        }}</span>
                    </div>
                    <div v-if="selectedExams.length" class="mt-2 space-y-1.5">
                        <div
                            v-for="(exam, index) in selectedExams"
                            :key="exam.id"
                            class="flex items-center gap-2 rounded-md bg-background px-2.5 py-2 text-xs"
                        >
                            <span class="font-semibold text-brand"
                                >{{ index + 1 }}.</span
                            >
                            <span class="min-w-0 flex-1 truncate">{{
                                exam.name
                            }}</span>
                            <button
                                v-if="canEdit"
                                type="button"
                                class="text-muted-foreground hover:text-destructive"
                                :aria-label="'Retirer ' + exam.name"
                                @click="removeExam(exam.id)"
                            >
                                <Trash2 class="size-3.5" />
                            </button>
                        </div>
                    </div>
                    <p v-else class="mt-2 text-xs text-muted-foreground">
                        Votre liste apparaîtra ici.
                    </p>
                </div>
            </div>

            <div
                v-else-if="mainTab === 'groupes'"
                class="min-h-0 flex-1 space-y-2 overflow-y-auto p-3"
            >
                <p class="mb-3 text-xs leading-5 text-muted-foreground">
                    Ajoutez rapidement un groupe d’examens fréquemment
                    prescrits.
                </p>

                <div v-if="bilanTemplates.length" class="mb-4">
                    <p
                        class="mb-2 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"
                    >
                        Mes modèles
                    </p>
                    <div
                        v-for="template in bilanTemplates"
                        :key="template.id"
                        class="mb-2 flex items-center gap-2 rounded-lg border border-l-4 border-brand border-sidebar-border/70 bg-background p-3 dark:border-sidebar-border"
                    >
                        <button
                            type="button"
                            class="min-w-0 flex-1 text-left"
                            :disabled="!canEdit"
                            @click="applyTemplate(template.id)"
                        >
                            <span class="block truncate text-sm font-semibold">
                                {{ template.name }}
                            </span>
                            <span class="text-xs text-muted-foreground">
                                {{ template.exam_ids.length }} examen{{
                                    template.exam_ids.length === 1 ? '' : 's'
                                }}
                            </span>
                        </button>
                        <button
                            v-if="canEdit"
                            type="button"
                            class="text-muted-foreground hover:text-destructive"
                            :aria-label="'Supprimer le modèle ' + template.name"
                            @click="deleteTemplate(template)"
                        >
                            <Trash2 class="size-3.5" />
                        </button>
                    </div>
                </div>
                <button
                    v-for="item in categories"
                    :key="item.key"
                    type="button"
                    class="flex w-full items-center gap-3 rounded-lg border border-l-4 border-sidebar-border/70 bg-background p-3 text-left transition hover:shadow-sm dark:border-sidebar-border"
                    :class="item.color"
                    :disabled="!canEdit"
                    @click="addCategory(item.key)"
                >
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-semibold">{{
                            item.label
                        }}</span>
                        <span class="text-xs text-muted-foreground">{{
                            item.hint
                        }}</span>
                    </span>
                    <Plus class="size-4 text-emerald-600" />
                </button>
            </div>

            <div v-else class="min-h-0 flex-1 space-y-2 overflow-y-auto p-3">
                <div
                    v-for="document in previousDocuments"
                    :key="document.id"
                    class="flex items-center gap-3 rounded-lg border border-sidebar-border/70 p-3 dark:border-sidebar-border"
                >
                    <FileText class="size-4 shrink-0 text-brand" />
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold">
                            {{ document.title }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ document.created_at ?? '—' }}
                        </p>
                    </div>
                </div>
                <p
                    v-if="previousDocuments.length === 0"
                    class="rounded-lg border border-dashed px-3 py-8 text-center text-sm text-muted-foreground"
                >
                    Aucun bilan précédent pour ce patient.
                </p>
            </div>

            <div
                class="border-t border-sidebar-border/70 p-3 dark:border-sidebar-border"
            >
                <Textarea
                    v-model="notes"
                    :disabled="!canEdit"
                    rows="3"
                    placeholder="Conseils ou notes pour le patient…"
                />
                <div class="mt-3 grid grid-cols-2 gap-2">
                    <label class="grid gap-1 text-[11px] text-muted-foreground">
                        Date du bilan
                        <input
                            v-model="bilanDate"
                            type="date"
                            class="h-9 rounded-md border border-input bg-background px-2 text-xs text-foreground"
                            :disabled="!canEdit"
                        />
                    </label>
                    <label class="grid gap-1 text-[11px] text-muted-foreground">
                        Format
                        <select
                            v-model="paperSize"
                            class="h-9 rounded-md border border-input bg-background px-2 text-xs text-foreground"
                            :disabled="!canEdit"
                        >
                            <option value="A4">A4</option>
                            <option value="A5">A5</option>
                        </select>
                    </label>
                </div>
                <label
                    class="mt-3 flex items-center gap-2 text-xs text-muted-foreground"
                >
                    <input
                        v-model="showDate"
                        type="checkbox"
                        class="size-3.5 accent-primary"
                        :disabled="!canEdit"
                    />
                    Afficher la date sur le bilan
                </label>
            </div>
        </section>

        <BilanDocumentEditor
            :paper-size="paperSize"
            :prescribed-at="bilanDate"
            :items="selectedExams"
            :notes="notes"
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
            :show-date="showDate"
            :can-edit="canEdit"
            :template-mode="templateMode"
            @save="saveBilan"
            @new-bilan="newBilan"
            @new-template="newTemplate"
            @toggle-title-box="titleBox = !titleBox"
        />

        <Dialog v-model:open="templateDialogOpen">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Nommer le modèle</DialogTitle>
                    <DialogDescription>
                        {{ selectedExams.length }}
                        {{
                            selectedExams.length === 1
                                ? 'examen sera enregistré'
                                : 'examens seront enregistrés'
                        }}
                        sous ce nom, puis proposé{{
                            selectedExams.length === 1 ? '' : 's'
                        }}
                        dans la liste des modèles.
                    </DialogDescription>
                </DialogHeader>

                <form class="grid gap-2" @submit.prevent="submitTemplate">
                    <label
                        class="text-xs font-medium text-foreground"
                        for="bilan-template-name"
                    >
                        Nom du modèle
                    </label>
                    <input
                        id="bilan-template-name"
                        ref="templateNameInput"
                        v-model="templateForm.name"
                        type="text"
                        maxlength="120"
                        required
                        placeholder="Bilan pré-opératoire"
                        class="h-10 w-full rounded-lg border border-input bg-background px-3 text-sm ring-offset-background outline-none focus:ring-2 focus:ring-ring"
                    />
                    <p
                        v-if="templateForm.errors.name"
                        class="text-xs text-destructive"
                    >
                        {{ templateForm.errors.name }}
                    </p>
                    <p
                        v-if="templateForm.errors.exam_ids"
                        class="text-xs text-destructive"
                    >
                        {{ templateForm.errors.exam_ids }}
                    </p>

                    <DialogFooter class="mt-2">
                        <Button
                            type="button"
                            variant="outline"
                            @click="templateDialogOpen = false"
                        >
                            Annuler
                        </Button>
                        <Button
                            type="submit"
                            :disabled="
                                templateForm.processing ||
                                templateForm.name.trim() === ''
                            "
                        >
                            Enregistrer le modèle
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
