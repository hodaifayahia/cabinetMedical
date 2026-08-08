<script setup lang="ts">
import { Download } from '@lucide/vue';

withDefaults(
    defineProps<{
        available: boolean;
        url: string | null;
        label: string;
        unavailableLabel: string;
        reason?: string | null;
        variant?: 'primary' | 'inverse';
        size?: 'md' | 'lg';
    }>(),
    {
        reason: null,
        variant: 'primary',
        size: 'md',
    },
);
</script>

<template>
    <a
        v-if="available && url"
        :href="url"
        class="inline-flex items-center justify-center gap-2 rounded-xl font-semibold transition focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
        :class="[
            size === 'lg' ? 'h-13 px-7 text-base' : 'h-11 px-5 text-sm',
            variant === 'inverse'
                ? 'bg-white text-primary shadow-sm ring-offset-primary hover:bg-white/90 focus-visible:ring-white'
                : 'bg-primary text-primary-foreground shadow-sm shadow-primary/20 ring-offset-background hover:bg-primary/90 focus-visible:ring-ring',
        ]"
    >
        <Download :class="size === 'lg' ? 'size-5' : 'size-4'" />
        {{ label }}
    </a>
    <button
        v-else
        type="button"
        disabled
        :title="reason ?? undefined"
        class="inline-flex cursor-not-allowed items-center justify-center gap-2 rounded-xl font-semibold opacity-60"
        :class="[
            size === 'lg' ? 'h-13 px-7 text-base' : 'h-11 px-5 text-sm',
            variant === 'inverse'
                ? 'bg-white/80 text-primary'
                : 'bg-primary/50 text-primary-foreground',
        ]"
    >
        <Download :class="size === 'lg' ? 'size-5' : 'size-4'" />
        {{ unavailableLabel }}
    </button>
</template>
