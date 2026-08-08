<script setup lang="ts">
import type { InertiaLinkProps } from '@inertiajs/vue3';
import { Link } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { Button } from '@/components/ui/button';

defineProps<{
    href: NonNullable<InertiaLinkProps['href']>;
    label?: string;
    compact?: boolean;
}>();
</script>

<template>
    <Button
        variant="outline"
        :size="compact ? 'icon' : 'sm'"
        as-child
        :class="compact ? 'size-9 shrink-0 rounded-full' : undefined"
    >
        <Link :href="href" :aria-label="label ?? 'Retour'">
            <ArrowLeft class="size-4" />
            <span v-if="!compact">{{ label ?? 'Retour' }}</span>
        </Link>
    </Button>
</template>
