<script setup lang="ts">
import type { LinkComponentBaseProps, Method } from '@inertiajs/core';
import { Link } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';

withDefaults(
    defineProps<{
        href: LinkComponentBaseProps['href'];
        label?: string;
        method?: Method;
        as?: string;
    }>(),
    {
        label: 'Retour',
        method: 'get',
        as: 'a',
    },
);
</script>

<template>
    <Link
        :href="href"
        :method="method"
        :as="as"
        class="group mb-6 inline-flex items-center gap-2 rounded-lg text-sm font-semibold text-slate-500 transition hover:text-[#1268a5] focus-visible:outline-none dark:text-slate-400 dark:hover:text-sky-300"
        data-test="auth-back-link"
    >
        <span
            class="flex size-8 items-center justify-center rounded-full border border-slate-200 bg-white text-slate-600 shadow-sm transition group-hover:-translate-x-0.5 group-hover:border-sky-200 group-hover:text-[#1268a5] dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300"
        >
            <ArrowLeft class="size-4" aria-hidden="true" />
        </span>
        <span>{{ label }}</span>
    </Link>
</template>
