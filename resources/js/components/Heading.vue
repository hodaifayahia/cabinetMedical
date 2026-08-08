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
    <header :class="variant === 'small' ? '' : 'mb-6'">
        <h2
            :class="
                variant === 'small'
                    ? 'mb-0.5 text-base font-semibold text-foreground'
                    : 'med-page-title'
            "
        >
            {{ title }}
        </h2>
        <div
            v-if="variant !== 'small'"
            class="med-page-accent"
            aria-hidden="true"
        />
        <p
            v-if="description"
            :class="
                variant === 'small'
                    ? 'text-sm text-muted-foreground'
                    : 'med-page-description'
            "
        >
            {{ description }}
        </p>
    </header>
</template>
