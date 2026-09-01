<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

import { useDesktopUpdateWatcher } from '@/lib/desktopUpdateWatcher';

/**
 * Tells the person using the app that a newer version is ready.
 *
 * Deliberately not a modal and not an automatic install. A clinic in the middle
 * of a consultation should never have the app restart under them, so this only
 * offers, and the install still happens on the settings page where the progress
 * and the risks are shown.
 */
const { available, dismissed, dismiss } = useDesktopUpdateWatcher();

const visible = computed(() => available.value !== null && !dismissed.value);
</script>

<template>
    <div
        v-if="visible"
        role="status"
        aria-live="polite"
        class="flex flex-wrap items-center gap-3 border-b border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100"
    >
        <span class="font-medium">
            La version {{ available?.version }} est disponible.
        </span>

        <Link
            href="/app/configuration/connectivity-backup"
            class="rounded-md bg-emerald-700 px-3 py-1 font-medium text-white transition hover:bg-emerald-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700"
        >
            Mettre à jour
        </Link>

        <button
            type="button"
            class="ml-auto rounded-md px-2 py-1 underline underline-offset-2 transition hover:bg-emerald-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700 dark:hover:bg-emerald-900"
            @click="dismiss"
        >
            Plus tard
        </button>
    </div>
</template>
