<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { Search, Smartphone, Trash2, UserPlus, Users } from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { getJson } from '@/lib/http';
import type { FamilyMedicalRelative } from '@/lib/patientHistory';
import type { PatientOption, RelativeCandidate } from '@/types';

// The « Famille » card: relatives linked to this dossier, with a search to
// link another dossier of the cabinet (« Ajouter un proche »).
const props = defineProps<{
    patientId: number;
    patientName: string;
    relatives: FamilyMedicalRelative[];
    relationOptions: PatientOption[];
    canEdit: boolean;
}>();

const open = ref(false);
const query = ref('');
const searching = ref(false);
const searchError = ref<string | null>(null);
const candidates = ref<RelativeCandidate[]>([]);
const selected = ref<RelativeCandidate | null>(null);

const form = useForm({
    relative_id: null as number | null,
    relation: 'brother',
});

const runSearch = useDebounceFn(async (term: string) => {
    if (term.trim().length < 2) {
        candidates.value = [];
        searching.value = false;

        return;
    }

    try {
        const response = await getJson<{ patients: RelativeCandidate[] }>(
            `/app/patients/${props.patientId}/relatives/search?q=${encodeURIComponent(term.trim())}`,
        );

        // Ignore an answer to an older query.
        if (term === query.value) {
            candidates.value = response.patients;
            searchError.value = null;
        }
    } catch {
        searchError.value = 'La recherche a échoué. Réessayez.';
    } finally {
        if (term === query.value) {
            searching.value = false;
        }
    }
}, 250);

watch(query, (term) => {
    searching.value = term.trim().length >= 2;
    void runSearch(term);
});

const openDialog = () => {
    query.value = '';
    candidates.value = [];
    selected.value = null;
    searchError.value = null;
    form.reset();
    form.clearErrors();
    open.value = true;
};

const choose = (candidate: RelativeCandidate) => {
    if (candidate.linked) {
        return;
    }

    selected.value = candidate;
    form.relative_id = candidate.id;
};

const submit = () => {
    form.post(`/app/patients/${props.patientId}/relatives`, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
};

const remove = (relative: FamilyMedicalRelative) => {
    if (!relative.link_id) {
        return;
    }

    if (
        !window.confirm(
            `Retirer ${relative.full_name} des proches de ${props.patientName} ?`,
        )
    ) {
        return;
    }

    router.delete(
        `/app/patients/${props.patientId}/relatives/${relative.link_id}`,
        { preserveScroll: true },
    );
};

const genderLabel = (gender: string | null): string =>
    gender === 'male' ? 'H' : gender === 'female' ? 'F' : '';

const hasRelatives = computed(() => props.relatives.length > 0);
</script>

<template>
    <section class="med-panel p-5" aria-label="Famille du patient">
        <div class="flex items-center justify-between gap-2">
            <h2
                class="flex items-center gap-2 text-sm font-semibold text-muted-foreground"
            >
                <Users class="size-4" />
                Famille
            </h2>
            <Button
                v-if="canEdit"
                size="sm"
                variant="outline"
                data-testid="add-relative"
                @click="openDialog"
            >
                <UserPlus class="size-4" />
                Ajouter un proche
            </Button>
        </div>

        <ul v-if="hasRelatives" class="mt-3 grid gap-2">
            <li
                v-for="relative in relatives"
                :key="relative.patient_id"
                class="flex items-start justify-between gap-2 rounded-lg border px-3 py-2"
            >
                <div class="min-w-0">
                    <Link
                        :href="`/app/patients/${relative.patient_id}`"
                        class="font-medium hover:text-brand hover:underline"
                    >
                        {{ relative.full_name }}
                    </Link>
                    <p class="text-xs text-muted-foreground">
                        {{ relative.relation_label }}
                        <template v-if="relative.age !== null">
                            · {{ relative.age }} ans</template
                        >
                        <template v-if="relative.patient_number">
                            ·
                            <span class="font-mono">{{
                                relative.patient_number
                            }}</span></template
                        >
                    </p>
                    <p
                        v-if="relative.summary"
                        class="mt-1 line-clamp-2 text-xs text-amber-800 dark:text-amber-300"
                    >
                        {{ relative.summary }}
                    </p>
                    <p
                        v-if="relative.source === 'mobile'"
                        class="mt-1 inline-flex items-center gap-1 text-[11px] text-muted-foreground"
                    >
                        <Smartphone class="size-3" /> Même compte famille
                        (application mobile)
                    </p>
                </div>
                <Button
                    v-if="canEdit && relative.link_id"
                    size="icon"
                    variant="ghost"
                    :aria-label="`Retirer ${relative.full_name} des proches`"
                    title="Retirer ce lien familial"
                    @click="remove(relative)"
                >
                    <Trash2 class="size-4 text-rose-600" />
                </Button>
            </li>
        </ul>
        <p v-else class="mt-3 text-sm text-muted-foreground">
            Aucun proche lié. Liez un parent, un frère, une sœur ou un enfant
            suivi au cabinet pour voir ses antécédents ici et pendant la
            consultation.
        </p>

        <Dialog v-model:open="open">
            <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Ajouter un proche</DialogTitle>
                    <DialogDescription>
                        Cherchez le dossier du proche (nom, téléphone ou n° de
                        dossier). Le lien est enregistré dans les deux dossiers.
                    </DialogDescription>
                </DialogHeader>

                <form class="grid gap-4" @submit.prevent="submit">
                    <div class="grid gap-1.5">
                        <Label for="relative-search">Patient</Label>
                        <div class="relative">
                            <Search
                                class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                            />
                            <Input
                                id="relative-search"
                                v-model="query"
                                class="pl-9"
                                placeholder="Ex. Benali Ahmed, 0555…, P-00012"
                                autocomplete="off"
                            />
                        </div>
                        <p
                            v-if="searchError"
                            class="text-xs text-destructive"
                            role="alert"
                        >
                            {{ searchError }}
                        </p>
                        <ul
                            v-if="candidates.length"
                            class="max-h-56 divide-y overflow-y-auto rounded-xl border"
                            role="listbox"
                        >
                            <li
                                v-for="candidate in candidates"
                                :key="candidate.id"
                            >
                                <button
                                    type="button"
                                    class="flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm transition hover:bg-muted disabled:cursor-not-allowed disabled:opacity-60"
                                    :class="
                                        selected?.id === candidate.id
                                            ? 'bg-brand-soft'
                                            : ''
                                    "
                                    :disabled="candidate.linked"
                                    role="option"
                                    :aria-selected="
                                        selected?.id === candidate.id
                                    "
                                    @click="choose(candidate)"
                                >
                                    <span class="min-w-0">
                                        <span class="font-medium">{{
                                            candidate.name
                                        }}</span>
                                        <span
                                            class="block text-xs text-muted-foreground"
                                        >
                                            {{
                                                [
                                                    candidate.number,
                                                    candidate.age !== null
                                                        ? `${candidate.age} ans`
                                                        : null,
                                                    genderLabel(
                                                        candidate.gender,
                                                    ),
                                                    candidate.phone,
                                                ]
                                                    .filter(Boolean)
                                                    .join(' · ')
                                            }}
                                        </span>
                                    </span>
                                    <span
                                        v-if="candidate.linked"
                                        class="shrink-0 text-xs text-muted-foreground"
                                        >Déjà lié</span
                                    >
                                </button>
                            </li>
                        </ul>
                        <p
                            v-else-if="
                                query.trim().length >= 2 &&
                                !searching &&
                                !searchError
                            "
                            class="text-xs text-muted-foreground"
                        >
                            Aucun patient trouvé. Le proche doit d’abord avoir
                            un dossier au cabinet.
                        </p>
                        <InputError :message="form.errors.relative_id" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="relative-relation">
                            Lien de parenté
                            <template v-if="selected">
                                — {{ selected.name }} est le/la … de
                                {{ patientName }}</template
                            >
                        </Label>
                        <select
                            id="relative-relation"
                            v-model="form.relation"
                            class="h-10 rounded-xl border border-input bg-background px-3 text-sm shadow-sm"
                        >
                            <option
                                v-for="option in relationOptions"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </select>
                        <InputError :message="form.errors.relation" />
                    </div>

                    <div class="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            @click="open = false"
                        >
                            Annuler
                        </Button>
                        <Button
                            type="submit"
                            :disabled="form.processing || !form.relative_id"
                        >
                            <UserPlus class="size-4" />
                            Lier ce proche
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    </section>
</template>
