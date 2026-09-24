<script setup lang="ts">
import {
    BookOpen,
    Check,
    ClipboardCopy,
    FlaskConical,
    MessageSquarePlus,
    PenLine,
    Pill,
    Send,
    ShieldAlert,
    Sparkles,
    UserRound,
} from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import AiCreditsPill from '@/components/ai/AiCreditsPill.vue';
import AiDisclaimer from '@/components/ai/AiDisclaimer.vue';
import AiNotice from '@/components/ai/AiNotice.vue';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import type {
    AiFailure,
    ConsultationDraft,
    CopilotAction,
    CopilotMessage,
} from '@/lib/ai';
import { aiCost, creditsLabel, formatAiText, runAi } from '@/lib/ai';
import { aiWorkspace } from '@/lib/aiWorkspace';
import { deleteJson, getJson } from '@/lib/http';

type VisitField = 'motif' | 'examens' | 'diagnostic' | 'traitement' | 'notes';

const open = defineModel<boolean>('open', { default: false });

const props = defineProps<{
    consultationId: number;
    draft: ConsultationDraft;
    canEdit: boolean;
}>();

const emit = defineEmits<{
    'apply-field': [
        field: VisitField,
        text: string,
        mode: 'replace' | 'append',
    ];
    'open-section': [section: 'bilans' | 'ordonnances'];
}>();

const messages = ref<CopilotMessage[]>([]);
const loaded = ref(false);
const input = ref('');
const sending = ref(false);
const failure = ref<AiFailure | null>(null);
const scroller = ref<HTMLElement | null>(null);
const applied = ref(new Set<string>());

const fieldLabels: Record<VisitField, string> = {
    motif: 'Motif',
    examens: 'Examens',
    diagnostic: 'Diagnostic',
    traitement: 'Traitement',
    notes: 'Notes internes',
};

const quickPrompts = [
    {
        icon: BookOpen,
        label: 'Résumé avant consultation',
        prompt: 'Fais-moi un résumé rapide de ce patient avant la consultation : problèmes actifs, traitements en cours, derniers résultats, points de vigilance.',
    },
    {
        icon: Sparkles,
        label: 'Diagnostics différentiels',
        prompt: 'Quels diagnostics différentiels évoquer à partir de ce que j’ai noté ? Classe-les du plus probable au moins probable, avec les éléments pour et contre.',
    },
    {
        icon: PenLine,
        label: 'Rédiger la visite',
        prompt: 'À partir de mes notes, rédige l’examen clinique structuré et le diagnostic, et propose de remplir les champs de la visite.',
    },
    {
        icon: FlaskConical,
        label: 'Bilan à demander',
        prompt: 'Quels examens complémentaires demander pour cette consultation ? Propose-les en actions.',
    },
    {
        icon: ShieldAlert,
        label: 'Vérifier l’ordonnance',
        prompt: 'Vérifie l’ordonnance en préparation : allergies, interactions, contre-indications et doses selon l’âge et le poids. Propose des corrections si nécessaire.',
    },
    {
        icon: UserRound,
        label: 'Conseils au patient (arabe)',
        prompt: 'Rédige des conseils simples pour le patient, en arabe (et en français en dessous), adaptés à ce diagnostic.',
    },
];

watch(
    open,
    async (isOpen) => {
        if (!isOpen || loaded.value) {
            return;
        }

        try {
            const result = await getJson<{ messages: CopilotMessage[] }>(
                `/app/ai/consultations/${props.consultationId}/copilot`,
            );
            messages.value = result.messages;
        } finally {
            loaded.value = true;
            await scrollDown();
        }
    },
    { immediate: true },
);

const scrollDown = async () => {
    await nextTick();
    scroller.value?.scrollTo({
        top: scroller.value.scrollHeight,
        behavior: 'smooth',
    });
};

const send = async (text?: string) => {
    const message = (text ?? input.value).trim();

    if (!message || sending.value) {
        return;
    }

    sending.value = true;
    failure.value = null;
    input.value = '';
    messages.value.push({ role: 'user', content: message });
    await scrollDown();

    try {
        const result = await runAi<{ message: CopilotMessage }>(
            `/app/ai/consultations/${props.consultationId}/copilot`,
            {
                message,
                draft: props.draft,
                workspace: {
                    exams: aiWorkspace.bilanExams,
                    medications: aiWorkspace.ordonnanceItems,
                },
            },
        );
        messages.value.push(result.message);
    } catch (error) {
        messages.value.pop();
        input.value = message;
        failure.value = error as AiFailure;
    } finally {
        sending.value = false;
        await scrollDown();
    }
};

const reset = async () => {
    await deleteJson(`/app/ai/consultations/${props.consultationId}/copilot`);
    messages.value = [];
    applied.value = new Set();
};

const actionKey = (messageIndex: number, actionIndex: number): string =>
    `${messageIndex}:${actionIndex}`;

const apply = (action: CopilotAction, key: string) => {
    if (!props.canEdit || applied.value.has(key)) {
        return;
    }

    switch (action.type) {
        case 'set_field':
            emit('apply-field', action.field, action.text, action.mode);
            break;
        case 'add_exam':
            aiWorkspace.queuedExams.push({
                exam_id: action.exam_id,
                name: action.name,
            });
            break;
        case 'add_medication':
            aiWorkspace.queuedMedications.push({
                medication: action.medication,
                dosage: action.dosage,
                duration: action.duration,
                instructions: action.instructions,
            });
            break;
        case 'patient_advice':
            aiWorkspace.queuedAdvice.push(action.text);
            break;
    }

    applied.value = new Set([...applied.value, key]);
};

const copy = async (text: string) => {
    try {
        await navigator.clipboard.writeText(text);
    } catch {
        // Clipboard can be blocked; nothing else to do.
    }
};

const allergyWarnings = (action: CopilotAction): string[] =>
    action.type === 'add_medication' ? (action.allergy_conflicts ?? []) : [];

const queuedCount = computed(() => ({
    exams: aiWorkspace.queuedExams.length,
    medications:
        aiWorkspace.queuedMedications.length + aiWorkspace.queuedAdvice.length,
}));
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent
            side="right"
            class="flex w-full flex-col gap-0 p-0 sm:max-w-xl"
            data-testid="copilot-panel"
        >
            <SheetHeader class="border-b px-4 py-3">
                <div class="flex items-center justify-between gap-2 pr-8">
                    <SheetTitle class="flex items-center gap-2">
                        <Sparkles class="size-4 text-brand" /> Copilote
                    </SheetTitle>
                    <AiCreditsPill />
                </div>
                <SheetDescription class="text-xs">
                    Il connaît le dossier du patient. Il propose, vous appliquez
                    d’un clic. {{ creditsLabel(aiCost('copilot_chat')) }} par
                    message.
                </SheetDescription>
            </SheetHeader>

            <div
                ref="scroller"
                class="min-h-0 flex-1 space-y-3 overflow-y-auto px-4 py-4"
            >
                <div v-if="loaded && messages.length === 0" class="space-y-3">
                    <p class="text-sm text-muted-foreground">
                        Que puis-je faire pour vous ? Quelques idées :
                    </p>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <button
                            v-for="item in quickPrompts"
                            :key="item.label"
                            type="button"
                            class="flex items-center gap-2 rounded-xl border p-3 text-left text-sm transition hover:border-brand hover:bg-brand-soft/40 disabled:opacity-50"
                            :disabled="!canEdit || sending"
                            @click="send(item.prompt)"
                        >
                            <component
                                :is="item.icon"
                                class="size-4 shrink-0 text-brand"
                            />
                            {{ item.label }}
                        </button>
                    </div>
                </div>

                <template v-for="(message, index) in messages" :key="index">
                    <div
                        v-if="message.role === 'user'"
                        class="flex justify-end"
                    >
                        <p
                            class="max-w-[85%] rounded-2xl rounded-br-sm bg-brand px-3 py-2 text-sm whitespace-pre-line text-white"
                        >
                            {{ message.content }}
                        </p>
                    </div>
                    <div v-else class="space-y-2">
                        <div
                            class="rounded-2xl rounded-bl-sm bg-muted px-3 py-2"
                        >
                            <!-- formatAiText escapes everything before allowing bold. -->
                            <!-- eslint-disable-next-line vue/no-v-html -->
                            <p
                                class="text-sm whitespace-pre-line text-foreground"
                                v-html="formatAiText(message.reply)"
                            />
                            <div
                                class="mt-2 flex flex-wrap items-center gap-1.5"
                            >
                                <span
                                    v-for="source in message.sources"
                                    :key="source"
                                    class="rounded-full bg-background px-2 py-0.5 text-[10px] text-muted-foreground"
                                    :title="
                                        'Source dans le dossier : ' + source
                                    "
                                >
                                    📄 {{ source }}
                                </span>
                                <button
                                    type="button"
                                    class="ml-auto inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[11px] text-muted-foreground hover:bg-background hover:text-foreground"
                                    @click="copy(message.reply)"
                                >
                                    <ClipboardCopy class="size-3" /> Copier
                                </button>
                            </div>
                        </div>

                        <div
                            v-for="(action, actionIndex) in message.actions"
                            :key="actionIndex"
                            class="rounded-xl border border-brand/25 bg-brand-soft/30 p-2.5 text-sm dark:border-brand-mint/25 dark:bg-brand-deep/20"
                        >
                            <div class="flex items-start gap-2">
                                <component
                                    :is="
                                        action.type === 'add_exam'
                                            ? FlaskConical
                                            : action.type === 'add_medication'
                                              ? Pill
                                              : action.type === 'patient_advice'
                                                ? UserRound
                                                : PenLine
                                    "
                                    class="mt-0.5 size-4 shrink-0 text-brand dark:text-brand-mint"
                                />
                                <div class="min-w-0 flex-1">
                                    <p
                                        class="text-xs font-semibold text-brand dark:text-brand-mint"
                                    >
                                        <template
                                            v-if="action.type === 'set_field'"
                                        >
                                            {{
                                                action.mode === 'append'
                                                    ? 'Compléter'
                                                    : 'Remplir'
                                            }}
                                            « {{ fieldLabels[action.field] }} »
                                        </template>
                                        <template
                                            v-else-if="
                                                action.type === 'add_exam'
                                            "
                                        >
                                            Ajouter au bilan : {{ action.name }}
                                            <span
                                                v-if="action.exam_id === null"
                                                class="font-normal text-muted-foreground"
                                                >(hors catalogue)</span
                                            >
                                        </template>
                                        <template
                                            v-else-if="
                                                action.type === 'add_medication'
                                            "
                                        >
                                            Ajouter à l’ordonnance :
                                            {{ action.medication }}
                                        </template>
                                        <template v-else
                                            >Conseils pour le patient</template
                                        >
                                    </p>
                                    <p
                                        v-if="
                                            action.type === 'set_field' ||
                                            action.type === 'patient_advice'
                                        "
                                        class="mt-1 text-xs whitespace-pre-line text-foreground"
                                    >
                                        {{ action.text }}
                                    </p>
                                    <p
                                        v-else-if="
                                            action.type === 'add_medication'
                                        "
                                        class="mt-0.5 text-xs text-muted-foreground"
                                    >
                                        {{
                                            [
                                                action.dosage,
                                                action.duration,
                                                action.instructions,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')
                                        }}
                                    </p>
                                    <p
                                        v-if="
                                            'reason' in action && action.reason
                                        "
                                        class="mt-0.5 text-xs text-muted-foreground italic"
                                    >
                                        {{ action.reason }}
                                    </p>
                                    <p
                                        v-for="warning in allergyWarnings(
                                            action,
                                        )"
                                        :key="warning"
                                        class="mt-1 flex items-start gap-1 rounded-md bg-red-50 px-2 py-1 text-xs text-red-800 dark:bg-red-950/40 dark:text-red-200"
                                    >
                                        <ShieldAlert
                                            class="mt-px size-3.5 shrink-0"
                                        />
                                        Allergie : {{ warning }}
                                    </p>
                                </div>
                                <Button
                                    v-if="canEdit"
                                    size="sm"
                                    :variant="
                                        applied.has(
                                            actionKey(index, actionIndex),
                                        )
                                            ? 'ghost'
                                            : 'outline'
                                    "
                                    class="h-7 shrink-0 bg-background text-xs"
                                    :disabled="
                                        applied.has(
                                            actionKey(index, actionIndex),
                                        )
                                    "
                                    @click="
                                        apply(
                                            action,
                                            actionKey(index, actionIndex),
                                        )
                                    "
                                >
                                    <Check class="size-3.5" />
                                    {{
                                        applied.has(
                                            actionKey(index, actionIndex),
                                        )
                                            ? 'Appliqué'
                                            : 'Appliquer'
                                    }}
                                </Button>
                            </div>
                        </div>
                    </div>
                </template>

                <p
                    v-if="sending"
                    class="flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <Spinner class="size-4" /> Le copilote lit le dossier…
                </p>
            </div>

            <div class="space-y-2 border-t px-4 py-3">
                <div
                    v-if="queuedCount.exams || queuedCount.medications"
                    class="flex flex-wrap items-center gap-2 rounded-lg bg-brand-soft/50 px-3 py-2 text-xs dark:bg-brand-deep/30"
                >
                    <span class="flex-1">En attente d’être ajouté :</span>
                    <Button
                        v-if="queuedCount.exams"
                        size="sm"
                        variant="outline"
                        class="h-7 bg-background text-xs"
                        @click="
                            emit('open-section', 'bilans');
                            open = false;
                        "
                    >
                        <FlaskConical class="size-3.5" /> Bilan ({{
                            queuedCount.exams
                        }})
                    </Button>
                    <Button
                        v-if="queuedCount.medications"
                        size="sm"
                        variant="outline"
                        class="h-7 bg-background text-xs"
                        @click="
                            emit('open-section', 'ordonnances');
                            open = false;
                        "
                    >
                        <Pill class="size-3.5" /> Ordonnance ({{
                            queuedCount.medications
                        }})
                    </Button>
                </div>
                <AiNotice :failure="failure" @close="failure = null" />
                <form
                    v-if="canEdit"
                    class="flex items-end gap-2"
                    @submit.prevent="send()"
                >
                    <Textarea
                        v-model="input"
                        rows="2"
                        class="min-h-11 resize-none"
                        placeholder="Demandez au copilote… (Entrée pour envoyer)"
                        maxlength="2000"
                        :disabled="sending"
                        @keydown.enter.exact.prevent="send()"
                    />
                    <Button type="submit" :disabled="sending || !input.trim()">
                        <Send class="size-4" />
                    </Button>
                </form>
                <div class="flex items-center justify-between gap-2">
                    <AiDisclaimer />
                    <Button
                        v-if="messages.length"
                        size="sm"
                        variant="ghost"
                        class="h-7 shrink-0 text-xs"
                        @click="reset"
                    >
                        <MessageSquarePlus class="size-3.5" /> Nouvelle
                        conversation
                    </Button>
                </div>
            </div>
        </SheetContent>
    </Sheet>
</template>
