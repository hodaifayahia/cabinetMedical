<script setup lang="ts">
import { Sparkles } from '@lucide/vue';
import { computed, onMounted } from 'vue';
import { Spinner } from '@/components/ui/spinner';
import type { AiFeature } from '@/lib/ai';
import { aiCost, aiState, creditsLabel, loadAiStatus } from '@/lib/ai';

const props = withDefaults(
    defineProps<{
        feature: AiFeature;
        label: string;
        loading?: boolean;
        disabled?: boolean;
        size?: 'sm' | 'md';
    }>(),
    { loading: false, disabled: false, size: 'sm' },
);

defineEmits<{ click: [] }>();

onMounted(() => {
    void loadAiStatus();
});

const cost = computed(() => aiCost(props.feature));
const short = computed(
    () =>
        aiState.status?.balance != null && aiState.status.balance < cost.value,
);
</script>

<template>
    <button
        type="button"
        class="group inline-flex shrink-0 items-center gap-2 rounded-full border border-brand/30 bg-brand-soft/70 font-medium text-brand shadow-xs transition hover:-translate-y-px hover:border-brand/60 hover:bg-brand-soft hover:shadow-sm focus-visible:ring-2 focus-visible:ring-brand focus-visible:outline-none disabled:pointer-events-none disabled:opacity-60 dark:border-brand-mint/30 dark:bg-brand-deep/40 dark:text-brand-mint"
        :class="size === 'sm' ? 'h-8 px-3 text-xs' : 'h-10 px-4 text-sm'"
        :disabled="disabled || loading"
        :aria-busy="loading"
        @click="$emit('click')"
    >
        <Spinner v-if="loading" class="size-3.5" />
        <Sparkles
            v-else
            class="size-3.5 transition group-hover:scale-110 group-hover:rotate-12"
        />
        <span>{{ loading ? 'L’IA réfléchit…' : label }}</span>
        <span
            v-if="!loading"
            class="rounded-full px-1.5 py-px text-[10px] font-semibold tabular-nums"
            :class="
                short
                    ? 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-200'
                    : 'bg-white/80 text-brand dark:bg-slate-950/40 dark:text-brand-mint'
            "
            :title="
                short
                    ? 'Crédits IA insuffisants pour cette action'
                    : 'Coût de cette action'
            "
        >
            {{ creditsLabel(cost) }}
        </span>
    </button>
</template>
