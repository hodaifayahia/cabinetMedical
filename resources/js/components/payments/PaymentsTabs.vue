<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ChartColumnBig, ListChecks, ReceiptText } from '@lucide/vue';
import { computed } from 'vue';

defineProps<{ active: 'journal' | 'analytics' | 'expenses' }>();

const page = usePage();

const tabs = computed(() => {
    const permissions: string[] = page.props.auth.user?.permissions ?? [];

    return [
        {
            key: 'journal',
            label: 'Journal des paiements',
            href: '/app/payments',
            icon: ListChecks,
            visible: true,
        },
        {
            key: 'analytics',
            label: 'Analyse financière',
            href: '/app/payments/analytics',
            icon: ChartColumnBig,
            visible: true,
        },
        {
            key: 'expenses',
            label: 'Charges',
            href: '/app/expenses',
            icon: ReceiptText,
            // Salaries and rent: doctor-level (reports) only.
            visible: permissions.includes('reports.view'),
        },
    ].filter((tab) => tab.visible);
});
</script>

<template>
    <nav
        class="inline-flex w-fit flex-wrap gap-1 rounded-xl border bg-muted/40 p-1"
        aria-label="Sections des paiements"
    >
        <Link
            v-for="tab in tabs"
            :key="tab.key"
            :href="tab.href"
            class="inline-flex h-9 items-center gap-2 rounded-lg px-3 text-sm font-semibold transition"
            :class="
                active === tab.key
                    ? 'bg-background text-foreground shadow-sm'
                    : 'text-muted-foreground hover:text-foreground'
            "
            :aria-current="active === tab.key ? 'page' : undefined"
        >
            <component :is="tab.icon" class="size-4" />
            {{ tab.label }}
        </Link>
    </nav>
</template>
