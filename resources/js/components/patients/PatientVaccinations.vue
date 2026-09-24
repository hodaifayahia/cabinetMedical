<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import {
    CircleAlert,
    CircleCheck,
    CircleDashed,
    Printer,
    Syringe,
    Trash2,
} from '@lucide/vue';
import { ref } from 'vue';
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

type Dose = {
    key: string;
    label: string;
    status: 'done' | 'due' | 'overdue' | 'upcoming';
};

export type VaccinationCard = {
    records: {
        id: string;
        vaccine: string;
        dose: string | null;
        schedule_key: string | null;
        given_on: string;
        lot: string | null;
        notes: string | null;
    }[];
    schedule: { key: string; label: string; due_on: string; doses: Dose[] }[];
    overdue: number;
    vaccines: string[];
};

const props = defineProps<{
    patientId: number;
    card: VaccinationCard;
    canEdit: boolean;
}>();

const today = (): string => new Date().toISOString().slice(0, 10);

const open = ref(false);
const form = useForm({
    vaccine: '',
    dose: '',
    schedule_key: '' as string,
    given_on: today(),
    lot: '',
    notes: '',
});

const record = (dose?: Dose) => {
    form.reset();
    form.clearErrors();
    form.given_on = today();

    if (dose) {
        form.schedule_key = dose.key;
        form.vaccine = dose.label;
    }

    open.value = true;
};

const save = () => {
    form.post(`/app/patients/${props.patientId}/vaccinations`, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
};

const remove = (id: string) => {
    router.delete(`/app/vaccinations/${id}`, { preserveScroll: true });
};

const dateLabel = (date: string): string =>
    new Intl.DateTimeFormat('fr-DZ', { dateStyle: 'medium' }).format(
        new Date(`${date}T00:00:00`),
    );

const statusStyle: Record<Dose['status'], string> = {
    done: 'text-emerald-700 dark:text-emerald-400',
    due: 'text-amber-700 dark:text-amber-400',
    overdue: 'text-rose-700 dark:text-rose-400 font-semibold',
    upcoming: 'text-muted-foreground',
};
</script>

<template>
    <section class="med-panel p-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="flex items-center gap-2 text-base font-bold">
                <Syringe class="size-4 text-brand" />
                Vaccinations
                <span
                    v-if="card.overdue > 0"
                    class="rounded-full bg-rose-500/10 px-2 py-0.5 text-xs font-semibold text-rose-700 dark:text-rose-400"
                    >{{ card.overdue }} dose(s) en retard</span
                >
            </h2>
            <div class="flex gap-2">
                <Button variant="outline" size="sm" as-child>
                    <a
                        :href="`/app/patients/${patientId}/vaccinations/print`"
                        target="_blank"
                        rel="noopener"
                    >
                        <Printer class="size-4" /> Carnet
                    </a>
                </Button>
                <Button v-if="canEdit" size="sm" @click="record()">
                    <Syringe class="size-4" /> Enregistrer un vaccin
                </Button>
            </div>
        </div>

        <div
            v-if="card.schedule.length"
            class="mt-4 grid gap-2 sm:grid-cols-2 xl:grid-cols-3"
        >
            <div
                v-for="slot in card.schedule"
                :key="slot.key"
                class="rounded-xl border p-3"
            >
                <p class="flex justify-between text-sm font-bold">
                    {{ slot.label }}
                    <span class="text-xs font-normal text-muted-foreground">{{
                        dateLabel(slot.due_on)
                    }}</span>
                </p>
                <ul class="mt-2 space-y-1">
                    <li
                        v-for="dose in slot.doses"
                        :key="dose.key"
                        class="flex items-center justify-between gap-2 text-xs"
                        :class="statusStyle[dose.status]"
                    >
                        <span class="flex items-center gap-1.5">
                            <CircleCheck
                                v-if="dose.status === 'done'"
                                class="size-3.5"
                            />
                            <CircleAlert
                                v-else-if="dose.status === 'overdue'"
                                class="size-3.5"
                            />
                            <CircleDashed v-else class="size-3.5" />
                            {{ dose.label }}
                        </span>
                        <button
                            v-if="canEdit && dose.status !== 'done'"
                            type="button"
                            class="rounded px-1.5 py-0.5 text-[11px] font-semibold text-brand hover:bg-brand-soft"
                            @click="record(dose)"
                        >
                            Fait
                        </button>
                    </li>
                </ul>
            </div>
        </div>
        <p
            v-if="card.schedule.length"
            class="mt-2 text-[11px] text-muted-foreground"
        >
            Calendrier national indicatif (MSPRH, révision 2016) — se référer au
            calendrier officiel en vigueur.
        </p>

        <div class="mt-4">
            <p class="text-sm font-semibold">Vaccins reçus</p>
            <ul
                v-if="card.records.length"
                class="mt-2 divide-y rounded-xl border"
            >
                <li
                    v-for="item in card.records"
                    :key="item.id"
                    class="flex items-center justify-between gap-3 px-3 py-2 text-sm"
                >
                    <span>
                        <span class="font-semibold">{{ item.vaccine }}</span>
                        <span class="text-muted-foreground">
                            · {{ dateLabel(item.given_on) }}
                            <template v-if="item.dose">
                                · {{ item.dose }}</template
                            >
                            <template v-if="item.lot">
                                · lot {{ item.lot }}</template
                            >
                        </span>
                    </span>
                    <Button
                        v-if="canEdit"
                        size="icon"
                        variant="ghost"
                        :aria-label="`Supprimer ${item.vaccine}`"
                        @click="remove(item.id)"
                    >
                        <Trash2 class="size-4 text-rose-600" />
                    </Button>
                </li>
            </ul>
            <p v-else class="mt-1 text-sm text-muted-foreground">
                Aucune vaccination enregistrée.
            </p>
        </div>
    </section>

    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Enregistrer un vaccin</DialogTitle>
                <DialogDescription>
                    Ajouté au carnet de vaccination du patient.
                </DialogDescription>
            </DialogHeader>
            <form class="grid gap-3" @submit.prevent="save">
                <div class="grid gap-1.5">
                    <Label for="vaccine-name">Vaccin</Label>
                    <Input
                        id="vaccine-name"
                        v-model="form.vaccine"
                        list="vaccine-options"
                        :readonly="form.schedule_key !== ''"
                    />
                    <datalist id="vaccine-options">
                        <option
                            v-for="vaccine in card.vaccines"
                            :key="vaccine"
                            :value="vaccine"
                        />
                    </datalist>
                    <InputError
                        :message="
                            form.errors.vaccine ?? form.errors.schedule_key
                        "
                    />
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="grid gap-1.5">
                        <Label for="vaccine-date">Date</Label>
                        <Input
                            id="vaccine-date"
                            v-model="form.given_on"
                            type="date"
                        />
                        <InputError :message="form.errors.given_on" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="vaccine-lot">N° de lot</Label>
                        <Input id="vaccine-lot" v-model="form.lot" />
                    </div>
                </div>
                <div v-if="form.schedule_key === ''" class="grid gap-1.5">
                    <Label for="vaccine-dose">Dose</Label>
                    <Input
                        id="vaccine-dose"
                        v-model="form.dose"
                        placeholder="Ex. 1re dose, rappel…"
                    />
                </div>
                <div class="grid gap-1.5">
                    <Label for="vaccine-notes">Remarque</Label>
                    <Input
                        id="vaccine-notes"
                        v-model="form.notes"
                        placeholder="Réaction, site d’injection…"
                    />
                </div>
                <div class="flex justify-end">
                    <Button type="submit" :disabled="form.processing">
                        Enregistrer
                    </Button>
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
