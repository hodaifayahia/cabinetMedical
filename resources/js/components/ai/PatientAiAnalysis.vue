<script setup lang="ts">
import {
    Activity,
    CalendarCheck,
    ClipboardList,
    FlaskConical,
    Pill,
    ShieldAlert,
    Sparkles,
    TriangleAlert,
} from '@lucide/vue';
import { computed, onMounted, ref, watch } from 'vue';
import AiActionButton from '@/components/ai/AiActionButton.vue';
import AiCreditsPill from '@/components/ai/AiCreditsPill.vue';
import AiDisclaimer from '@/components/ai/AiDisclaimer.vue';
import AiNotice from '@/components/ai/AiNotice.vue';
import { Skeleton } from '@/components/ui/skeleton';
import type { AiFailure, PatientAnalysis } from '@/lib/ai';
import { runAi } from '@/lib/ai';
import { getJson } from '@/lib/http';

const props = withDefaults(
    defineProps<{ patientId: number; canRun?: boolean }>(),
    { canRun: true },
);

const analysis = ref<PatientAnalysis | null>(null);
const loadingStored = ref(true);
const running = ref(false);
const failure = ref<AiFailure | null>(null);

const load = async () => {
    loadingStored.value = true;
    failure.value = null;

    try {
        const result = await getJson<{ analysis: PatientAnalysis | null }>(
            `/app/ai/patients/${props.patientId}/analysis`,
        );
        analysis.value = result.analysis;
    } catch {
        analysis.value = null;
    } finally {
        loadingStored.value = false;
    }
};

const analyze = async () => {
    running.value = true;
    failure.value = null;

    try {
        const result = await runAi<{ analysis: PatientAnalysis }>(
            `/app/ai/patients/${props.patientId}/analysis`,
        );
        analysis.value = result.analysis;
    } catch (error) {
        failure.value = error as AiFailure;
    } finally {
        running.value = false;
    }
};

onMounted(load);
watch(() => props.patientId, load);

const generatedAt = computed(() => {
    if (!analysis.value?.created_at) {
        return null;
    }

    return new Intl.DateTimeFormat('fr-DZ', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(analysis.value.created_at));
});

const riskTone = (level: string): string =>
    level === 'élevé'
        ? 'bg-red-50 text-red-700 ring-red-200 dark:bg-red-950/40 dark:text-red-300 dark:ring-red-900'
        : level === 'modéré'
          ? 'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-950/40 dark:text-amber-200 dark:ring-amber-900'
          : 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:ring-emerald-900';

const lists = computed(() => {
    const content = analysis.value?.content;

    if (!content) {
        return [];
    }

    return [
        {
            key: 'problems',
            title: 'Problèmes actifs',
            icon: ClipboardList,
            items: content.problems,
        },
        {
            key: 'follow_up',
            title: 'Suivi à prévoir',
            icon: CalendarCheck,
            items: content.follow_up,
        },
        {
            key: 'suggested_exams',
            title: 'Examens à envisager',
            icon: FlaskConical,
            items: content.suggested_exams,
        },
        {
            key: 'treatment_notes',
            title: 'Traitements',
            icon: Pill,
            items: content.treatment_notes,
        },
    ].filter((list) => list.items.length > 0);
});
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <p
                    class="flex items-center gap-2 text-sm font-semibold text-foreground"
                >
                    <Sparkles class="size-4 text-brand dark:text-brand-mint" />
                    Analyse IA du dossier
                </p>
                <p class="mt-0.5 text-xs text-muted-foreground">
                    <template v-if="generatedAt">
                        Générée le {{ generatedAt }} à partir des consultations,
                        ordonnances, mesures et documents.
                    </template>
                    <template v-else>
                        L’IA lit tout le dossier du patient au cabinet et vous
                        en fait la synthèse.
                    </template>
                </p>
            </div>
            <div class="flex items-center gap-2">
                <AiCreditsPill />
                <AiActionButton
                    v-if="canRun"
                    feature="patient_analysis"
                    :label="analysis ? 'Actualiser' : 'Analyser le dossier'"
                    :loading="running"
                    @click="analyze"
                />
            </div>
        </div>

        <AiNotice :failure="failure" @close="failure = null" />

        <div v-if="loadingStored || running" class="space-y-3">
            <p
                v-if="running"
                class="flex items-center gap-2 text-sm text-muted-foreground"
            >
                <Activity class="size-4 animate-pulse text-brand" />
                L’IA lit le dossier du patient… cela prend quelques secondes.
            </p>
            <Skeleton class="h-16 w-full" />
            <div class="grid gap-3 sm:grid-cols-2">
                <Skeleton class="h-24" />
                <Skeleton class="h-24" />
            </div>
        </div>

        <div
            v-else-if="!analysis"
            class="rounded-xl border border-dashed p-8 text-center"
        >
            <span
                class="mx-auto flex size-12 items-center justify-center rounded-full bg-brand-soft text-brand dark:bg-brand-deep/40 dark:text-brand-mint"
            >
                <Sparkles class="size-5" />
            </span>
            <p class="mt-3 text-sm font-medium">
                Aucune analyse pour ce patient
            </p>
            <p class="mx-auto mt-1 max-w-sm text-sm text-muted-foreground">
                Lancez l’analyse pour obtenir en quelques secondes ses problèmes
                actifs, ses risques et le suivi à prévoir.
            </p>
        </div>

        <template v-else>
            <div
                v-if="analysis.content.alerts.length"
                class="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-900 dark:border-red-900 dark:bg-red-950/30 dark:text-red-100"
            >
                <p class="flex items-center gap-2 font-semibold">
                    <TriangleAlert class="size-4" /> À surveiller en priorité
                </p>
                <ul class="mt-1.5 list-disc space-y-0.5 pl-6">
                    <li v-for="alert in analysis.content.alerts" :key="alert">
                        {{ alert }}
                    </li>
                </ul>
            </div>

            <p
                class="rounded-xl bg-muted/40 p-4 text-sm leading-relaxed whitespace-pre-line text-foreground"
            >
                {{ analysis.content.summary }}
            </p>

            <div v-if="analysis.content.risks.length" class="space-y-2">
                <p
                    class="flex items-center gap-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    <ShieldAlert class="size-3.5" /> Risques
                </p>
                <div class="grid gap-2 sm:grid-cols-2">
                    <div
                        v-for="risk in analysis.content.risks"
                        :key="risk.label"
                        class="rounded-lg border p-3"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <p class="text-sm font-medium">{{ risk.label }}</p>
                            <span
                                class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1"
                                :class="riskTone(risk.level)"
                                >{{ risk.level }}</span
                            >
                        </div>
                        <p
                            v-if="risk.reason"
                            class="mt-1 text-xs text-muted-foreground"
                        >
                            {{ risk.reason }}
                        </p>
                    </div>
                </div>
            </div>

            <div v-if="lists.length" class="grid gap-3 sm:grid-cols-2">
                <div
                    v-for="list in lists"
                    :key="list.key"
                    class="rounded-lg border p-3"
                >
                    <p
                        class="flex items-center gap-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                    >
                        <component :is="list.icon" class="size-3.5" />
                        {{ list.title }}
                    </p>
                    <ul class="mt-2 space-y-1 text-sm">
                        <li
                            v-for="item in list.items"
                            :key="item"
                            class="flex gap-2"
                        >
                            <span
                                class="mt-2 size-1.5 shrink-0 rounded-full bg-brand dark:bg-brand-mint"
                            />
                            <span>{{ item }}</span>
                        </li>
                    </ul>
                </div>
            </div>

            <AiDisclaimer />
        </template>
    </div>
</template>
