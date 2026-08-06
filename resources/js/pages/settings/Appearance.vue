<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import Heading from '@/components/Heading.vue';
import { edit } from '@/routes/appearance';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Paramètres d’apparence',
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Paramètres d’apparence" />

    <h1 class="sr-only">Paramètres d’apparence</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Apparence"
            description="Adaptez l’apparence de l’application à votre préférence."
        />
        <AppearanceTabs />
    </div>
</template>
