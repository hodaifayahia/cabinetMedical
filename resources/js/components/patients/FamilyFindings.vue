<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Users } from '@lucide/vue';
import { computed } from 'vue';
import { relativesWithFindings } from '@/lib/patientHistory';
import type { FamilyMedicalRelative } from '@/lib/patientHistory';

// « Signalé chez les proches »: what the dossiers of the patient's relatives
// report (allergies, chronic diseases, operations, recent diagnoses).
const props = withDefaults(
    defineProps<{
        relatives: FamilyMedicalRelative[];
        /** Link each relative to their dossier. */
        linkDossiers?: boolean;
        title?: string;
    }>(),
    { linkDossiers: false, title: 'Signalé chez les proches' },
);

const reported = computed(() => relativesWithFindings(props.relatives));

const tone = (category: string): string =>
    ({
        allergy:
            'bg-rose-100 text-rose-800 dark:bg-rose-500/20 dark:text-rose-200',
        condition:
            'bg-amber-100 text-amber-900 dark:bg-amber-500/20 dark:text-amber-200',
        surgical:
            'bg-sky-100 text-sky-900 dark:bg-sky-500/20 dark:text-sky-200',
        diagnosis:
            'bg-violet-100 text-violet-900 dark:bg-violet-500/20 dark:text-violet-200',
    })[category] ?? 'bg-muted text-foreground';
</script>

<template>
    <div
        v-if="reported.length"
        class="rounded-xl border border-amber-300/70 bg-amber-50/70 p-3 dark:border-amber-500/40 dark:bg-amber-500/10"
        data-testid="family-findings"
    >
        <p
            class="flex items-center gap-1.5 text-xs font-semibold text-amber-900 dark:text-amber-200"
        >
            <Users class="size-3.5" />
            {{ title }}
        </p>
        <ul class="mt-2 grid gap-2">
            <li
                v-for="relative in reported"
                :key="relative.patient_id"
                class="text-sm"
            >
                <p class="font-medium text-foreground">
                    {{ relative.relation_label }} —
                    <Link
                        v-if="linkDossiers"
                        :href="`/app/patients/${relative.patient_id}`"
                        class="hover:text-brand hover:underline"
                        >{{ relative.short_name }}</Link
                    >
                    <template v-else>{{ relative.short_name }}</template>
                    <span
                        v-if="relative.age !== null"
                        class="text-xs font-normal text-muted-foreground"
                    >
                        · {{ relative.age }} ans</span
                    >
                </p>
                <div class="mt-1 flex flex-wrap gap-1">
                    <span
                        v-for="(item, index) in relative.items"
                        :key="`${relative.patient_id}-${index}`"
                        class="rounded-full px-2 py-0.5 text-xs font-medium break-words"
                        :class="tone(item.category)"
                        :title="item.label"
                        >{{ item.display }}</span
                    >
                </div>
            </li>
        </ul>
    </div>
</template>
