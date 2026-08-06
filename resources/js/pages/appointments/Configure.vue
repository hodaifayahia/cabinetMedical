<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, CalendarPlus, Trash2 } from '@lucide/vue';
import ConfigurationTabs from '@/components/configuration/ConfigurationTabs.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type {
    OpenMonth,
    ScheduleDay,
    TimeOffEntry,
    WeekdayOption,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Configuration', href: '/app/configuration' },
            {
                title: 'Disponibilités des rendez-vous',
                href: '/app/appointments/configure',
            },
        ],
    },
});

const props = defineProps<{
    hasDoctor: boolean;
    weekdays: WeekdayOption[];
    schedule: ScheduleDay[];
    openMonths: OpenMonth[];
    timeOff: TimeOffEntry[];
    defaultDuration: number;
}>();

const now = new Date();

type ScheduleFormDay = Omit<ScheduleDay, 'slot_duration'> & {
    slot_duration: number | string;
};

const scheduleForm = useForm<{ days: ScheduleFormDay[] }>({
    days: props.schedule.map((day) => ({
        ...day,
        slot_duration: day.slot_duration ?? '',
    })),
});

const openMonthForm = useForm<{ year: number; month: number; note: string }>({
    year: now.getFullYear(),
    month: now.getMonth() + 1,
    note: '',
});

const timeOffForm = useForm<{
    starts_at: string;
    ends_at: string;
    reason: string;
}>({
    starts_at: '',
    ends_at: '',
    reason: '',
});

const monthOptions = Array.from({ length: 12 }, (_, index) => ({
    value: index + 1,
    label: new Date(2000, index, 1).toLocaleString('fr-FR', { month: 'long' }),
}));

const scheduleError = (index: number, field: string): string | undefined =>
    (scheduleForm.errors as Record<string, string>)[`days.${index}.${field}`];

const saveSchedule = () => {
    scheduleForm
        .transform((data) => ({
            days: data.days.map((day) => ({
                day_of_week: day.day_of_week,
                is_working: day.is_working,
                starts_at: day.starts_at,
                ends_at: day.ends_at,
                slot_duration:
                    day.slot_duration === '' ? null : Number(day.slot_duration),
            })),
        }))
        .put('/app/appointments/schedule', { preserveScroll: true });
};

const openMonth = () => {
    openMonthForm.post('/app/appointments/open-months', {
        preserveScroll: true,
        onSuccess: () => openMonthForm.reset('note'),
    });
};

const closeMonth = (id: number) => {
    router.delete(`/app/appointments/open-months/${id}`, {
        preserveScroll: true,
    });
};

const addTimeOff = () => {
    timeOffForm.post('/app/appointments/time-off', {
        preserveScroll: true,
        onSuccess: () => timeOffForm.reset(),
    });
};

const removeTimeOff = (id: number) => {
    router.delete(`/app/appointments/time-off/${id}`, { preserveScroll: true });
};

const formatDate = (value: string): string =>
    new Date(`${value}T00:00:00`).toLocaleDateString('fr-FR', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
</script>

<template>
    <Head title="Configurer les disponibilités" />

    <div class="med-page">
        <ConfigurationTabs />

        <div class="flex items-center justify-between">
            <Heading
                title="Configuration des disponibilités"
                description="Définissez les horaires hebdomadaires, les mois ouverts aux rendez-vous et les jours d’absence."
            />
            <Button variant="outline" as-child>
                <Link href="/app/appointments">
                    <ArrowLeft class="size-4" />
                    Retour au calendrier
                </Link>
            </Button>
        </div>

        <p
            v-if="!hasDoctor"
            class="rounded-lg border border-amber-300/70 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-200"
        >
            Aucun médecin actif n’est configuré pour le cabinet. Créez son
            profil avant de définir les disponibilités.
        </p>

        <!-- Weekly working hours -->
        <section class="med-panel p-6">
            <Heading
                variant="small"
                title="Jours de travail hebdomadaires"
                description="Activez les jours travaillés et définissez les horaires et la durée des créneaux."
            />

            <form class="mt-4 space-y-3" @submit.prevent="saveSchedule">
                <div
                    v-for="(day, index) in scheduleForm.days"
                    :key="day.day_of_week"
                    class="grid grid-cols-1 gap-3 rounded-lg border border-sidebar-border/60 p-3 sm:grid-cols-[140px_1fr_1fr_1fr] sm:items-end dark:border-sidebar-border"
                >
                    <label class="flex items-center gap-2">
                        <Checkbox
                            :model-value="day.is_working"
                            @update:model-value="
                                (value) => (day.is_working = value === true)
                            "
                        />
                        <span class="text-sm font-medium text-foreground">{{
                            day.label
                        }}</span>
                    </label>

                    <div class="grid gap-1">
                        <Label
                            :for="`start-${day.day_of_week}`"
                            class="text-xs text-muted-foreground"
                            >Début</Label
                        >
                        <Input
                            :id="`start-${day.day_of_week}`"
                            v-model="day.starts_at"
                            type="time"
                            :disabled="!day.is_working"
                        />
                        <InputError
                            :message="scheduleError(index, 'starts_at')"
                        />
                    </div>

                    <div class="grid gap-1">
                        <Label
                            :for="`end-${day.day_of_week}`"
                            class="text-xs text-muted-foreground"
                            >Fin</Label
                        >
                        <Input
                            :id="`end-${day.day_of_week}`"
                            v-model="day.ends_at"
                            type="time"
                            :disabled="!day.is_working"
                        />
                        <InputError
                            :message="scheduleError(index, 'ends_at')"
                        />
                    </div>

                    <div class="grid gap-1">
                        <Label
                            :for="`slot-${day.day_of_week}`"
                            class="text-xs text-muted-foreground"
                        >
                            Créneau (min)
                        </Label>
                        <Input
                            :id="`slot-${day.day_of_week}`"
                            v-model="day.slot_duration"
                            type="number"
                            min="5"
                            max="240"
                            :placeholder="String(defaultDuration)"
                            :disabled="!day.is_working"
                        />
                        <InputError
                            :message="scheduleError(index, 'slot_duration')"
                        />
                    </div>
                </div>

                <div class="flex justify-end">
                    <Button
                        type="submit"
                        :disabled="scheduleForm.processing || !hasDoctor"
                        >Enregistrer les horaires</Button
                    >
                </div>
            </form>
        </section>

        <div class="grid gap-6 lg:grid-cols-2">
            <!-- Open months -->
            <section class="med-panel p-6">
                <Heading
                    variant="small"
                    title="Mois ouverts"
                    description="Seuls les mois ouverts acceptent de nouveaux rendez-vous."
                />

                <form
                    class="mt-4 flex flex-wrap items-end gap-3"
                    @submit.prevent="openMonth"
                >
                    <div class="grid gap-1">
                        <Label class="text-xs text-muted-foreground"
                            >Mois</Label
                        >
                        <Select
                            :model-value="String(openMonthForm.month)"
                            @update:model-value="
                                (value) => (openMonthForm.month = Number(value))
                            "
                        >
                            <SelectTrigger class="w-40">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="option in monthOptions"
                                    :key="option.value"
                                    :value="String(option.value)"
                                >
                                    {{ option.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div class="grid gap-1">
                        <Label
                            for="open-year"
                            class="text-xs text-muted-foreground"
                            >Année</Label
                        >
                        <Input
                            id="open-year"
                            v-model.number="openMonthForm.year"
                            type="number"
                            min="2000"
                            max="2100"
                            class="w-28"
                        />
                    </div>

                    <Button
                        type="submit"
                        :disabled="openMonthForm.processing || !hasDoctor"
                    >
                        <CalendarPlus class="size-4" />
                        Ouvrir
                    </Button>
                </form>
                <InputError
                    :message="openMonthForm.errors.month"
                    class="mt-1"
                />
                <InputError :message="openMonthForm.errors.year" />

                <ul class="mt-4 space-y-2">
                    <li
                        v-if="openMonths.length === 0"
                        class="rounded-md bg-muted/40 px-3 py-2 text-sm text-muted-foreground"
                    >
                        Aucun mois n’est ouvert pour le moment.
                    </li>
                    <li
                        v-for="month in openMonths"
                        :key="month.id"
                        class="flex items-center justify-between rounded-md border border-sidebar-border/70 px-3 py-2 text-sm dark:border-sidebar-border"
                    >
                        <span class="font-medium text-foreground">{{
                            month.label
                        }}</span>
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            aria-label="Fermer le mois"
                            @click="closeMonth(month.id)"
                        >
                            <Trash2 class="size-4 text-destructive" />
                        </Button>
                    </li>
                </ul>
            </section>

            <!-- Days off -->
            <section class="med-panel p-6">
                <Heading
                    variant="small"
                    title="Congés et jours fériés"
                    description="Bloquez des journées complètes, par exemple l’Aïd ou un jour férié."
                />

                <form class="mt-4 space-y-3" @submit.prevent="addTimeOff">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="grid gap-1">
                            <Label
                                for="off-start"
                                class="text-xs text-muted-foreground"
                                >Du</Label
                            >
                            <Input
                                id="off-start"
                                v-model="timeOffForm.starts_at"
                                type="date"
                            />
                            <InputError
                                :message="timeOffForm.errors.starts_at"
                            />
                        </div>
                        <div class="grid gap-1">
                            <Label
                                for="off-end"
                                class="text-xs text-muted-foreground"
                                >Au</Label
                            >
                            <Input
                                id="off-end"
                                v-model="timeOffForm.ends_at"
                                type="date"
                            />
                            <InputError :message="timeOffForm.errors.ends_at" />
                        </div>
                    </div>
                    <div class="grid gap-1">
                        <Label
                            for="off-reason"
                            class="text-xs text-muted-foreground"
                            >Motif (facultatif)</Label
                        >
                        <Input
                            id="off-reason"
                            v-model="timeOffForm.reason"
                            placeholder="Ex. Aïd el-Fitr"
                        />
                        <InputError :message="timeOffForm.errors.reason" />
                    </div>
                    <div class="flex justify-end">
                        <Button
                            type="submit"
                            :disabled="timeOffForm.processing || !hasDoctor"
                            >Ajouter l’absence</Button
                        >
                    </div>
                </form>

                <ul class="mt-4 space-y-2">
                    <li
                        v-if="timeOff.length === 0"
                        class="rounded-md bg-muted/40 px-3 py-2 text-sm text-muted-foreground"
                    >
                        Aucune absence enregistrée.
                    </li>
                    <li
                        v-for="entry in timeOff"
                        :key="entry.id"
                        class="flex items-center justify-between rounded-md border border-sidebar-border/70 px-3 py-2 text-sm dark:border-sidebar-border"
                    >
                        <span>
                            <span class="font-medium text-foreground">
                                {{ formatDate(entry.starts_at) }}
                                <template
                                    v-if="entry.ends_at !== entry.starts_at"
                                >
                                    – {{ formatDate(entry.ends_at) }}</template
                                >
                            </span>
                            <span
                                v-if="entry.reason"
                                class="ml-2 text-muted-foreground"
                                >{{ entry.reason }}</span
                            >
                        </span>
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            aria-label="Supprimer l’absence"
                            @click="removeTimeOff(entry.id)"
                        >
                            <Trash2 class="size-4 text-destructive" />
                        </Button>
                    </li>
                </ul>
            </section>
        </div>
    </div>
</template>
