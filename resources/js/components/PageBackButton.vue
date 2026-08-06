<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { Button } from '@/components/ui/button';

defineProps<{
    href: string;
    label?: string;
}>();
</script>

<template>
    <Button variant="outline" size="sm" as-child>
        <Link :href="href">
            <ArrowLeft class="size-4" />
            {{ label ?? 'Back' }}
        </Link>
    </Button>
</template>
