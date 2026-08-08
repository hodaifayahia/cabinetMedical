<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import DesktopPinEnrollment from '@/components/DesktopPinEnrollment.vue';
import AppHeaderLayout from '@/layouts/app/AppHeaderLayout.vue';
import { markDesktopOnboardingComplete } from '@/lib/desktopOnboarding';
import type { BreadcrumbItem } from '@/types';

const { breadcrumbs = [] } = defineProps<{
    breadcrumbs?: BreadcrumbItem[];
}>();

if (usePage().props.auth.user) {
    markDesktopOnboardingComplete();
}
</script>

<template>
    <DesktopPinEnrollment />
    <AppHeaderLayout :breadcrumbs="breadcrumbs">
        <slot />
    </AppHeaderLayout>
</template>
