<script setup lang="ts">
type Props = {
    title: string;
    description?: string;
    variant?: 'default' | 'small';
};

withDefaults(defineProps<Props>(), {
    variant: 'default',
});
</script>

<template>
    <header :class="variant === 'small' ? '' : 'mb-7'">
        <h2
            :class="
                variant === 'small'
                    ? 'mb-0.5 text-base font-semibold text-slate-800 dark:text-slate-100'
                    : 'text-[2rem] leading-none font-bold tracking-tight text-[#111827] sm:text-[2.2rem] dark:text-slate-50'
            "
        >
            {{ title }}
        </h2>
        <div
            v-if="variant !== 'small'"
            class="mt-3 h-1 w-20 rounded-full bg-[#e2a719]"
            aria-hidden="true"
        />
        <p
            v-if="description"
            :class="
                variant === 'small'
                    ? 'text-sm text-muted-foreground'
                    : 'mt-3 max-w-3xl text-sm leading-6 text-slate-600 dark:text-slate-300'
            "
        >
            {{ description }}
        </p>
    </header>
</template>
