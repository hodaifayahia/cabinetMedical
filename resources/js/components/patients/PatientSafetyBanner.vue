<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import {
    Activity,
    BellRing,
    CalendarClock,
    CircleCheck,
    Pill,
    Plus,
    Settings2,
    ShieldAlert,
    Trash2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
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

export type SafetyItem = {
    id: string;
    type: 'allergy' | 'condition' | 'treatment';
    label: string;
    severity: string | null;
    severity_label: string | null;
    details: string | null;
    since: string | null;
};

export type PatientSafetySummary = {
    allergies: SafetyItem[];
    conditions: SafetyItem[];
    treatments: SafetyItem[];
    legacy_allergies: string | null;
    severities: Record<string, string>;
    recalls: {
        id: string;
        due_on: string;
        reason: string;
        overdue: boolean;
    }[];
};

const props = defineProps<{
    patientId: number;
    safety: PatientSafetySummary;
    canEdit: boolean;
    consultationId?: number | null;
}>();

// --- Rappels (« revoir dans 3 mois ») --------------------------------------
const showRecall = ref(false);
const isoDate = (date: Date): string => {
    const pad = (value: number) => String(value).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
};
const inMonths = (months: number): string => {
    const date = new Date();
    date.setMonth(date.getMonth() + months);

    return isoDate(date);
};
const recallForm = useForm({
    due_on: inMonths(3),
    reason: '',
    consultation_id: null as number | null,
});

const openRecall = () => {
    recallForm.reset();
    recallForm.due_on = inMonths(3);
    recallForm.clearErrors();
    showRecall.value = true;
};

const saveRecall = () => {
    recallForm.consultation_id = props.consultationId ?? null;
    recallForm.post(`/app/patients/${props.patientId}/recalls`, {
        preserveScroll: true,
        onSuccess: () => {
            showRecall.value = false;
        },
    });
};

const cancelRecall = (id: string) => {
    router.patch(
        `/app/recalls/${id}`,
        { action: 'cancelled' },
        { preserveScroll: true },
    );
};

const shortDate = (date: string): string =>
    new Intl.DateTimeFormat('fr-DZ', { dateStyle: 'medium' }).format(
        new Date(`${date}T00:00:00`),
    );

const hasAllergies = computed(
    () =>
        props.safety.allergies.length > 0 ||
        props.safety.legacy_allergies !== null,
);

const showManager = ref(false);
const form = useForm({
    type: 'allergy' as SafetyItem['type'],
    label: '',
    severity: 'moderate',
    details: '',
    since: '',
});

const typeLabels: Record<SafetyItem['type'], string> = {
    allergy: 'Allergie',
    condition: 'Maladie chronique',
    treatment: 'Traitement au long cours',
};

const groupLabels: Record<SafetyItem['type'], string> = {
    allergy: 'Allergies',
    condition: 'Maladies chroniques',
    treatment: 'Traitements au long cours',
};

const placeholders: Record<SafetyItem['type'], string> = {
    allergy: 'Ex. Pénicilline, AINS, iode, latex…',
    condition: 'Ex. Diabète type 2, HTA, asthme…',
    treatment: 'Ex. Metformine 850 mg · 2 fois par jour',
};

const add = () => {
    form.post(`/app/patients/${props.patientId}/alerts`, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('label', 'details', 'since');
        },
    });
};

const retire = (item: SafetyItem) => {
    router.patch(
        `/app/patient-alerts/${item.id}/deactivate`,
        {},
        { preserveScroll: true },
    );
};

const remove = (item: SafetyItem) => {
    router.delete(`/app/patient-alerts/${item.id}`, { preserveScroll: true });
};

const groups = computed(() => [
    { type: 'allergy' as const, items: props.safety.allergies },
    { type: 'condition' as const, items: props.safety.conditions },
    { type: 'treatment' as const, items: props.safety.treatments },
]);
</script>

<template>
    <section
        class="rounded-2xl border p-3"
        :class="
            hasAllergies
                ? 'border-rose-300 bg-rose-50 dark:border-rose-500/40 dark:bg-rose-500/10'
                : 'border-border bg-card'
        "
        aria-label="Fiche de sécurité du patient"
    >
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="grid min-w-0 flex-1 gap-2">
                <p class="flex flex-wrap items-center gap-2 text-sm">
                    <ShieldAlert
                        v-if="hasAllergies"
                        class="size-4 shrink-0 text-rose-700 dark:text-rose-400"
                    />
                    <CircleCheck
                        v-else
                        class="size-4 shrink-0 text-emerald-600"
                    />
                    <span
                        class="font-bold"
                        :class="
                            hasAllergies
                                ? 'text-rose-800 dark:text-rose-300'
                                : 'text-foreground'
                        "
                        >Allergies :</span
                    >
                    <template v-if="safety.allergies.length">
                        <span
                            v-for="item in safety.allergies"
                            :key="item.id"
                            class="rounded-full px-2 py-0.5 text-xs font-semibold"
                            :class="
                                item.severity === 'severe'
                                    ? 'bg-rose-700 text-white'
                                    : 'bg-rose-100 text-rose-800 dark:bg-rose-500/20 dark:text-rose-200'
                            "
                            :title="item.details ?? undefined"
                        >
                            {{ item.label }}
                            <template v-if="item.severity_label">
                                · {{ item.severity_label }}
                            </template>
                        </span>
                    </template>
                    <span
                        v-if="safety.legacy_allergies"
                        class="text-xs text-rose-800 dark:text-rose-200"
                    >
                        {{ safety.allergies.length ? 'Note :' : '' }}
                        {{ safety.legacy_allergies }}
                    </span>
                    <span
                        v-if="!hasAllergies"
                        class="text-xs text-muted-foreground"
                        >Aucune allergie connue renseignée</span
                    >
                </p>
                <p
                    v-if="safety.conditions.length"
                    class="flex flex-wrap items-center gap-2 text-sm"
                >
                    <Activity class="size-4 shrink-0 text-amber-600" />
                    <span class="font-bold">Maladies chroniques :</span>
                    <span
                        v-for="item in safety.conditions"
                        :key="item.id"
                        class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-900 dark:bg-amber-500/20 dark:text-amber-200"
                        :title="item.details ?? undefined"
                        >{{ item.label }}</span
                    >
                </p>
                <p
                    v-if="safety.recalls.length"
                    class="flex flex-wrap items-center gap-2 text-sm"
                >
                    <CalendarClock class="size-4 shrink-0 text-brand" />
                    <span class="font-bold">Rappels prévus :</span>
                    <span
                        v-for="recall in safety.recalls"
                        :key="recall.id"
                        class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold"
                        :class="
                            recall.overdue
                                ? 'bg-rose-100 text-rose-800 dark:bg-rose-500/20 dark:text-rose-200'
                                : 'bg-brand-soft text-brand'
                        "
                    >
                        {{ shortDate(recall.due_on) }} · {{ recall.reason }}
                        <button
                            v-if="canEdit"
                            type="button"
                            class="ml-0.5 rounded-full px-1 hover:bg-black/10"
                            :aria-label="`Annuler le rappel ${recall.reason}`"
                            title="Annuler ce rappel"
                            @click="cancelRecall(recall.id)"
                        >
                            ×
                        </button>
                    </span>
                </p>
                <p
                    v-if="safety.treatments.length"
                    class="flex flex-wrap items-center gap-2 text-sm"
                >
                    <Pill class="size-4 shrink-0 text-sky-600" />
                    <span class="font-bold">Traitements au long cours :</span>
                    <span
                        v-for="item in safety.treatments"
                        :key="item.id"
                        class="rounded-full bg-sky-100 px-2 py-0.5 text-xs font-semibold text-sky-900 dark:bg-sky-500/20 dark:text-sky-200"
                        :title="item.details ?? undefined"
                        >{{ item.label }}</span
                    >
                </p>
            </div>
            <div v-if="canEdit" class="flex gap-2 print:hidden">
                <Button size="sm" variant="outline" @click="openRecall">
                    <BellRing class="size-4" />
                    Rappel
                </Button>
                <Button size="sm" variant="outline" @click="showManager = true">
                    <Settings2 class="size-4" />
                    Gérer
                </Button>
            </div>
        </div>
    </section>

    <Dialog v-model:open="showRecall">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Programmer un rappel</DialogTitle>
                <DialogDescription>
                    Le patient apparaîtra dans « Relances » à l’approche de la
                    date, avec un message WhatsApp / SMS prêt à envoyer.
                </DialogDescription>
            </DialogHeader>
            <form class="grid gap-3" @submit.prevent="saveRecall">
                <div class="flex flex-wrap gap-2">
                    <Button
                        v-for="months in [1, 3, 6, 12]"
                        :key="months"
                        type="button"
                        size="sm"
                        :variant="
                            recallForm.due_on === inMonths(months)
                                ? 'default'
                                : 'outline'
                        "
                        @click="recallForm.due_on = inMonths(months)"
                    >
                        {{ months === 12 ? '1 an' : `${months} mois` }}
                    </Button>
                </div>
                <div class="grid gap-1.5">
                    <Label for="recall-date">Date</Label>
                    <Input
                        id="recall-date"
                        v-model="recallForm.due_on"
                        type="date"
                    />
                    <InputError :message="recallForm.errors.due_on" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="recall-reason">Motif</Label>
                    <Input
                        id="recall-reason"
                        v-model="recallForm.reason"
                        placeholder="Ex. Contrôle HbA1c, renouvellement traitement…"
                    />
                    <InputError :message="recallForm.errors.reason" />
                </div>
                <div class="flex justify-end">
                    <Button type="submit" :disabled="recallForm.processing">
                        Programmer
                    </Button>
                </div>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="showManager">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>Fiche de sécurité du patient</DialogTitle>
                <DialogDescription>
                    Les allergies sont vérifiées automatiquement à chaque
                    ordonnance.
                </DialogDescription>
            </DialogHeader>

            <form
                class="grid gap-3 rounded-xl border bg-muted/30 p-4"
                @submit.prevent="add"
            >
                <div class="grid gap-3 sm:grid-cols-[180px_1fr]">
                    <div class="grid gap-1.5">
                        <Label for="safety-type">Type</Label>
                        <select
                            id="safety-type"
                            v-model="form.type"
                            class="h-10 rounded-xl border border-input bg-background px-3 text-sm shadow-sm"
                        >
                            <option
                                v-for="(label, value) in typeLabels"
                                :key="value"
                                :value="value"
                            >
                                {{ label }}
                            </option>
                        </select>
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="safety-label">Libellé</Label>
                        <Input
                            id="safety-label"
                            v-model="form.label"
                            :placeholder="placeholders[form.type]"
                        />
                        <InputError :message="form.errors.label" />
                    </div>
                </div>
                <div class="grid gap-3 sm:grid-cols-3">
                    <div v-if="form.type === 'allergy'" class="grid gap-1.5">
                        <Label for="safety-severity">Gravité</Label>
                        <select
                            id="safety-severity"
                            v-model="form.severity"
                            class="h-10 rounded-xl border border-input bg-background px-3 text-sm shadow-sm"
                        >
                            <option
                                v-for="(label, value) in safety.severities"
                                :key="value"
                                :value="value"
                            >
                                {{ label }}
                            </option>
                        </select>
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="safety-since">Depuis</Label>
                        <Input
                            id="safety-since"
                            v-model="form.since"
                            type="date"
                        />
                        <InputError :message="form.errors.since" />
                    </div>
                    <div
                        class="grid gap-1.5"
                        :class="form.type === 'allergy' ? '' : 'sm:col-span-2'"
                    >
                        <Label for="safety-details">Précisions</Label>
                        <Input
                            id="safety-details"
                            v-model="form.details"
                            :placeholder="
                                form.type === 'allergy'
                                    ? 'Réaction (urticaire, œdème…)'
                                    : 'Optionnel'
                            "
                        />
                    </div>
                </div>
                <div class="flex justify-end">
                    <Button type="submit" :disabled="form.processing">
                        <Plus class="size-4" />
                        Ajouter
                    </Button>
                </div>
            </form>

            <div class="grid gap-4">
                <div v-for="group in groups" :key="group.type">
                    <p class="text-sm font-bold">
                        {{ groupLabels[group.type] }}
                    </p>
                    <ul
                        v-if="group.items.length"
                        class="mt-2 divide-y rounded-xl border"
                    >
                        <li
                            v-for="item in group.items"
                            :key="item.id"
                            class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm"
                        >
                            <span class="min-w-0">
                                <span class="font-semibold">{{
                                    item.label
                                }}</span>
                                <span
                                    v-if="item.severity_label"
                                    class="ml-1 text-xs text-rose-700 dark:text-rose-300"
                                    >{{ item.severity_label }}</span
                                >
                                <span
                                    v-if="item.details || item.since"
                                    class="block text-xs text-muted-foreground"
                                >
                                    {{
                                        [
                                            item.details,
                                            item.since
                                                ? `depuis ${item.since}`
                                                : null,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')
                                    }}
                                </span>
                            </span>
                            <span class="flex gap-1">
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    title="N’est plus d’actualité (conservé dans l’historique)"
                                    @click="retire(item)"
                                >
                                    Retirer
                                </Button>
                                <Button
                                    size="icon"
                                    variant="ghost"
                                    aria-label="Supprimer (saisie erronée)"
                                    title="Supprimer (saisie erronée)"
                                    @click="remove(item)"
                                >
                                    <Trash2 class="size-4 text-rose-600" />
                                </Button>
                            </span>
                        </li>
                    </ul>
                    <p v-else class="mt-1 text-xs text-muted-foreground">
                        Aucun élément.
                    </p>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
