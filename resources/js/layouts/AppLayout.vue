<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import BackupReminderBanner from '@/components/BackupReminderBanner.vue';
import DesktopPinEnrollment from '@/components/DesktopPinEnrollment.vue';
import DesktopUpdateBanner from '@/components/DesktopUpdateBanner.vue';
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
    <DesktopUpdateBanner />
    <BackupReminderBanner />
    <AppHeaderLayout :breadcrumbs="breadcrumbs">
        <slot />
    </AppHeaderLayout>
</template>
