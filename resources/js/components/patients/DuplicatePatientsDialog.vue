<script setup lang="ts">
import { CopyCheck, GitMerge, Loader2, ShieldCheck } from '@lucide/vue';
import { ref, watch } from 'vue';
import PatientAvatar from '@/components/patients/PatientAvatar.vue';
import PatientMergeDialog from '@/components/patients/PatientMergeDialog.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogDescription,
    DialogHeader,
    DialogScrollContent,
    DialogTitle,
} from '@/components/ui/dialog';
import { getJson } from '@/lib/http';
import { formatDate, relativeDay } from '@/lib/patientDisplay';
import type { PatientMergeSummary } from '@/types';

type Group = { reasons: string[]; patients: PatientMergeSummary[] };

const props = defineProps<{ open: boolean }>();
const emit = defineEmits<{ 'update:open': [open: boolean] }>();

const groups = ref<Group[]>([]);
const kept = ref<Record<number, number>>({});
const loading = ref(false);
const error = ref<string | null>(null);
const merging = ref<{
    primary: PatientMergeSummary;
    duplicateId: number;
} | null>(null);

const load = async () => {
    loading.value = true;
    error.value = null;

    try {
        const response = await getJson<{ groups: Group[] }>(
            '/app/patient-duplicates',
        );
        groups.value = response.groups;
        // Keep, by default, the dossier with the most history.
        kept.value = Object.fromEntries(
            response.groups.map((group, index) => [
                index,
                group.patients[0]?.id ?? 0,
            ]),
        );
    } catch {
        error.value = 'Impossible de rechercher les doublons.';
    } finally {
        loading.value = false;
    }
};

watch(
    () => props.open,
    (open) => {
        if (open) {
            void load();
        }
    },
    { immediate: true },
);

const keptOf = (index: number, group: Group): PatientMergeSummary =>
    group.patients.find((patient) => patient.id === kept.value[index]) ??
    group.patients[0];

const startMerge = (index: number, group: Group, duplicateId: number) => {
    merging.value = { primary: keptOf(index, group), duplicateId };
};
</script>

<template>
    <Dialog :open="open" @update:open="(value) => emit('update:open', value)">
        <DialogScrollContent class="sm:max-w-3xl">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <CopyCheck class="size-5 text-brand" />
                    Doublons possibles
                </DialogTitle>
                <DialogDescription>
                    Dossiers qui semblent décrire la même personne (même nom,
                    même date de naissance ou même téléphone). Choisissez le
                    dossier à garder, puis fusionnez les autres dedans.
                </DialogDescription>
            </DialogHeader>

            <div
                v-if="loading"
                class="flex items-center justify-center gap-2 py-10 text-sm text-muted-foreground"
            >
                <Loader2 class="size-4 animate-spin" />
                Analyse des dossiers…
            </div>

            <p v-else-if="error" class="text-sm text-destructive">
                {{ error }}
            </p>

            <div v-else-if="groups.length === 0" class="med-empty">
                <ShieldCheck class="med-empty-icon text-emerald-500/60" />
                <p class="med-empty-title">Aucun doublon détecté</p>
                <p class="med-empty-hint">
                    Vos dossiers patients sont propres.
                </p>
            </div>

            <ul v-else class="grid gap-4">
                <li
                    v-for="(group, index) in groups"
                    :key="group.patients.map((p) => p.id).join('-')"
                    class="rounded-2xl border bg-surface p-4"
                >
                    <div class="mb-3 flex flex-wrap gap-1.5">
                        <span
                            v-for="reason in group.reasons"
                            :key="reason"
                            class="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900/40 dark:text-amber-200"
                            >{{ reason }}</span
                        >
                    </div>

                    <div class="grid gap-2">
                        <div
                            v-for="patient in group.patients"
                            :key="patient.id"
                            class="flex flex-col gap-3 rounded-xl border p-3 transition sm:flex-row sm:items-center"
                            :class="
                                kept[index] === patient.id
                                    ? 'border-emerald-500/40 bg-emerald-50/50 dark:bg-emerald-950/20'
                                    : ''
                            "
                        >
                            <div class="flex min-w-0 flex-1 items-center gap-3">
                                <PatientAvatar
                                    :id="patient.id"
                                    :name="patient.full_name"
                                    size="sm"
                                />
                                <div class="min-w-0">
                                    <p class="truncate font-medium">
                                        {{ patient.full_name }}
                                        <span
                                            class="ml-1 font-mono text-xs font-normal text-muted-foreground"
                                            >{{ patient.patient_number }}</span
                                        >
                                    </p>
                                    <p
                                        class="flex flex-wrap gap-x-3 text-xs text-muted-foreground"
                                    >
                                        <span>{{
                                            formatDate(patient.date_of_birth)
                                        }}</span>
                                        <span v-if="patient.phone">{{
                                            patient.phone
                                        }}</span>
                                        <span
                                            >{{
                                                patient.visits_count
                                            }}
                                            visite(s)</span
                                        >
                                        <span v-if="patient.last_visit_at"
                                            >dernière
                                            {{
                                                relativeDay(
                                                    patient.last_visit_at,
                                                )
                                            }}</span
                                        >
                                    </p>
                                </div>
                            </div>

                            <span
                                v-if="kept[index] === patient.id"
                                class="inline-flex items-center gap-1 text-sm font-medium text-emerald-700 dark:text-emerald-300"
                            >
                                <ShieldCheck class="size-4" />
                                Dossier gardé
                            </span>
                            <div v-else class="flex gap-2">
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    @click="kept[index] = patient.id"
                                >
                                    Garder celui-ci
                                </Button>
                                <Button
                                    size="sm"
                                    @click="
                                        startMerge(index, group, patient.id)
                                    "
                                >
                                    <GitMerge class="size-4" />
                                    Fusionner
                                </Button>
                            </div>
                        </div>
                    </div>
                </li>
            </ul>
        </DialogScrollContent>
    </Dialog>

    <PatientMergeDialog
        v-if="merging"
        :open="merging !== null"
        :primary="merging.primary"
        :duplicate-id="merging.duplicateId"
        @update:open="(value) => !value && (merging = null)"
    />
</template>
