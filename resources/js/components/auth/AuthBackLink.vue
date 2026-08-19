<script setup lang="ts">
import type { LinkComponentBaseProps, Method } from '@inertiajs/core';
import { Link, router } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { isTauri } from '@tauri-apps/api/core';
import { computed, onMounted, ref } from 'vue';
import { login } from '@/routes';

const props = withDefaults(
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

const destination = ref(props.href);
const desktopRuntime = ref(false);
const effectiveMethod = computed<Method>(() =>
    desktopRuntime.value ? 'get' : props.method,
);

const leaveActivation = (event: MouseEvent): void => {
    if (props.method !== 'post') {
        return;
    }

    event.preventDefault();
    router.post(
        '/logout',
        {},
        {
            replace: true,
            onFinish: () => window.location.assign('/login'),
        },
    );
};

onMounted(() => {
    if (isTauri()) {
        desktopRuntime.value = true;
        destination.value = login();
    }
});
</script>

<template>
    <Link
        :href="destination"
        :method="effectiveMethod"
        :as="as"
        @click="leaveActivation"
        class="group mb-6 inline-flex items-center gap-2 rounded-lg text-sm font-semibold text-slate-500 transition hover:text-brand focus-visible:outline-none dark:text-slate-400 dark:hover:text-brand-mint"
        data-test="auth-back-link"
    >
        <span
            class="flex size-8 items-center justify-center rounded-full border border-slate-200 bg-white text-slate-600 shadow-sm transition group-hover:-translate-x-0.5 group-hover:border-brand group-hover:text-brand dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300"
        >
            <ArrowLeft class="size-4" aria-hidden="true" />
        </span>
        <span>{{ label }}</span>
    </Link>
</template>
