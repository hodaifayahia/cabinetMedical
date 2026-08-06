<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { BellRing, CalendarClock } from '@lucide/vue';
import { onMounted, ref } from 'vue';
import { appointmentStatusLabel } from '@/components/consultations/display';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';

type AppointmentRow = {
    id: number;
    date: string | null;
    time: string | null;
    status: string;
    reason: string | null;
};
type Slot = {
    starts_at: string;
    label: string;
    available: boolean;
    reason: string | null;
};
type Prestation = { label: string; amount: number | null };

const props = defineProps<{
    consultationId: number;
    upcoming: AppointmentRow[];
    past: AppointmentRow[];
    prestations: Prestation[];
    canEdit: boolean;
}>();

const today = new Date().toISOString().slice(0, 10);
const date = ref(today);
const slots = ref<Slot[]>([]);
const slotReason = ref<string | null>(null);
const loadingSlots = ref(false);
const selectedSlot = ref('');

const form = useForm({ starts_at: '', title: 'Consultation', notes: '' });

const reasonText = (reason: string | null): string => {
    switch (reason) {
        case 'month_closed':
            return "Ce mois n'est pas encore ouvert à la réservation.";
        case 'not_working_day':
            return 'Jour non travaillé.';
        case 'day_off':
            return 'Jour de congé / férié.';
        default:
            return 'Aucun créneau disponible ce jour.';
    }
};

const loadSlots = async () => {
    loadingSlots.value = true;
    selectedSlot.value = '';

    try {
        const res = await fetch(
            `/app/appointments/availability/day?date=${date.value}`,
            {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            },
        );
        const data = await res.json();
        slots.value = data.slots ?? [];
        slotReason.value = data.reason ?? null;
    } finally {
        loadingSlots.value = false;
    }
};

onMounted(loadSlots);

const submit = () => {
    if (!selectedSlot.value) {
        return;
    }

    form.starts_at = selectedSlot.value;
    form.post(`/app/consultations/${props.consultationId}/schedule-next`, {
        preserveScroll: true,
        onSuccess: () => {
            selectedSlot.value = '';
            loadSlots();
        },
    });
};
</script>

<template>
    <div class="grid gap-4 lg:grid-cols-2">
        <div
            class="rounded-xl border border-sidebar-border/70 bg-background p-5 dark:border-sidebar-border"
        >
            <h3
                class="flex items-center gap-2 text-base font-semibold text-foreground"
            >
                <CalendarClock class="size-4 text-primary" /> Nouveau
                rendez-vous
            </h3>

            <div class="mt-4 space-y-4">
                <div class="grid gap-1.5">
                    <Label>Prestation</Label>
                    <Select
                        :model-value="form.title"
                        @update:model-value="
                            (v) => (form.title = (v as string) ?? '')
                        "
                    >
                        <SelectTrigger class="w-full"
                            ><SelectValue placeholder="Choisir une prestation"
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="Consultation"
                                >Consultation</SelectItem
                            >
                            <SelectItem
                                v-for="p in prestations"
                                :key="p.label"
                                :value="p.label"
                            >
                                {{ p.label
                                }}<template v-if="p.amount">
                                    — {{ p.amount }} DA</template
                                >
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div class="grid gap-1.5">
                    <Label for="rv-date">Date</Label>
                    <Input
                        id="rv-date"
                        v-model="date"
                        type="date"
                        :disabled="!canEdit"
                        @change="loadSlots"
                    />
                </div>

                <div class="grid gap-1.5">
                    <Label>Créneaux disponibles</Label>
                    <p
                        v-if="loadingSlots"
                        class="text-sm text-muted-foreground"
                    >
                        Chargement…
                    </p>
                    <div
                        v-else-if="slots.length"
                        class="grid grid-cols-3 gap-2 sm:grid-cols-4"
                    >
                        <button
                            v-for="slot in slots"
                            :key="slot.starts_at"
                            type="button"
                            :disabled="!slot.available || !canEdit"
                            class="rounded-md border px-2 py-1.5 text-sm transition"
                            :class="
                                selectedSlot === slot.starts_at
                                    ? 'border-primary bg-primary text-primary-foreground'
                                    : slot.available
                                      ? 'cursor-pointer border-emerald-300/70 bg-emerald-50 text-emerald-900 hover:bg-emerald-100 dark:bg-emerald-950/30 dark:text-emerald-200'
                                      : 'cursor-not-allowed border-sidebar-border/60 bg-muted/40 text-muted-foreground'
                            "
                            @click="selectedSlot = slot.starts_at"
                        >
                            {{ slot.label }}
                        </button>
                    </div>
                    <p
                        v-else
                        class="rounded-md bg-muted/40 px-3 py-2 text-sm text-muted-foreground"
                    >
                        {{ reasonText(slotReason) }}
                    </p>
                </div>

                <div class="grid gap-1.5">
                    <Label for="rv-notes">Notes internes</Label>
                    <Textarea
                        id="rv-notes"
                        v-model="form.notes"
                        rows="2"
                        :disabled="!canEdit"
                    />
                </div>

                <Button
                    v-if="canEdit"
                    class="w-full"
                    :disabled="form.processing || !selectedSlot"
                    @click="submit"
                >
                    <BellRing class="size-4" /> Planifier
                </Button>
            </div>
        </div>

        <div
            class="rounded-xl border border-sidebar-border/70 bg-background p-5 dark:border-sidebar-border"
        >
            <div class="flex items-center justify-between">
                <h3 class="text-base font-semibold text-foreground">
                    Rendez-vous du patient
                </h3>
                <span class="text-xs text-muted-foreground"
                    >{{ upcoming.length }} à venir ·
                    {{ past.length }} passés</span
                >
            </div>

            <p
                class="mt-4 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
            >
                À venir
            </p>
            <ul v-if="upcoming.length" class="mt-2 space-y-2">
                <li
                    v-for="a in upcoming"
                    :key="a.id"
                    class="flex items-center justify-between rounded-lg border border-sidebar-border/70 px-3 py-2 text-sm dark:border-sidebar-border"
                >
                    <span class="font-medium text-foreground"
                        >{{ a.date }} · {{ a.time }}</span
                    >
                    <span class="text-xs text-muted-foreground capitalize">{{
                        appointmentStatusLabel(a.status)
                    }}</span>
                </li>
            </ul>
            <p v-else class="mt-2 text-sm text-muted-foreground">
                Aucun rendez-vous à venir.
            </p>

            <p
                class="mt-5 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
            >
                Passés
            </p>
            <ul v-if="past.length" class="mt-2 space-y-2">
                <li
                    v-for="a in past"
                    :key="a.id"
                    class="flex items-center justify-between rounded-lg border border-sidebar-border/70 px-3 py-2 text-sm dark:border-sidebar-border"
                >
                    <span class="font-medium text-foreground"
                        >{{ a.date }} · {{ a.time }}</span
                    >
                    <span class="text-xs text-muted-foreground capitalize">{{
                        appointmentStatusLabel(a.status)
                    }}</span>
                </li>
            </ul>
            <p v-else class="mt-2 text-sm text-muted-foreground">
                Aucun ancien rendez-vous.
            </p>
        </div>
    </div>
</template>
