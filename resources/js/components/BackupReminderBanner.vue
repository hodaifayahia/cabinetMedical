<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { AlertTriangle } from '@lucide/vue';
import { computed } from 'vue';

import {
    backupReminderCopy,
    normalizeBackupReminder,
} from '@/lib/backupReminder';

/**
 * Local backups on the desktop are required, and this strip keeps saying so
 * while none is recent. It is deliberately neither a modal nor a lock: a
 * clinic in the middle of a consultation keeps working normally. It cannot be
 * dismissed either, only resolved. The server shares it only with someone who
 * can act on it, usually the clinic's doctor.
 */
const page = usePage();

const copy = computed(() => {
    const reminder = normalizeBackupReminder(page.props.backupReminder);

    return reminder === null ? null : backupReminderCopy(reminder.state);
});
</script>

<template>
    <div
        v-if="copy"
        role="status"
        aria-live="polite"
        class="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-950 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100"
    >
        <AlertTriangle
            class="size-4 shrink-0 text-amber-600 dark:text-amber-400"
            aria-hidden="true"
        />
        <span class="font-semibold">{{ copy.title }}</span>
        <span class="min-w-0 flex-1 basis-64">{{ copy.message }}</span>

        <Link
            :href="copy.action.href"
            class="rounded-md bg-amber-700 px-3 py-1 font-medium text-white transition hover:bg-amber-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-700"
        >
            {{ copy.action.label }}
        </Link>
    </div>
</template>
