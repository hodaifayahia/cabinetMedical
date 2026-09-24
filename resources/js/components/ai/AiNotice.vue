<script setup lang="ts">
import { Mail, Phone, Sparkles, TriangleAlert, X } from '@lucide/vue';
import { computed } from 'vue';
import type { AiFailure } from '@/lib/ai';
import { aiState } from '@/lib/ai';

const props = defineProps<{ failure: AiFailure | null }>();

defineEmits<{ close: [] }>();

const needsSupport = computed(
    () =>
        props.failure?.reason === 'insufficient_credits' ||
        props.failure?.reason === 'disabled',
);
const support = computed(() => aiState.status?.support ?? null);
const phoneHref = computed(() =>
    support.value?.phone
        ? 'tel:' + support.value.phone.replace(/[^\d+]/g, '')
        : null,
);
const mailHref = computed(() =>
    support.value?.email
        ? `mailto:${support.value.email}?subject=${encodeURIComponent('Recharge crédits IA')}`
        : null,
);
</script>

<template>
    <div
        v-if="failure"
        role="status"
        class="relative rounded-xl border p-3 pr-9 text-sm"
        :class="
            needsSupport
                ? 'border-brand/30 bg-brand-soft/60 text-foreground dark:border-brand-mint/30 dark:bg-brand-deep/30'
                : 'border-amber-300 bg-amber-50 text-amber-950 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-100'
        "
    >
        <button
            type="button"
            class="absolute top-2 right-2 rounded-md p-1 text-muted-foreground transition hover:bg-background/70 hover:text-foreground"
            aria-label="Fermer"
            @click="$emit('close')"
        >
            <X class="size-3.5" />
        </button>

        <template v-if="needsSupport">
            <p
                class="flex items-center gap-2 font-semibold text-brand dark:text-brand-mint"
            >
                <Sparkles class="size-4" />
                {{
                    failure.reason === 'disabled'
                        ? 'L’assistant IA n’est pas activé'
                        : 'Vos crédits IA sont épuisés'
                }}
            </p>
            <p class="mt-1 text-muted-foreground">{{ failure.message }}</p>
            <p class="mt-2 text-muted-foreground">
                Pour recharger vos crédits, contactez le support ClickDz : nous
                vous répondons rapidement.
            </p>
            <div v-if="phoneHref || mailHref" class="mt-2 flex flex-wrap gap-2">
                <a
                    v-if="phoneHref"
                    :href="phoneHref"
                    class="inline-flex items-center gap-1.5 rounded-full bg-background px-3 py-1 text-xs font-medium text-foreground shadow-xs transition hover:shadow-sm"
                >
                    <Phone class="size-3.5 text-brand dark:text-brand-mint" />
                    {{ support?.phone }}
                </a>
                <a
                    v-if="mailHref"
                    :href="mailHref"
                    class="inline-flex items-center gap-1.5 rounded-full bg-background px-3 py-1 text-xs font-medium text-foreground shadow-xs transition hover:shadow-sm"
                >
                    <Mail class="size-3.5 text-brand dark:text-brand-mint" />
                    {{ support?.email }}
                </a>
            </div>
        </template>

        <p v-else class="flex items-start gap-2">
            <TriangleAlert class="mt-0.5 size-4 shrink-0" />
            <span>{{ failure.message }}</span>
        </p>
    </div>
</template>
