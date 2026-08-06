<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowUp,
    ArrowUpDown,
    CalendarDays,
    CheckCircle2,
    Clock,
    Eye,
    Play,
    Search,
    Stethoscope,
    Users,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Consultations', href: '/app/consultations' }],
    },
});

type TodayAppointment = {
    id: number;
    time: string | null;
    patient_name: string | null;
    patient_number: string | null;
    status: string;
    reason: string | null;
    consultation_id: number | null;
    consultation_status: string | null;
};

const props = defineProps<{
    date: string;
    appointments: TodayAppointment[];
    canStart: boolean;
}>();

type SortKey = 'time' | 'patient' | 'reason' | 'status';

const search = ref('');
const statusFilter = ref('all');
const sortKey = ref<SortKey>('time');
const sortDirection = ref<'asc' | 'desc'>('asc');

const statusStyles: Record<string, string> = {
    scheduled:
        'bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300',
    confirmed:
        'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300',
    checked_in:
        'bg-indigo-50 text-indigo-700 dark:bg-indigo-950/40 dark:text-indigo-300',
    in_progress:
        'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300',
    completed:
        'bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-300',
    no_show: 'bg-red-50 text-red-700 dark:bg-red-950/40 dark:text-red-300',
};

const statusLabels: Record<string, string> = {
    scheduled: 'Planifié',
    confirmed: 'Confirmé',
    checked_in: 'Arrivé',
    in_progress: 'En cours',
    completed: 'Terminé',
    cancelled: 'Annulé',
    no_show: 'Absent',
};

const statusLabel = (status: string): string =>
    statusLabels[status] ?? status.replace(/_/g, ' ');

const statusOptions = computed(() => [
    { value: 'all', label: 'Tous les statuts' },
    ...Array.from(
        new Set(props.appointments.map((appointment) => appointment.status)),
    ).map((value) => ({ value, label: statusLabel(value) })),
]);

const normalized = (value: string | null | undefined): string =>
    (value ?? '').trim().toLocaleLowerCase();

const filteredAppointments = computed(() => {
    const query = normalized(search.value);

    return props.appointments
        .filter((appointment) => {
            const matchesStatus =
                statusFilter.value === 'all' ||
                appointment.status === statusFilter.value;
            const searchable = [
                appointment.patient_name,
                appointment.patient_number,
                appointment.reason,
                statusLabel(appointment.status),
            ]
                .map(normalized)
                .join(' ');

            return (
                matchesStatus && (query === '' || searchable.includes(query))
            );
        })
        .sort((first, second) => {
            const firstValue =
                sortKey.value === 'time'
                    ? (first.time ?? '')
                    : sortKey.value === 'patient'
                      ? (first.patient_name ?? '')
                      : sortKey.value === 'reason'
                        ? (first.reason ?? '')
                        : statusLabel(first.status);
            const secondValue =
                sortKey.value === 'time'
                    ? (second.time ?? '')
                    : sortKey.value === 'patient'
                      ? (second.patient_name ?? '')
                      : sortKey.value === 'reason'
                        ? (second.reason ?? '')
                        : statusLabel(second.status);
            const result = firstValue.localeCompare(secondValue, undefined, {
                numeric: true,
                sensitivity: 'base',
            });

            return sortDirection.value === 'asc' ? result : -result;
        });
});

const completedCount = computed(
    () =>
        props.appointments.filter(
            (appointment) =>
                appointment.consultation_status === 'completed' ||
                appointment.status === 'completed',
        ).length,
);

const pendingCount = computed(
    () => props.appointments.length - completedCount.value,
);

const sortBy = (key: SortKey) => {
    if (sortKey.value === key) {
        sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc';

        return;
    }

    sortKey.value = key;
    sortDirection.value = 'asc';
};

const resetFilters = () => {
    search.value = '';
    statusFilter.value = 'all';
    sortKey.value = 'time';
    sortDirection.value = 'asc';
};

const formatDate = (value: string): string =>
    new Date(`${value}T00:00:00`).toLocaleDateString('fr-FR', {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });

const start = (appointmentId: number) => {
    router.post(
        `/app/consultations/${appointmentId}/start`,
        {},
        { preserveScroll: true },
    );
};

const isCompleted = (appointment: TodayAppointment): boolean =>
    appointment.consultation_status === 'completed' ||
    appointment.status === 'completed';
</script>

<template>
    <Head title="Consultations du jour" />

    <div class="med-page">
        <section class="med-panel overflow-hidden">
            <div
                class="border-b border-sidebar-border/70 p-6 dark:border-sidebar-border"
            >
                <div
                    class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between"
                >
                    <Heading
                        title="Patients du jour"
                        :description="`Rendez-vous du ${formatDate(date)}`"
                    />
                    <div
                        class="flex items-center gap-2 text-sm text-muted-foreground"
                    >
                        <CalendarDays class="size-4" />
                        <span>{{ formatDate(date) }}</span>
                    </div>
                </div>

                <div class="mt-6 grid gap-3 sm:grid-cols-3">
                    <div
                        class="rounded-xl border border-sidebar-border/70 bg-muted/20 p-4 dark:border-sidebar-border"
                    >
                        <div
                            class="flex items-center justify-between text-muted-foreground"
                        >
                            <span
                                class="text-xs font-medium tracking-wide uppercase"
                                >Total des rendez-vous</span
                            >
                            <Users class="size-4" />
                        </div>
                        <p class="mt-2 text-2xl font-semibold">
                            {{ appointments.length }}
                        </p>
                    </div>
                    <div
                        class="rounded-xl border border-sidebar-border/70 bg-muted/20 p-4 dark:border-sidebar-border"
                    >
                        <div
                            class="flex items-center justify-between text-muted-foreground"
                        >
                            <span
                                class="text-xs font-medium tracking-wide uppercase"
                                >À examiner</span
                            >
                            <Stethoscope class="size-4" />
                        </div>
                        <p class="mt-2 text-2xl font-semibold">
                            {{ pendingCount }}
                        </p>
                    </div>
                    <div
                        class="rounded-xl border border-sidebar-border/70 bg-muted/20 p-4 dark:border-sidebar-border"
                    >
                        <div
                            class="flex items-center justify-between text-muted-foreground"
                        >
                            <span
                                class="text-xs font-medium tracking-wide uppercase"
                                >Terminées</span
                            >
                            <CheckCircle2 class="size-4 text-emerald-600" />
                        </div>
                        <p class="mt-2 text-2xl font-semibold">
                            {{ completedCount }}
                        </p>
                    </div>
                </div>
            </div>

            <div class="space-y-4 p-6">
                <div
                    class="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between"
                >
                    <div class="relative min-w-0 flex-1 xl:max-w-md">
                        <Search
                            class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                        />
                        <Input
                            v-model="search"
                            class="h-10 pl-9"
                            placeholder="Rechercher un patient, un motif ou un statut…"
                        />
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <select
                            v-model="statusFilter"
                            class="med-native-control"
                            aria-label="Filtrer par statut"
                        >
                            <option
                                v-for="option in statusOptions"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </select>
                        <Button
                            variant="outline"
                            size="sm"
                            @click="resetFilters"
                        >
                            Réinitialiser
                        </Button>
                    </div>
                </div>

                <div
                    class="flex items-center justify-between text-sm text-muted-foreground"
                >
                    <span
                        >{{ filteredAppointments.length }} sur
                        {{ appointments.length }} rendez-vous</span
                    >
                    <span class="hidden sm:inline"
                        >Cliquez sur une colonne pour trier</span
                    >
                </div>

                <div class="med-table-wrap">
                    <table class="med-table">
                        <thead
                            class="bg-muted/40 text-left text-xs tracking-wide text-muted-foreground uppercase"
                        >
                            <tr>
                                <th class="px-4 py-3 font-medium">
                                    <button
                                        class="flex items-center gap-2"
                                        @click="sortBy('time')"
                                    >
                                        Heure
                                        <ArrowUp
                                            v-if="
                                                sortKey === 'time' &&
                                                sortDirection === 'asc'
                                            "
                                            class="size-3.5"
                                        />
                                        <ArrowDown
                                            v-else-if="sortKey === 'time'"
                                            class="size-3.5"
                                        />
                                        <ArrowUpDown
                                            v-else
                                            class="size-3.5 opacity-50"
                                        />
                                    </button>
                                </th>
                                <th class="px-4 py-3 font-medium">
                                    <button
                                        class="flex items-center gap-2"
                                        @click="sortBy('patient')"
                                    >
                                        Patient
                                        <ArrowUp
                                            v-if="
                                                sortKey === 'patient' &&
                                                sortDirection === 'asc'
                                            "
                                            class="size-3.5"
                                        />
                                        <ArrowDown
                                            v-else-if="sortKey === 'patient'"
                                            class="size-3.5"
                                        />
                                        <ArrowUpDown
                                            v-else
                                            class="size-3.5 opacity-50"
                                        />
                                    </button>
                                </th>
                                <th class="px-4 py-3 font-medium">
                                    <button
                                        class="flex items-center gap-2"
                                        @click="sortBy('reason')"
                                    >
                                        Motif
                                        <ArrowUp
                                            v-if="
                                                sortKey === 'reason' &&
                                                sortDirection === 'asc'
                                            "
                                            class="size-3.5"
                                        />
                                        <ArrowDown
                                            v-else-if="sortKey === 'reason'"
                                            class="size-3.5"
                                        />
                                        <ArrowUpDown
                                            v-else
                                            class="size-3.5 opacity-50"
                                        />
                                    </button>
                                </th>
                                <th class="px-4 py-3 font-medium">
                                    <button
                                        class="flex items-center gap-2"
                                        @click="sortBy('status')"
                                    >
                                        Statut
                                        <ArrowUp
                                            v-if="
                                                sortKey === 'status' &&
                                                sortDirection === 'asc'
                                            "
                                            class="size-3.5"
                                        />
                                        <ArrowDown
                                            v-else-if="sortKey === 'status'"
                                            class="size-3.5"
                                        />
                                        <ArrowUpDown
                                            v-else
                                            class="size-3.5 opacity-50"
                                        />
                                    </button>
                                </th>
                                <th class="px-4 py-3 text-right font-medium">
                                    Action
                                </th>
                            </tr>
                        </thead>
                        <tbody
                            class="divide-y divide-sidebar-border/70 dark:divide-sidebar-border"
                        >
                            <tr v-if="filteredAppointments.length === 0">
                                <td
                                    class="px-4 py-8 text-center text-muted-foreground"
                                    colspan="5"
                                >
                                    Aucun rendez-vous ne correspond à vos
                                    filtres.
                                </td>
                            </tr>
                            <tr
                                v-for="appointment in filteredAppointments"
                                :key="appointment.id"
                                class="bg-background transition-colors hover:bg-muted/30"
                            >
                                <td
                                    class="px-4 py-3 font-medium text-foreground"
                                >
                                    <span class="flex items-center gap-1.5">
                                        <Clock
                                            class="size-3.5 text-muted-foreground"
                                        />
                                        {{ appointment.time }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="font-medium text-foreground">{{
                                        appointment.patient_name
                                    }}</span>
                                    <span
                                        class="ml-2 font-mono text-xs text-muted-foreground"
                                    >
                                        {{ appointment.patient_number }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">
                                    {{ appointment.reason ?? '—' }}
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                                        :class="
                                            statusStyles[appointment.status] ??
                                            'bg-muted text-muted-foreground'
                                        "
                                    >
                                        {{ statusLabel(appointment.status) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex justify-end">
                                        <Button
                                            v-if="
                                                appointment.consultation_id &&
                                                isCompleted(appointment)
                                            "
                                            size="sm"
                                            variant="outline"
                                            as-child
                                        >
                                            <Link
                                                :href="`/app/consultations/${appointment.consultation_id}`"
                                            >
                                                <Eye class="size-4" />
                                                Voir
                                            </Link>
                                        </Button>
                                        <Button
                                            v-else-if="
                                                appointment.consultation_id
                                            "
                                            size="sm"
                                            variant="secondary"
                                            as-child
                                        >
                                            <Link
                                                :href="`/app/consultations/${appointment.consultation_id}`"
                                            >
                                                <Stethoscope class="size-4" />
                                                Continuer
                                            </Link>
                                        </Button>
                                        <Button
                                            v-else-if="canStart"
                                            size="sm"
                                            @click="start(appointment.id)"
                                        >
                                            <Play class="size-4" />
                                            Commencer
                                        </Button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>
</template>
