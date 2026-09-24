<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ArrowLeftRight,
    Check,
    GitMerge,
    Loader2,
    Search,
    ShieldCheck,
    TriangleAlert,
    UserRoundX,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import PatientAvatar from '@/components/patients/PatientAvatar.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogDescription,
    DialogHeader,
    DialogScrollContent,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { getJson } from '@/lib/http';
import { formatDate, relativeDay } from '@/lib/patientDisplay';
import type { PatientMergePreview, PatientMergeSummary } from '@/types';

type Side = 'primary' | 'duplicate';

const props = defineProps<{
    open: boolean;
    primary: { id: number; full_name: string; patient_number: string };
    /** Skip the search step and compare with this dossier straight away. */
    duplicateId?: number | null;
}>();

const emit = defineEmits<{ 'update:open': [open: boolean] }>();

const keptId = ref(props.primary.id);
const step = ref<'pick' | 'compare'>('pick');
const search = ref('');
const candidates = ref<PatientMergeSummary[]>([]);
const loadingCandidates = ref(false);
const preview = ref<PatientMergePreview | null>(null);
const otherId = ref<number | null>(null);
const loadingPreview = ref(false);
const choices = ref<Record<string, Side>>({});
const confirmed = ref(false);
const merging = ref(false);
const error = ref<string | null>(null);

const close = () => emit('update:open', false);

const loadCandidates = async () => {
    loadingCandidates.value = true;
    error.value = null;

    try {
        const query = search.value.trim()
            ? `?search=${encodeURIComponent(search.value.trim())}`
            : '';
        const response = await getJson<{ candidates: PatientMergeSummary[] }>(
            `/app/patients/${keptId.value}/merge/candidates${query}`,
        );
        candidates.value = response.candidates;
    } catch {
        error.value = 'Impossible de charger les dossiers.';
    } finally {
        loadingCandidates.value = false;
    }
};

const compare = async (duplicateId: number) => {
    loadingPreview.value = true;
    error.value = null;
    otherId.value = duplicateId;

    try {
        preview.value = await getJson<PatientMergePreview>(
            `/app/patients/${keptId.value}/merge/${duplicateId}`,
        );
        choices.value = {};
        confirmed.value = false;
        step.value = 'compare';
    } catch {
        error.value = 'Impossible de comparer ces deux dossiers.';
    } finally {
        loadingPreview.value = false;
    }
};

/** Keep the other dossier instead: the comparison is reloaded the other way round. */
const swap = () => {
    if (otherId.value === null) {
        return;
    }

    const previousKept = keptId.value;
    keptId.value = otherId.value;
    void compare(previousKept);
};

const backToPick = () => {
    const swapped = keptId.value !== props.primary.id;
    keptId.value = props.primary.id;
    step.value = 'pick';

    if (swapped) {
        void loadCandidates();
    }
};

let searchTimer: ReturnType<typeof setTimeout> | undefined;

watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(loadCandidates, 300);
});

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        keptId.value = props.primary.id;
        search.value = '';
        preview.value = null;
        error.value = null;
        step.value = 'pick';

        if (props.duplicateId) {
            void compare(props.duplicateId);
        } else {
            void loadCandidates();
        }
    },
    { immediate: true },
);

const conflicts = computed(
    () => preview.value?.fields.filter((field) => field.conflict) ?? [],
);

const filled = computed(
    () =>
        preview.value?.fields.filter(
            (field) =>
                !field.clinical &&
                field.primary === null &&
                field.duplicate !== null,
        ) ?? [],
);

const clinicalMerged = computed(
    () =>
        preview.value?.fields.filter(
            (field) =>
                field.clinical &&
                field.duplicate !== null &&
                field.duplicate !== field.primary,
        ) ?? [],
);

const totalMoved = computed(
    () => preview.value?.moves.reduce((sum, move) => sum + move.count, 0) ?? 0,
);

const chosen = (field: string): Side => choices.value[field] ?? 'primary';

const choose = (field: string, side: Side) => {
    choices.value = { ...choices.value, [field]: side };
};

const merge = () => {
    if (!preview.value?.duplicate || !confirmed.value) {
        return;
    }

    merging.value = true;
    error.value = null;

    router.post(
        `/app/patients/${keptId.value}/merge`,
        {
            duplicate_id: preview.value.duplicate.id,
            choices: choices.value,
        },
        {
            preserveScroll: false,
            onSuccess: () => close(),
            onError: (errors) => {
                error.value =
                    errors.duplicate_id ??
                    Object.values(errors)[0] ??
                    'La fusion a échoué.';
            },
            onFinish: () => {
                merging.value = false;
            },
        },
    );
};
</script>

<template>
    <Dialog :open="open" @update:open="(value) => emit('update:open', value)">
        <DialogScrollContent class="sm:max-w-3xl">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <GitMerge class="size-5 text-brand" />
                    Fusionner deux dossiers
                </DialogTitle>
                <DialogDescription>
                    Pour un même patient enregistré deux fois : tout son
                    historique est regroupé dans un seul dossier.
                </DialogDescription>
            </DialogHeader>

            <p
                v-if="error"
                class="flex items-center gap-2 rounded-xl border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive"
                role="alert"
            >
                <TriangleAlert class="size-4 shrink-0" />
                {{ error }}
            </p>

            <!-- Step 1: pick the other dossier -->
            <div v-if="step === 'pick'" class="grid gap-4">
                <div
                    class="flex items-center gap-3 rounded-xl border border-brand/25 bg-brand-soft/50 p-3"
                >
                    <PatientAvatar :id="primary.id" :name="primary.full_name" />
                    <div class="min-w-0">
                        <p class="text-xs font-medium text-muted-foreground">
                            Dossier conservé
                        </p>
                        <p class="truncate font-semibold">
                            {{ primary.full_name }}
                            <span
                                class="ml-1 font-mono text-xs font-normal text-muted-foreground"
                                >{{ primary.patient_number }}</span
                            >
                        </p>
                    </div>
                </div>

                <div class="relative">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <Input
                        v-model="search"
                        type="search"
                        class="pl-9"
                        placeholder="Chercher l’autre dossier : nom, n° de dossier, téléphone…"
                        aria-label="Chercher le dossier à fusionner"
                    />
                </div>

                <p class="text-xs font-medium text-muted-foreground">
                    {{
                        search.trim()
                            ? 'Résultats de la recherche'
                            : 'Doublons probables détectés automatiquement'
                    }}
                </p>

                <div
                    v-if="loadingCandidates"
                    class="flex items-center justify-center gap-2 py-8 text-sm text-muted-foreground"
                >
                    <Loader2 class="size-4 animate-spin" />
                    Recherche…
                </div>

                <div
                    v-else-if="candidates.length === 0"
                    class="med-empty rounded-xl border border-dashed py-8"
                >
                    <UserRoundX class="med-empty-icon" />
                    <p class="med-empty-title">
                        {{
                            search.trim()
                                ? 'Aucun dossier trouvé'
                                : 'Aucun doublon probable'
                        }}
                    </p>
                    <p class="med-empty-hint">
                        Cherchez l’autre dossier par nom, numéro ou téléphone.
                    </p>
                </div>

                <ul v-else class="grid gap-2">
                    <li v-for="candidate in candidates" :key="candidate.id">
                        <button
                            type="button"
                            class="group flex w-full items-center gap-3 rounded-xl border bg-surface p-3 text-left transition hover:border-brand/40 hover:bg-brand-soft/40 hover:shadow-sm disabled:opacity-60"
                            :disabled="loadingPreview"
                            @click="compare(candidate.id)"
                        >
                            <PatientAvatar
                                :id="candidate.id"
                                :name="candidate.full_name"
                            />
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-medium">
                                    {{ candidate.full_name }}
                                    <span
                                        class="ml-1 font-mono text-xs font-normal text-muted-foreground"
                                        >{{ candidate.patient_number }}</span
                                    >
                                </p>
                                <p
                                    class="mt-0.5 flex flex-wrap gap-x-3 text-xs text-muted-foreground"
                                >
                                    <span
                                        >Né(e) le
                                        {{
                                            formatDate(candidate.date_of_birth)
                                        }}</span
                                    >
                                    <span v-if="candidate.phone">{{
                                        candidate.phone
                                    }}</span>
                                    <span
                                        >{{
                                            candidate.visits_count
                                        }}
                                        consultation{{
                                            candidate.visits_count > 1
                                                ? 's'
                                                : ''
                                        }}</span
                                    >
                                </p>
                            </div>
                            <span
                                class="flex items-center gap-1 text-sm font-medium text-brand opacity-70 transition group-hover:opacity-100 dark:text-brand-mint"
                            >
                                <Loader2
                                    v-if="
                                        loadingPreview &&
                                        otherId === candidate.id
                                    "
                                    class="size-4 animate-spin"
                                />
                                Comparer
                            </span>
                        </button>
                    </li>
                </ul>
            </div>

            <!-- Step 2: compare and confirm -->
            <div v-else-if="preview" class="grid gap-5">
                <div
                    class="grid items-stretch gap-3 sm:grid-cols-[1fr_auto_1fr]"
                >
                    <div
                        class="rounded-xl border-2 border-emerald-500/40 bg-emerald-50/60 p-3 dark:bg-emerald-950/20"
                    >
                        <p
                            class="flex items-center gap-1 text-xs font-semibold text-emerald-700 dark:text-emerald-300"
                        >
                            <ShieldCheck class="size-3.5" /> Conservé
                        </p>
                        <div
                            v-if="preview.primary"
                            class="mt-2 flex items-center gap-2"
                        >
                            <PatientAvatar
                                :id="preview.primary.id"
                                :name="preview.primary.full_name"
                                size="sm"
                            />
                            <div class="min-w-0">
                                <p class="truncate font-semibold">
                                    {{ preview.primary.full_name }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ preview.primary.patient_number }} ·
                                    {{ preview.primary.visits_count }} visite(s)
                                </p>
                            </div>
                        </div>
                    </div>

                    <Button
                        variant="outline"
                        size="icon"
                        class="self-center justify-self-center rounded-full"
                        title="Garder plutôt l’autre dossier"
                        aria-label="Inverser le dossier conservé"
                        :disabled="loadingPreview"
                        @click="swap"
                    >
                        <Loader2
                            v-if="loadingPreview"
                            class="size-4 animate-spin"
                        />
                        <ArrowLeftRight v-else class="size-4" />
                    </Button>

                    <div
                        class="rounded-xl border-2 border-amber-500/40 bg-amber-50/60 p-3 dark:bg-amber-950/20"
                    >
                        <p
                            class="flex items-center gap-1 text-xs font-semibold text-amber-700 dark:text-amber-300"
                        >
                            <GitMerge class="size-3.5" /> Fusionné puis archivé
                        </p>
                        <div
                            v-if="preview.duplicate"
                            class="mt-2 flex items-center gap-2"
                        >
                            <PatientAvatar
                                :id="preview.duplicate.id"
                                :name="preview.duplicate.full_name"
                                size="sm"
                            />
                            <div class="min-w-0">
                                <p class="truncate font-semibold">
                                    {{ preview.duplicate.full_name }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ preview.duplicate.patient_number }} ·
                                    {{ preview.duplicate.visits_count }}
                                    visite(s)
                                    <template
                                        v-if="preview.duplicate.last_visit_at"
                                    >
                                        · dernière
                                        {{
                                            relativeDay(
                                                preview.duplicate.last_visit_at,
                                            )
                                        }}
                                    </template>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <section>
                    <h3 class="text-sm font-semibold">
                        Ce qui sera rattaché au dossier conservé
                    </h3>
                    <div
                        v-if="preview.moves.length"
                        class="mt-2 flex flex-wrap gap-2"
                    >
                        <span
                            v-for="move in preview.moves"
                            :key="move.table"
                            class="inline-flex items-center gap-1.5 rounded-full border bg-surface px-3 py-1 text-sm"
                        >
                            <span class="font-semibold text-brand">{{
                                move.count
                            }}</span>
                            {{ move.label }}
                        </span>
                    </div>
                    <p v-else class="mt-1 text-sm text-muted-foreground">
                        Ce dossier ne contient ni consultation, ni rendez-vous,
                        ni document.
                    </p>
                </section>

                <section v-if="conflicts.length">
                    <h3 class="text-sm font-semibold">
                        Informations différentes — choisissez la bonne
                    </h3>
                    <div class="mt-2 grid gap-2">
                        <div
                            v-for="field in conflicts"
                            :key="field.field"
                            class="grid gap-2 sm:grid-cols-[9rem_1fr_1fr] sm:items-center"
                        >
                            <span class="text-sm text-muted-foreground">{{
                                field.label
                            }}</span>
                            <button
                                v-for="side in [
                                    'primary',
                                    'duplicate',
                                ] as const"
                                :key="side"
                                type="button"
                                class="flex items-center justify-between gap-2 rounded-lg border px-3 py-2 text-left text-sm transition"
                                :class="
                                    chosen(field.field) === side
                                        ? 'border-brand bg-brand-soft font-medium text-foreground ring-1 ring-brand/30'
                                        : 'bg-surface text-muted-foreground hover:border-brand/40 hover:text-foreground'
                                "
                                :aria-pressed="chosen(field.field) === side"
                                @click="choose(field.field, side)"
                            >
                                <span class="min-w-0 break-words">{{
                                    field[side]
                                }}</span>
                                <Check
                                    v-if="chosen(field.field) === side"
                                    class="size-4 shrink-0 text-brand"
                                />
                            </button>
                        </div>
                    </div>
                </section>

                <section
                    v-if="filled.length || clinicalMerged.length"
                    class="grid gap-2 rounded-xl bg-muted/40 p-3 text-sm"
                >
                    <p v-if="filled.length">
                        <span class="font-medium"
                            >Complété automatiquement :</span
                        >
                        {{ filled.map((field) => field.label).join(', ') }}
                        (vide dans le dossier conservé).
                    </p>
                    <p v-if="clinicalMerged.length">
                        <span class="font-medium"
                            >Regroupé sans rien perdre :</span
                        >
                        {{
                            clinicalMerged
                                .map((field) => field.label)
                                .join(', ')
                        }}
                        — le texte des deux dossiers est gardé.
                    </p>
                </section>

                <label
                    class="flex items-start gap-3 rounded-xl border border-amber-500/30 bg-amber-50/50 p-3 text-sm dark:bg-amber-950/20"
                >
                    <input
                        v-model="confirmed"
                        type="checkbox"
                        class="mt-0.5 size-4 accent-[var(--brand)]"
                    />
                    <span>
                        Je confirme qu’il s’agit du
                        <strong>même patient</strong>. Les
                        {{ totalMoved }} élément(s) seront rattachés au dossier
                        conservé et l’autre dossier sera archivé. La fusion est
                        enregistrée dans le journal d’audit.
                    </span>
                </label>

                <div
                    class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-between"
                >
                    <Button
                        v-if="!duplicateId"
                        variant="ghost"
                        @click="backToPick"
                    >
                        <ArrowLeft class="size-4" />
                        Choisir un autre dossier
                    </Button>
                    <span v-else />
                    <div class="flex flex-col-reverse gap-2 sm:flex-row">
                        <Button variant="outline" @click="close">
                            Annuler
                        </Button>
                        <Button
                            :disabled="!confirmed || merging"
                            @click="merge"
                        >
                            <Loader2
                                v-if="merging"
                                class="size-4 animate-spin"
                            />
                            <GitMerge v-else class="size-4" />
                            Fusionner les dossiers
                        </Button>
                    </div>
                </div>
            </div>

            <div
                v-else
                class="flex items-center justify-center gap-2 py-10 text-sm text-muted-foreground"
            >
                <Loader2 class="size-4 animate-spin" />
                Comparaison des dossiers…
            </div>
        </DialogScrollContent>
    </Dialog>
</template>
