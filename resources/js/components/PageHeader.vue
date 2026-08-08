<script setup lang="ts">
import Heading from '@/components/Heading.vue';

type Props = {
    title: string;
    description?: string;
};

defineProps<Props>();
</script>

<template>
    <header
        class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
    >
        <Heading :title="title" :description="description" />
        <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2">
            <slot name="actions" />
        </div>
    </header>
</template>
