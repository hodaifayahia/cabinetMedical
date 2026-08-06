<script setup lang="ts">
import { computed } from 'vue';
import { SidebarInset } from '@/components/ui/sidebar';
import type { AppVariant } from '@/types';

type Props = {
    variant?: AppVariant;
    class?: string;
};

const props = withDefaults(defineProps<Props>(), {
    variant: 'sidebar',
});
const className = computed(() => props.class);
</script>

<template>
    <SidebarInset v-if="props.variant === 'sidebar'" :class="className">
        <slot />
    </SidebarInset>
    <main
        v-else
        class="flex min-h-[calc(100vh-4rem)] w-full flex-1 flex-col gap-4 bg-[#edf3f6] text-slate-900 dark:bg-slate-950 dark:text-slate-100"
        :class="className"
    >
        <slot />
    </main>
</template>
