<script setup lang="ts">
// Status line of the consultation dictation panel: which engine listens, the
// live microphone level, a microphone check, and the Windows fallback.
import { Keyboard, Mic } from '@lucide/vue';
import { computed } from 'vue';
import type { DictationEngine } from '@/composables/useDictation';

const props = defineProps<{
    supported: boolean;
    engine: DictationEngine | null;
    listening: boolean;
    level: number;
    testing: boolean;
    testResult: 'ok' | 'silent' | null;
}>();

defineEmits<{ test: [] }>();

const showMeter = computed(
    () => props.testing || (props.listening && props.engine === 'recorder'),
);
const percent = computed(() =>
    Math.round(Math.min(1, Math.max(0, props.level)) * 100),
);
</script>

<template>
    <div class="space-y-1.5 text-xs" data-testid="dictation-status">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <p
                v-if="supported"
                class="text-muted-foreground"
                data-testid="dictation-engine"
            >
                {{
                    engine === 'speech'
                        ? 'Reconnaissance vocale du navigateur'
                        : 'Micro enregistré, transcrit par l’assistant IA (sans crédit)'
                }}
            </p>
            <button
                v-if="supported && !listening"
                type="button"
                class="inline-flex items-center gap-1 rounded-full border border-sidebar-border/70 bg-background px-2.5 py-1 font-medium hover:bg-muted disabled:opacity-60 dark:border-sidebar-border"
                :disabled="testing"
                data-testid="dictation-test-mic"
                @click="$emit('test')"
            >
                <Mic class="size-3" />
                {{ testing ? 'Parlez pour tester…' : 'Tester le micro' }}
            </button>
        </div>
        <div
            v-if="showMeter"
            class="h-1.5 w-full overflow-hidden rounded-full bg-muted"
            role="meter"
            aria-label="Niveau du micro"
            aria-valuemin="0"
            aria-valuemax="100"
            :aria-valuenow="percent"
            data-testid="dictation-level"
        >
            <div
                class="h-full rounded-full bg-emerald-500 transition-[width] duration-100"
                :style="{ width: `${percent}%` }"
            />
        </div>
        <p
            v-if="testResult === 'ok'"
            class="text-emerald-700 dark:text-emerald-400"
            data-testid="dictation-test-result"
        >
            Micro OK : le son est bien capté.
        </p>
        <p
            v-else-if="testResult === 'silent'"
            class="text-amber-700 dark:text-amber-400"
            data-testid="dictation-test-result"
        >
            Le micro est autorisé mais aucun son n’est capté. Vérifiez le micro
            choisi dans Paramètres Windows › Système › Son › Entrée.
        </p>
        <p
            class="flex items-start gap-1.5 text-muted-foreground"
            data-testid="dictation-windows-hint"
        >
            <Keyboard class="mt-px size-3.5 shrink-0" />
            <span>
                Astuce Windows : sans Internet, cliquez dans le champ texte puis
                appuyez sur Windows + H pour la dictée vocale de Windows.
            </span>
        </p>
    </div>
</template>
