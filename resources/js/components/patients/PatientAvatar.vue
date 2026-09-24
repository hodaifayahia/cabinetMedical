<script setup lang="ts">
import { computed } from 'vue';
import { avatarTone, initials } from '@/lib/patientDisplay';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        id: number | string;
        name: string;
        size?: 'sm' | 'md' | 'lg';
        class?: string;
    }>(),
    { size: 'md', class: undefined },
);

const sizes = {
    sm: 'size-8 text-xs',
    md: 'size-10 text-sm',
    lg: 'size-16 text-xl',
};

const classes = computed(() =>
    cn(
        'flex shrink-0 items-center justify-center rounded-full font-semibold tracking-wide select-none',
        sizes[props.size],
        avatarTone(props.id),
        props.class,
    ),
);
</script>

<template>
    <span :class="classes" aria-hidden="true">{{ initials(name) }}</span>
</template>
