<script setup lang="ts">
import { Sparkles } from '@lucide/vue';
import { computed, onMounted } from 'vue';
import { aiState, loadAiStatus } from '@/lib/ai';

onMounted(() => {
    void loadAiStatus();
});

const balance = computed(() => aiState.status?.balance ?? null);
const tone = computed(() => {
    if (balance.value === null) {
        return 'bg-muted text-muted-foreground';
    }

    if (balance.value <= 0 || aiState.status?.enabled === false) {
        return 'bg-red-50 text-red-700 dark:bg-red-950/40 dark:text-red-300';
    }

    return balance.value < 20
        ? 'bg-amber-50 text-amber-800 dark:bg-amber-950/40 dark:text-amber-200'
        : 'bg-brand-soft text-brand dark:bg-brand-deep/40 dark:text-brand-mint';
});
</script>

<template>
    <span
        v-if="aiState.status?.available"
        class="inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium tabular-nums"
        :class="tone"
        :title="
            aiState.status.enabled
                ? 'Crédits de l’assistant IA de votre cabinet'
                : 'Assistant IA désactivé pour ce cabinet'
        "
    >
        <Sparkles class="size-3.5" />
        <template v-if="aiState.status.enabled">
            {{ balance ?? '—' }} crédits IA
        </template>
        <template v-else>IA désactivée</template>
    </span>
</template>
