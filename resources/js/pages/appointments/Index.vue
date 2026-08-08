<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import type { DateValue } from '@internationalized/date';
import {
    DateFormatter,
    getLocalTimeZone,
    parseDate,
} from '@internationalized/date';
import {
    Ban,
    CalendarDays,
    Check,
    ChevronsUpDown,
    Clock,
    Eye,
    MoreVertical,
    Pencil,
    Play,
    Printer,
    Plus,
    RefreshCw,
    Search,
    Stethoscope,
    UserCheck,
    X,
} from '@lucide/vue';
import { computed, ref, shallowRef, watch } from 'vue';
import { toast } from 'vue-sonner';
import AvailabilityCalendar from '@/components/appointments/AvailabilityCalendar.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PatientForm from '@/components/patients/PatientForm.vue';
import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogScrollContent,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { getJson, isValidationError, postJson, putJson } from '@/lib/http';
import type {
    AppointmentFilters,
    AppointmentListItem,
    AppointmentPatientOption,
    AppointmentStats,
    AppointmentStatusOption,
    DayAvailability,
    AppointmentPrestationOption,
    MonthOverview,
    PatientDetail,
    PatientOption,
    Paginator,
    Slot,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Rendez-vous', href: '/app/appointments' }],
    },
});

const props = defineProps<{
    month: MonthOverview;
    appointments: Paginator<AppointmentListItem>;
    stats: AppointmentStats;
    statusOptions: AppointmentStatusOption[];
    filters: AppointmentFilters;
    hasDoctor: boolean;
    patients: AppointmentPatientOption[];
    prestations: AppointmentPrestationOption[];
    genders: PatientOption[];
    bloodGroups: PatientOption[];
    permissions: {
        book: boolean;
        confirm: boolean;
        checkIn: boolean;
        cancel: boolean;
        configure: boolean;
        manageActs: boolean;
        startConsultation: boolean;
    };
    today: string;
}>();

usePage();

// ----- Filters -----
const dateFormatter = new DateFormatter('fr-FR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
});

const parseFilterDate = (value: string | null): DateValue | undefined => {
    if (!value) {
        return undefined;
    }

    try {
        return parseDate(value);
    } catch {
        return undefined;
    }
};

const dateValue = shallowRef<DateValue | undefined>(
    parseFilterDate(props.filters.date),
);
const datePickerOpen = ref(false);
const search = ref(props.filters.search ?? '');
const statusFilter = ref(props.filters.status ?? 'all');
const perPage = ref(String(props.filters.per_page ?? 10));

let searchTimer: ReturnType<typeof setTimeout> | undefined;

const applyFilters = (
    overrides: Partial<{
        date: string;
        search: string;
        status: string;
        per_page: number;
    }>,
) => {
    router.get(
        '/app/appointments',
        {
            date: props.filters.date,
            search: props.filters.search,
            status: props.filters.status ?? 'all',
            per_page: props.filters.per_page,
            ...overrides,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};

watch(
    () => props.filters.date,
    (value) => {
        dateValue.value = parseFilterDate(value);
    },
);

watch(
    () => props.filters.search,
    (value) => {
        if ((value ?? '') !== search.value) {
            search.value = value ?? '';
        }
    },
);

watch(
    () => props.filters.status,
    (value) => {
        statusFilter.value = value ?? 'all';
    },
);

watch(
    () => props.filters.per_page,
    (value) => {
        perPage.value = String(value ?? 10);
    },
);

watch(search, (value) => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => applyFilters({ search: value.trim() }), 350);
});

const isFilterToday = computed(() => props.filters.date === props.today);

const dateButtonLabel = computed(() =>
    dateValue.value
        ? dateFormatter.format(dateValue.value.toDate(getLocalTimeZone()))
        : 'Choisir une date',
);

const onDateSelect = (value: DateValue | DateValue[] | undefined) => {
    if (!value || Array.isArray(value)) {
        return;
    }

    datePickerOpen.value = false;
    applyFilters({ date: value.toString() });
};

const onStatusChange = (value: unknown) => {
    const next = typeof value === 'string' ? value : 'all';
    statusFilter.value = next;
    applyFilters({ status: next });
};

const onPerPageChange = (value: unknown) => {
    const next = typeof value === 'string' ? value : '10';
    perPage.value = next;
    applyFilters({ per_page: Number(next) });
};

// ----- Status presentation -----
const statusStyles: Record<string, string> = {
    scheduled:
        'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300',
    confirmed:
        'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300',
    checked_in: 'bg-sky-50 text-sky-700 dark:bg-sky-950/40 dark:text-sky-300',
    in_progress: 'bg-sky-50 text-sky-700 dark:bg-sky-950/40 dark:text-sky-300',
    completed:
        'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
    cancelled:
        'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
    no_show:
        'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
};

const statusLabels: Record<string, string> = {
    scheduled: 'Non confirmé',
    confirmed: 'Confirmé',
    checked_in: 'Arrivé',
    in_progress: 'En cours',
    completed: 'Traité',
    cancelled: 'Annulé',
    no_show: 'Absent',
};

const statusLabel = (status: string): string =>
    statusLabels[status] ??
    status.replace(/_/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase());

const statChips = computed(() => [
    {
        key: 'all',
        label: 'Tous',
        value: props.stats.total ?? 0,
    },
    { key: 'confirmed', label: 'Confirmés', value: props.stats.confirmed ?? 0 },
    { key: 'cancelled', label: 'Annulés', value: props.stats.cancelled ?? 0 },
    {
        key: 'scheduled',
        label: 'Non confirmés',
        value: props.stats.scheduled ?? 0,
    },
    { key: 'completed', label: 'Traités', value: props.stats.completed ?? 0 },
]);

const avatarStyles = [
    'bg-[#e8aa1b] text-white',
    'bg-[#f3ba2f] text-white',
    'bg-[#d89416] text-white',
    'bg-[#efb63b] text-white',
];

const patientInitials = (name: string | null): string =>
    (name ?? '?')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('') || '?';

const waitingAppointments = computed(() =>
    props.appointments.data.filter(
        (appointment) =>
            !['cancelled', 'completed', 'no_show'].includes(appointment.status),
    ),
);

const progressTotal = computed(() =>
    Math.max(0, (props.stats.total ?? 0) - (props.stats.cancelled ?? 0)),
);
const progressCompleted = computed(() => props.stats.completed ?? 0);
const progressPercent = computed(() =>
    progressTotal.value === 0
        ? 0
        : Math.min(100, (progressCompleted.value / progressTotal.value) * 100),
);

// ----- Row actions -----
const confirmAppointment = (appointment: AppointmentListItem) => {
    router.patch(
        `/app/appointments/${appointment.id}/confirm`,
        {},
        { preserveScroll: true, preserveState: true },
    );
};

const checkInAppointment = (appointment: AppointmentListItem) => {
    router.patch(
        `/app/appointments/${appointment.id}/check-in`,
        {},
        { preserveScroll: true, preserveState: true },
    );
};

// Requirement #2: start (or resume) the consultation for an appointment.
// Posts to the existing consultations.start endpoint which creates/opens the
// Consultation for the patient and redirects into the workspace.
const startConsultation = (appointment: AppointmentListItem) => {
    if (appointment.consultation_id) {
        router.visit(`/app/consultations/${appointment.consultation_id}`);

        return;
    }

    router.post(
        `/app/consultations/${appointment.id}/start`,
        {},
        { preserveScroll: true },
    );
};

const consultationIsCompleted = (appointment: AppointmentListItem): boolean =>
    appointment.consultation_status === 'completed' ||
    appointment.status === 'completed';

const cancelTarget = ref<AppointmentListItem | null>(null);
const cancelForm = useForm<{ reason: string }>({ reason: '' });

const openCancel = (appointment: AppointmentListItem) => {
    cancelTarget.value = appointment;
    cancelForm.reset();
    cancelForm.clearErrors();
};

const submitCancel = () => {
    if (!cancelTarget.value) {
        return;
    }

    cancelForm.patch(`/app/appointments/${cancelTarget.value.id}/cancel`, {
        preserveScroll: true,
        onSuccess: () => {
            cancelTarget.value = null;
        },
    });
};

// ----- Booking modal -----
const showBooking = ref(false);
const currentMonth = ref<MonthOverview>(props.month);
const selectedDate = ref<string | null>(null);
const dayData = ref<DayAvailability | null>(null);
const loadingMonth = ref(false);
const loadingDay = ref(false);
const activeSlot = ref<Slot | null>(null);
const patientSearch = ref('');
const patientOptions = ref<AppointmentPatientOption[]>([...props.patients]);
const patientFormOpen = ref(false);
const patientFormMode = ref<'create' | 'edit'>('create');
const patientFormPatient = ref<PatientDetail | null>(null);
const patientFormLoading = ref(false);
const patientFormKey = ref(0);
const availablePrestations = ref<AppointmentPrestationOption[]>([
    ...props.prestations,
]);
const prestationSearch = ref('');
const prestationOpen = ref(false);
const prestationEditorMode = ref<'create' | 'edit' | null>(null);
const prestationEditingOption = ref<AppointmentPrestationOption | null>(null);
const prestationEditorValue = ref('');
const prestationEditorError = ref('');
const prestationProcessing = ref(false);

const form = useForm<{
    patient_id: number | '';
    starts_at: string;
    reason: string;
    prestation: string;
}>({
    patient_id: '',
    starts_at: '',
    reason: '',
    prestation: '',
});

watch(
    () => props.month,
    (month) => {
        currentMonth.value = month;
    },
);

watch(
    () => props.patients,
    (patients) => {
        patientOptions.value = [...patients];
    },
);

watch(
    () => props.prestations,
    (prestations) => {
        availablePrestations.value = [...prestations];
    },
);

const fetchJson = async <T,>(url: string): Promise<T> => {
    const response = await fetch(url, {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
    });

    if (!response.ok) {
        throw new Error(`Request failed with status ${response.status}`);
    }

    return (await response.json()) as T;
};

const loadMonth = async (year: number, month: number) => {
    loadingMonth.value = true;

    try {
        currentMonth.value = await fetchJson<MonthOverview>(
            `/app/appointments/availability/month?year=${year}&month=${month}`,
        );
    } finally {
        loadingMonth.value = false;
    }

    selectedDate.value = null;
    dayData.value = null;
    activeSlot.value = null;
};

const loadDay = async (date: string) => {
    selectedDate.value = date;
    activeSlot.value = null;
    loadingDay.value = true;

    try {
        prestationSearch.value = '';
        cancelPrestationEditor();
        dayData.value = await fetchJson<DayAvailability>(
            `/app/appointments/availability/day?date=${date}`,
        );
    } finally {
        loadingDay.value = false;
    }
};

const openBooking = () => {
    form.reset();
    form.clearErrors();
    patientSearch.value = '';
    activeSlot.value = null;
    currentMonth.value = props.month;
    selectedDate.value = null;
    dayData.value = null;
    showBooking.value = true;

    const todayDay = props.month.days.find((day) => day.date === props.today);

    if (
        todayDay &&
        todayDay.is_open_month &&
        todayDay.is_working_day &&
        !todayDay.is_day_off &&
        !todayDay.is_past
    ) {
        void loadDay(props.today);
    }
};

const selectedDateLabel = computed(() =>
    selectedDate.value
        ? new Date(`${selectedDate.value}T00:00:00`).toLocaleDateString(
              'fr-FR',
              {
                  weekday: 'long',
                  year: 'numeric',
                  month: 'long',
                  day: 'numeric',
              },
          )
        : '',
);

const closedReasonLabel = computed(() => {
    switch (dayData.value?.reason) {
        case 'month_closed':
            return 'Ce mois n’est pas encore ouvert aux rendez-vous.';
        case 'not_working_day':
            return 'Le médecin ne travaille pas ce jour-là.';
        case 'day_off':
            return 'Cette journée est déclarée comme congé ou jour férié.';
        case 'no_doctor':
            return 'Aucun médecin actif n’est configuré pour le cabinet.';
        default:
            return 'Aucun créneau n’est disponible pour cette journée.';
    }
});

const filteredPrestations = computed(() => {
    const query = prestationSearch.value.trim().toLowerCase();

    if (!query) {
        return availablePrestations.value;
    }

    return availablePrestations.value.filter((prestation) =>
        [prestation.label, prestation.category ?? '']
            .join(' ')
            .toLowerCase()
            .includes(query),
    );
});

const selectedPrestation = computed(
    () =>
        availablePrestations.value.find(
            (prestation) => prestation.label === form.prestation,
        ) ?? null,
);

const formatPrestationAmount = (amount: number | null): string =>
    amount === null ? '' : String(amount) + ' DA';

const openCreatePatient = () => {
    patientFormMode.value = 'create';
    patientFormPatient.value = null;
    patientFormKey.value += 1;
    patientFormOpen.value = true;
};

const openEditPatient = async () => {
    if (!selectedPatient.value) {
        return;
    }

    patientFormLoading.value = true;
    patientFormMode.value = 'edit';
    patientFormPatient.value = null;
    patientFormOpen.value = true;

    try {
        const response = await getJson<{ patient: PatientDetail }>(
            '/app/patients/' + selectedPatient.value.id + '/json',
        );
        patientFormPatient.value = response.patient;
        patientFormKey.value += 1;
    } catch {
        patientFormOpen.value = false;
        toast.error('Impossible de charger ce patient. Veuillez réessayer.');
    } finally {
        patientFormLoading.value = false;
    }
};

const handlePatientSaved = (patient?: AppointmentPatientOption) => {
    if (!patient) {
        return;
    }

    const option = {
        id: patient.id,
        full_name: patient.full_name,
        patient_number: patient.patient_number,
    };
    const index = patientOptions.value.findIndex(
        (current) => current.id === option.id,
    );

    if (index === -1) {
        patientOptions.value = [option, ...patientOptions.value];
    } else {
        patientOptions.value.splice(index, 1, option);
    }

    choosePatient(option);
    patientFormOpen.value = false;
    toast.success(
        patientFormMode.value === 'create'
            ? 'Patient créé.'
            : 'Patient mis à jour.',
    );
};

const startPrestationCreate = () => {
    prestationEditorMode.value = 'create';
    prestationEditingOption.value = null;
    prestationEditorValue.value = '';
    prestationEditorError.value = '';
};

const startPrestationEdit = (prestation: AppointmentPrestationOption) => {
    prestationEditorMode.value = 'edit';
    prestationEditingOption.value = prestation;
    prestationEditorValue.value = prestation.label;
    prestationEditorError.value = '';
};

const cancelPrestationEditor = () => {
    prestationEditorMode.value = null;
    prestationEditingOption.value = null;
    prestationEditorValue.value = '';
    prestationEditorError.value = '';
};

const selectPrestation = (prestation: AppointmentPrestationOption) => {
    form.prestation = prestation.label;
    prestationSearch.value = '';
    prestationOpen.value = false;
    cancelPrestationEditor();
};

const hasSlots = computed(() => (dayData.value?.slots.length ?? 0) > 0);

const filteredPatients = computed(() => {
    const query = patientSearch.value.trim().toLowerCase();

    const matches = query
        ? patientOptions.value.filter(
              (patient) =>
                  patient.full_name.toLowerCase().includes(query) ||
                  patient.patient_number.toLowerCase().includes(query),
          )
        : patientOptions.value;

    return matches.slice(0, 50);
});

const selectedPatient = computed(
    () =>
        patientOptions.value.find(
            (patient) => patient.id === form.patient_id,
        ) ?? null,
);

const savePrestation = async () => {
    const name = prestationEditorValue.value.trim();

    if (!name) {
        prestationEditorError.value = 'Saisissez le nom de l’acte.';

        return;
    }

    prestationProcessing.value = true;
    prestationEditorError.value = '';

    try {
        const response = prestationEditingOption.value
            ? await putJson<{ prestation: AppointmentPrestationOption }>(
                  '/app/appointments/prestations/' +
                      prestationEditingOption.value.source +
                      '/' +
                      prestationEditingOption.value.id,
                  { name },
              )
            : await postJson<{ prestation: AppointmentPrestationOption }>(
                  '/app/appointments/prestations',
                  { name },
              );
        const saved = response.prestation;

        if (prestationEditingOption.value) {
            availablePrestations.value = availablePrestations.value.map(
                (current) =>
                    current.id === prestationEditingOption.value?.id &&
                    current.source === prestationEditingOption.value.source
                        ? saved
                        : current,
            );
        } else {
            availablePrestations.value = [saved, ...availablePrestations.value];
        }

        form.prestation = saved.label;
        prestationSearch.value = '';
        cancelPrestationEditor();
        toast.success(
            prestationEditingOption.value ? 'Acte mis à jour.' : 'Acte ajouté.',
        );
    } catch (error) {
        if (isValidationError(error)) {
            prestationEditorError.value =
                error.errors.name?.[0] ?? 'Saisissez un nom d’acte valide.';
        } else {
            toast.error(
                'Impossible d’enregistrer cet acte. Veuillez réessayer.',
            );
        }
    } finally {
        prestationProcessing.value = false;
    }
};

const slotButtonClasses = (slot: Slot): string => {
    const base =
        'flex flex-col items-center rounded-md border px-1.5 py-1 text-xs transition';

    if (activeSlot.value?.starts_at === slot.starts_at) {
        return `${base} cursor-pointer border-primary bg-primary text-primary-foreground`;
    }

    if (slot.available) {
        return `${base} cursor-pointer border-emerald-300/70 bg-emerald-50 text-emerald-900 hover:bg-emerald-100 dark:border-emerald-900/50 dark:bg-emerald-950/30 dark:text-emerald-200 dark:hover:bg-emerald-950/50`;
    }

    if (slot.reason === 'booked') {
        return `${base} cursor-not-allowed border-amber-300/70 bg-amber-50 text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-200`;
    }

    return `${base} cursor-not-allowed border-sidebar-border/60 bg-muted/30 text-muted-foreground`;
};

const selectSlot = (slot: Slot) => {
    if (!slot.available || !props.permissions.book) {
        return;
    }

    activeSlot.value = slot;
    form.starts_at = slot.starts_at;
};

const choosePatient = (patient: AppointmentPatientOption) => {
    form.patient_id = patient.id;
    patientSearch.value = '';
};

const clearPatient = () => {
    form.patient_id = '';
    patientSearch.value = '';
};

const canConfirm = computed(
    () =>
        form.patient_id !== '' && activeSlot.value !== null && !form.processing,
);

const submitBooking = () => {
    if (!canConfirm.value) {
        return;
    }

    form.post('/app/appointments', {
        preserveScroll: true,
        onSuccess: () => {
            showBooking.value = false;
        },
    });
};

const printAppointments = () => {
    const query = new URLSearchParams();

    if (props.filters.date) {
        query.set('date', props.filters.date);
    }

    if (props.filters.search) {
        query.set('search', props.filters.search);
    }

    if (props.filters.status) {
        query.set('status', props.filters.status);
    }

    const suffix = query.size > 0 ? `?${query.toString()}` : '';
    window.open(
        `/app/appointments/print${suffix}`,
        '_blank',
        'noopener,noreferrer',
    );
};
</script>

<template>
    <Head title="Rendez-vous" />

    <div
        class="min-h-full w-full min-w-0 flex-1 bg-[#edf3f6] px-4 py-5 text-slate-900 lg:px-7 lg:py-6"
    >
        <section class="mx-auto max-w-[1800px]">
            <div
                class="flex flex-col gap-5 xl:flex-row xl:items-start xl:justify-between"
            >
                <div>
                    <h1
                        class="text-[2rem] leading-none font-bold tracking-tight text-[#111827] sm:text-[2.2rem]"
                    >
                        Rendez-vous
                    </h1>
                    <div class="mt-3 h-1 w-20 rounded-full bg-[#e2a719]" />
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <div class="relative min-w-[220px] flex-1 sm:flex-none">
                        <Search
                            class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400"
                        />
                        <Input
                            v-model="search"
                            type="search"
                            class="h-10 rounded-xl border-white/80 bg-white pl-9 text-sm shadow-sm placeholder:text-slate-400 focus-visible:ring-[#4c82b7]"
                            placeholder="Rechercher..."
                            aria-label="Rechercher un rendez-vous"
                        />
                    </div>

                    <Select
                        :model-value="statusFilter"
                        @update:model-value="onStatusChange"
                    >
                        <SelectTrigger
                            class="h-10 w-[136px] rounded-xl border-white/80 bg-white shadow-sm focus:ring-[#4c82b7]"
                        >
                            <SelectValue placeholder="Tous" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">Tous</SelectItem>
                            <SelectItem
                                v-for="option in statusOptions"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ statusLabel(option.value) }}
                            </SelectItem>
                        </SelectContent>
                    </Select>

                    <Popover v-model:open="datePickerOpen">
                        <PopoverTrigger as-child>
                            <Button
                                variant="outline"
                                class="h-10 rounded-xl border-white/80 bg-white px-3 font-normal shadow-sm hover:bg-white"
                            >
                                <span>{{ dateButtonLabel }}</span>
                                <CalendarDays
                                    class="ml-1 size-4 text-slate-500"
                                />
                            </Button>
                        </PopoverTrigger>
                        <PopoverContent class="w-auto p-0" align="end">
                            <Calendar
                                :model-value="dateValue"
                                initial-focus
                                @update:model-value="onDateSelect"
                            />
                        </PopoverContent>
                    </Popover>

                    <Button
                        variant="outline"
                        size="icon"
                        class="h-10 w-10 rounded-xl border-white/80 bg-white text-slate-600 shadow-sm hover:bg-white hover:text-[#3e739f]"
                        title="Actualiser"
                        aria-label="Actualiser les rendez-vous"
                        @click="applyFilters({})"
                    >
                        <RefreshCw class="size-4" />
                    </Button>
                    <Button
                        variant="outline"
                        size="icon"
                        class="h-10 w-10 rounded-xl border-white/80 bg-white text-slate-600 shadow-sm hover:bg-white hover:text-[#3e739f]"
                        title="Imprimer"
                        aria-label="Imprimer les rendez-vous"
                        @click="printAppointments"
                    >
                        <Printer class="size-4" />
                    </Button>
                </div>
            </div>

            <div
                class="mt-7 grid gap-5 xl:grid-cols-[minmax(0,1.72fr)_minmax(320px,0.82fr)]"
            >
                <div class="min-w-0">
                    <div
                        class="flex min-h-12 flex-wrap items-center rounded-full bg-[#4c82b7] p-1 shadow-sm"
                        role="tablist"
                        aria-label="Filtrer les rendez-vous par statut"
                    >
                        <button
                            v-for="chip in statChips"
                            :key="chip.key"
                            type="button"
                            class="flex min-w-[104px] flex-1 items-center justify-center gap-2 rounded-full px-3 py-2 text-sm font-semibold transition focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none"
                            :class="
                                statusFilter === chip.key
                                    ? 'bg-white text-[#1d507c] shadow-sm'
                                    : 'text-white/90 hover:bg-white/10 hover:text-white'
                            "
                            role="tab"
                            :aria-selected="statusFilter === chip.key"
                            @click="onStatusChange(chip.key)"
                        >
                            <span>{{ chip.label }}</span>
                            <span
                                class="inline-flex min-w-6 items-center justify-center rounded-full bg-[#e3aa1a] px-1.5 py-0.5 text-xs font-bold text-white"
                            >
                                {{ chip.value }}
                            </span>
                        </button>
                    </div>

                    <div
                        class="mt-3 flex items-center justify-between gap-3 px-1"
                    >
                        <p
                            class="text-xs font-medium tracking-wide text-slate-500 uppercase"
                        >
                            {{ dateButtonLabel }}
                        </p>
                        <Button
                            v-if="!isFilterToday"
                            variant="ghost"
                            size="sm"
                            class="h-8 rounded-lg text-[#3e739f] hover:bg-white/70 hover:text-[#24557d]"
                            @click="applyFilters({ date: today })"
                        >
                            <CalendarDays class="size-4" />
                            Aujourd'hui
                        </Button>
                    </div>

                    <div class="mt-2 space-y-2.5">
                        <article
                            v-if="appointments.data.length === 0"
                            class="rounded-2xl border border-white/80 bg-white px-5 py-12 text-center shadow-[0_4px_18px_rgba(38,70,91,0.06)]"
                        >
                            <CalendarDays
                                class="mx-auto size-8 text-slate-300"
                            />
                            <p
                                class="mt-3 text-sm font-semibold text-slate-700"
                            >
                                Aucun rendez-vous pour cette journée.
                            </p>
                            <button
                                v-if="permissions.book"
                                type="button"
                                class="mt-2 text-sm font-semibold text-[#3e739f] hover:underline"
                                @click="openBooking"
                            >
                                Prendre un rendez-vous
                            </button>
                        </article>

                        <article
                            v-for="(appointment, index) in appointments.data"
                            :key="appointment.id"
                            class="group grid gap-3 rounded-2xl border border-white/80 bg-white p-3 shadow-[0_4px_18px_rgba(38,70,91,0.07)] transition hover:-translate-y-px hover:shadow-[0_8px_24px_rgba(38,70,91,0.12)] sm:grid-cols-[44px_minmax(0,1fr)_92px_128px_40px] sm:items-center sm:gap-4 sm:px-4"
                        >
                            <div
                                class="flex size-11 items-center justify-center rounded-full text-sm font-bold shadow-inner"
                                :class="
                                    avatarStyles[index % avatarStyles.length]
                                "
                            >
                                {{ patientInitials(appointment.patient_name) }}
                            </div>

                            <div class="min-w-0">
                                <Link
                                    v-if="appointment.patient_id"
                                    :href="`/app/patients/${appointment.patient_id}`"
                                    class="block truncate text-sm font-bold text-slate-800 hover:text-[#3e739f] hover:underline"
                                >
                                    {{ appointment.patient_name }}
                                </Link>
                                <p
                                    v-else
                                    class="truncate text-sm font-bold text-slate-800"
                                >
                                    {{ appointment.patient_name }}
                                </p>
                                <p
                                    class="mt-0.5 truncate text-xs text-slate-500"
                                >
                                    {{
                                        appointment.patient_number ??
                                        'Dossier patient'
                                    }}
                                    <span v-if="appointment.prestation">
                                        · {{ appointment.prestation }}</span
                                    >
                                </p>
                            </div>

                            <div
                                class="flex items-center gap-1.5 text-sm font-bold text-slate-800 tabular-nums"
                            >
                                <Clock class="size-4 text-slate-400" />
                                <span>{{ appointment.time_label }}</span>
                            </div>

                            <span
                                class="inline-flex w-fit items-center justify-center rounded-full px-3 py-1 text-xs font-bold capitalize"
                                :class="
                                    statusStyles[appointment.status] ??
                                    'bg-slate-100 text-slate-600'
                                "
                            >
                                {{ statusLabel(appointment.status) }}
                            </span>

                            <div class="flex items-center justify-end gap-0.5">
                                <Button
                                    v-if="
                                        permissions.startConsultation &&
                                        consultationIsCompleted(appointment) &&
                                        appointment.consultation_id
                                    "
                                    variant="outline"
                                    size="sm"
                                    class="rounded-lg"
                                    title="Voir la consultation"
                                    @click="startConsultation(appointment)"
                                >
                                    <Eye class="size-4" />
                                    Voir
                                </Button>
                                <Button
                                    v-else-if="
                                        permissions.startConsultation &&
                                        appointment.consultation_id
                                    "
                                    variant="secondary"
                                    size="sm"
                                    class="rounded-lg"
                                    title="Continuer la consultation"
                                    @click="startConsultation(appointment)"
                                >
                                    <Stethoscope class="size-4" />
                                    Continuer
                                </Button>
                                <Button
                                    v-else-if="
                                        permissions.startConsultation &&
                                        appointment.can_start
                                    "
                                    size="sm"
                                    class="rounded-lg bg-[#3e739f] text-white hover:bg-[#24557d]"
                                    title="Démarrer la consultation"
                                    @click="startConsultation(appointment)"
                                >
                                    <Play class="size-4" />
                                    Consultation
                                </Button>
                                <Button
                                    v-if="
                                        permissions.confirm &&
                                        appointment.can_confirm
                                    "
                                    variant="ghost"
                                    size="icon-sm"
                                    class="rounded-lg text-emerald-600 hover:bg-emerald-50 hover:text-emerald-700"
                                    title="Confirmer"
                                    aria-label="Confirmer le rendez-vous"
                                    @click="confirmAppointment(appointment)"
                                >
                                    <Check class="size-4" />
                                </Button>
                                <Button
                                    v-if="
                                        permissions.checkIn &&
                                        appointment.can_check_in
                                    "
                                    variant="ghost"
                                    size="icon-sm"
                                    class="rounded-lg text-[#3e739f] hover:bg-sky-50 hover:text-[#24557d]"
                                    title="Faire entrer le patient"
                                    aria-label="Faire entrer le patient"
                                    @click="checkInAppointment(appointment)"
                                >
                                    <UserCheck class="size-4" />
                                </Button>
                                <Button
                                    v-if="
                                        permissions.cancel &&
                                        appointment.can_cancel
                                    "
                                    variant="ghost"
                                    size="icon-sm"
                                    class="rounded-lg text-slate-400 hover:bg-red-50 hover:text-red-600"
                                    title="Annuler"
                                    aria-label="Annuler le rendez-vous"
                                    @click="openCancel(appointment)"
                                >
                                    <MoreVertical class="size-4" />
                                </Button>
                            </div>
                        </article>
                    </div>

                    <div
                        class="mt-4 flex flex-col gap-3 px-1 text-xs text-slate-500 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <span>
                            {{ appointments.from ?? 0 }}–{{
                                appointments.to ?? 0
                            }}
                            sur {{ appointments.total }} rendez-vous
                        </span>
                        <div class="flex flex-wrap items-center gap-2">
                            <span>Par page</span>
                            <Select
                                :model-value="perPage"
                                @update:model-value="onPerPageChange"
                            >
                                <SelectTrigger
                                    class="h-8 w-[68px] rounded-lg border-white bg-white text-xs shadow-sm"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="10">10</SelectItem>
                                    <SelectItem value="50">50</SelectItem>
                                    <SelectItem value="100">100</SelectItem>
                                </SelectContent>
                            </Select>
                            <div class="flex items-center gap-1">
                                <Button
                                    v-for="link in appointments.links"
                                    :key="link.label"
                                    :variant="
                                        link.active ? 'default' : 'outline'
                                    "
                                    size="sm"
                                    class="h-8 min-w-8 rounded-lg border-white bg-white px-2 text-xs shadow-sm"
                                    :disabled="!link.url"
                                    as-child
                                >
                                    <Link
                                        v-if="link.url"
                                        :href="link.url || '#'"
                                        preserve-scroll
                                        preserve-state
                                    >
                                        <span v-html="link.label" />
                                    </Link>
                                    <span v-else v-html="link.label" />
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>

                <aside class="space-y-5">
                    <section
                        class="rounded-2xl border border-white/80 bg-white p-4 shadow-[0_4px_18px_rgba(38,70,91,0.07)]"
                    >
                        <div class="flex items-center justify-between gap-3">
                            <p class="text-sm font-bold text-slate-800">
                                État d'avancement -
                                {{ Math.round(progressPercent) }}%
                            </p>
                            <span
                                class="text-sm font-bold text-slate-800 tabular-nums"
                            >
                                {{ progressCompleted }}/{{ progressTotal }}
                            </span>
                        </div>
                        <div
                            class="mt-3 h-2.5 overflow-hidden rounded-full bg-slate-100"
                        >
                            <div
                                class="h-full rounded-full bg-[#4c82b7] transition-all"
                                :style="{ width: `${progressPercent}%` }"
                            />
                        </div>
                    </section>

                    <section
                        class="rounded-2xl border border-white/80 bg-white p-4 shadow-[0_4px_18px_rgba(38,70,91,0.07)]"
                    >
                        <div class="flex items-center justify-between gap-3">
                            <h2 class="text-xl font-bold text-[#1d659e]">
                                Salle d'attente d'aujourd'hui
                            </h2>
                            <span
                                class="rounded-full bg-[#e9f2f8] px-2.5 py-1 text-xs font-bold text-[#3e739f]"
                            >
                                {{ waitingAppointments.length }}
                            </span>
                        </div>

                        <div
                            v-if="waitingAppointments.length"
                            class="mt-5 space-y-3"
                        >
                            <div
                                v-for="(
                                    appointment, index
                                ) in waitingAppointments"
                                :key="appointment.id"
                                class="grid grid-cols-[48px_minmax(0,1fr)] gap-2"
                            >
                                <div class="relative text-right">
                                    <span
                                        class="text-[11px] font-bold text-slate-500 tabular-nums"
                                    >
                                        {{ appointment.time_label }}
                                    </span>
                                    <span
                                        class="absolute top-5 right-[-5px] z-10 size-2.5 rounded-full border-2 border-white bg-[#e18e3b] shadow-sm"
                                    />
                                </div>
                                <div
                                    class="relative rounded-r-xl border-l-4 border-[#4c82b7] bg-[#fff7e8] px-3 py-3 shadow-sm"
                                    :class="
                                        index === waitingAppointments.length - 1
                                            ? ''
                                            : 'mb-2'
                                    "
                                >
                                    <div class="flex items-start gap-2">
                                        <div
                                            class="flex size-8 shrink-0 items-center justify-center rounded-full bg-[#e8aa1b] text-[11px] font-bold text-white"
                                        >
                                            {{
                                                patientInitials(
                                                    appointment.patient_name,
                                                )
                                            }}
                                        </div>
                                        <div class="min-w-0">
                                            <p
                                                class="truncate text-sm font-bold text-slate-800"
                                            >
                                                {{ appointment.patient_name }}
                                            </p>
                                            <p
                                                class="mt-0.5 text-xs text-slate-600"
                                            >
                                                {{
                                                    statusLabel(
                                                        appointment.status,
                                                    )
                                                }}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <p
                            v-else
                            class="mt-6 rounded-xl bg-slate-50 px-3 py-8 text-center text-sm text-slate-500"
                        >
                            La salle d'attente est vide.
                        </p>
                    </section>
                </aside>
            </div>
        </section>

        <section
            v-if="false"
            class="w-full rounded-xl border border-sidebar-border/70 bg-background p-6 lg:p-8 dark:border-sidebar-border"
        >
            <div
                class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between"
            >
                <Heading
                    title="Rendez-vous"
                    description="Planifiez et gérez les rendez-vous du cabinet."
                />

                <div class="flex flex-col items-stretch gap-3 sm:items-end">
                    <Button
                        v-if="permissions.book"
                        class="self-start sm:self-end"
                        @click="openBooking"
                    >
                        <Plus class="size-4" />
                        Prendre un rendez-vous
                    </Button>

                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="chip in statChips"
                            :key="chip.key"
                            type="button"
                            class="flex min-w-[64px] flex-col items-center rounded-lg border px-3 py-1.5 text-center transition"
                            :class="
                                statusFilter === chip.key
                                    ? 'border-primary bg-primary/5 dark:bg-primary/10'
                                    : 'border-sidebar-border/70 bg-background hover:bg-muted/50 dark:border-sidebar-border'
                            "
                            @click="onStatusChange(chip.key)"
                        >
                            <span
                                class="text-base font-semibold text-foreground"
                                >{{ chip.value }}</span
                            >
                            <span
                                class="text-[11px] font-medium tracking-wide text-muted-foreground uppercase"
                            >
                                {{ chip.label }}
                            </span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="mt-6 space-y-3">
                <Popover v-model:open="datePickerOpen">
                    <PopoverTrigger as-child>
                        <Button
                            variant="outline"
                            class="w-full justify-start gap-2 font-normal"
                        >
                            <CalendarDays
                                class="size-4 text-muted-foreground"
                            />
                            <span>{{ dateButtonLabel }}</span>
                            <span
                                v-if="isFilterToday"
                                class="ml-auto rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300"
                            >
                                Aujourd’hui
                            </span>
                        </Button>
                    </PopoverTrigger>
                    <PopoverContent class="w-auto p-0" align="start">
                        <Calendar
                            :model-value="dateValue"
                            initial-focus
                            @update:model-value="onDateSelect"
                        />
                    </PopoverContent>
                </Popover>

                <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_220px]">
                    <div class="relative">
                        <Search
                            class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                        />
                        <Input
                            v-model="search"
                            type="search"
                            class="pl-8"
                            placeholder="Rechercher par prénom, nom ou téléphone"
                            aria-label="Rechercher les rendez-vous par patient"
                        />
                    </div>

                    <Select
                        :model-value="statusFilter"
                        @update:model-value="onStatusChange"
                    >
                        <SelectTrigger class="w-full">
                            <SelectValue placeholder="Tous les statuts" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all"
                                >Tous les statuts</SelectItem
                            >
                            <SelectItem
                                v-for="option in statusOptions"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div v-if="!isFilterToday">
                    <Button
                        variant="ghost"
                        size="sm"
                        @click="applyFilters({ date: today })"
                    >
                        <CalendarDays class="size-4" />
                        Revenir à aujourd’hui
                    </Button>
                </div>
            </div>

            <!-- Table -->
            <div class="mt-4 overflow-x-auto">
                <div
                    class="overflow-hidden rounded-lg border border-sidebar-border/70 dark:border-sidebar-border"
                >
                    <table
                        class="w-full min-w-[880px] divide-y divide-sidebar-border/70 text-sm dark:divide-sidebar-border"
                    >
                        <thead
                            class="bg-muted/40 text-left text-xs tracking-wide text-muted-foreground uppercase"
                        >
                            <tr>
                                <th class="px-4 py-3 font-medium">Heure</th>
                                <th class="px-4 py-3 font-medium">Patient</th>
                                <th class="px-4 py-3 font-medium">Acte</th>
                                <th class="px-4 py-3 font-medium">Statut</th>
                                <th class="px-4 py-3 font-medium">Motif</th>
                                <th class="px-4 py-3 text-right font-medium">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody
                            class="divide-y divide-sidebar-border/70 dark:divide-sidebar-border"
                        >
                            <tr v-if="appointments.data.length === 0">
                                <td
                                    class="px-4 py-8 text-center text-muted-foreground"
                                    colspan="6"
                                >
                                    Aucun rendez-vous pour cette journée.
                                    <button
                                        v-if="permissions.book"
                                        type="button"
                                        class="font-medium text-primary hover:underline"
                                        @click="openBooking"
                                    >
                                        Prendre un rendez-vous
                                    </button>
                                </td>
                            </tr>
                            <tr
                                v-for="appointment in appointments.data"
                                :key="appointment.id"
                                class="bg-background"
                            >
                                <td
                                    class="px-4 py-3 font-medium whitespace-nowrap text-foreground"
                                >
                                    {{ appointment.time_label }}
                                    <span class="text-muted-foreground"
                                        >– {{ appointment.end_label }}</span
                                    >
                                </td>
                                <td class="px-4 py-3">
                                    <Link
                                        v-if="appointment.patient_id"
                                        :href="`/app/patients/${appointment.patient_id}`"
                                        class="font-medium text-foreground hover:underline"
                                    >
                                        {{ appointment.patient_name }}
                                    </Link>
                                    <span
                                        v-else
                                        class="font-medium text-foreground"
                                        >{{ appointment.patient_name }}</span
                                    >
                                    <span
                                        class="ml-2 font-mono text-xs text-muted-foreground"
                                    >
                                        {{ appointment.patient_number }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">
                                    {{ appointment.prestation ?? '—' }}
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                                        :class="
                                            statusStyles[appointment.status] ??
                                            'bg-muted text-muted-foreground'
                                        "
                                        :title="
                                            appointment.cancellation_reason ??
                                            ''
                                        "
                                    >
                                        {{ statusLabel(appointment.status) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">
                                    {{ appointment.reason ?? '—' }}
                                </td>
                                <td class="px-4 py-3">
                                    <div
                                        class="flex items-center justify-end gap-1"
                                    >
                                        <Button
                                            v-if="
                                                permissions.startConsultation &&
                                                consultationIsCompleted(
                                                    appointment,
                                                ) &&
                                                appointment.consultation_id
                                            "
                                            variant="outline"
                                            size="sm"
                                            title="Voir la consultation"
                                            @click="startConsultation(appointment)"
                                        >
                                            <Eye class="size-4" />
                                            Voir
                                        </Button>
                                        <Button
                                            v-else-if="
                                                permissions.startConsultation &&
                                                appointment.consultation_id
                                            "
                                            variant="secondary"
                                            size="sm"
                                            title="Continuer la consultation"
                                            @click="startConsultation(appointment)"
                                        >
                                            <Stethoscope class="size-4" />
                                            Continuer
                                        </Button>
                                        <Button
                                            v-else-if="
                                                permissions.startConsultation &&
                                                appointment.can_start
                                            "
                                            size="sm"
                                            class="bg-[#3e739f] text-white hover:bg-[#24557d]"
                                            title="Démarrer la consultation"
                                            @click="startConsultation(appointment)"
                                        >
                                            <Play class="size-4" />
                                            Consultation
                                        </Button>
                                        <Button
                                            v-if="permissions.confirm"
                                            variant="ghost"
                                            size="icon-sm"
                                            :disabled="!appointment.can_confirm"
                                            title="Confirmer le rendez-vous"
                                            @click="
                                                confirmAppointment(appointment)
                                            "
                                        >
                                            <Check
                                                class="size-4 text-emerald-600 dark:text-emerald-400"
                                            />
                                        </Button>
                                        <Button
                                            v-if="permissions.checkIn"
                                            variant="ghost"
                                            size="icon-sm"
                                            :disabled="
                                                !appointment.can_check_in
                                            "
                                            title="Faire entrer le patient"
                                            @click="
                                                checkInAppointment(appointment)
                                            "
                                        >
                                            <UserCheck
                                                class="size-4 text-indigo-600 dark:text-indigo-400"
                                            />
                                        </Button>
                                        <Button
                                            v-if="permissions.cancel"
                                            variant="ghost"
                                            size="icon-sm"
                                            :disabled="!appointment.can_cancel"
                                            title="Annuler le rendez-vous"
                                            @click="openCancel(appointment)"
                                        >
                                            <Ban
                                                class="size-4 text-destructive"
                                            />
                                        </Button>
                                        <span
                                            v-if="
                                                !permissions.confirm &&
                                                !permissions.checkIn &&
                                                !permissions.cancel &&
                                                !permissions.startConsultation
                                            "
                                            class="text-xs text-muted-foreground"
                                        >
                                            —
                                        </span>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div
                    class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div class="flex flex-wrap items-center gap-4">
                        <p class="text-sm text-muted-foreground">
                            Affichage de {{ appointments.from ?? 0 }} à
                            {{ appointments.to ?? 0 }} sur
                            {{ appointments.total }} rendez-vous.
                        </p>
                        <div class="flex items-center gap-2">
                            <span class="text-sm text-muted-foreground"
                                >Par page</span
                            >
                            <Select
                                :model-value="perPage"
                                @update:model-value="onPerPageChange"
                            >
                                <SelectTrigger class="h-8 w-[72px]">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="10">10</SelectItem>
                                    <SelectItem value="50">50</SelectItem>
                                    <SelectItem value="100">100</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <Button
                            v-for="link in appointments.links"
                            :key="link.label"
                            :variant="link.active ? 'default' : 'outline'"
                            size="sm"
                            :disabled="!link.url"
                            as-child
                        >
                            <Link
                                v-if="link.url"
                                :href="link.url || '#'"
                                preserve-scroll
                                preserve-state
                            >
                                <span v-html="link.label" />
                            </Link>
                            <span v-else v-html="link.label" />
                        </Button>
                    </div>
                </div>
            </div>
        </section>

        <!-- Booking modal: patient search on top, calendar left, slots right -->
        <Dialog v-model:open="showBooking">
            <DialogScrollContent
                class="max-h-[calc(100vh-2rem)] sm:max-w-6xl xl:max-w-7xl"
            >
                <DialogHeader>
                    <DialogTitle>Prendre un rendez-vous</DialogTitle>
                    <DialogDescription>
                        Recherchez un patient, choisissez une journée, puis un
                        créneau disponible.
                    </DialogDescription>
                </DialogHeader>

                <div
                    class="grid gap-6 lg:grid-cols-[minmax(0,26rem)_minmax(0,1fr)]"
                >
                    <!-- Left column: patient, prestation, reason -->
                    <div class="space-y-5">
                        <!-- Patient -->
                        <div class="grid gap-2">
                            <div
                                class="flex items-center justify-between gap-2"
                            >
                                <Label for="modal-patient-search"
                                    >Patient</Label
                                >
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    @click="openCreatePatient"
                                >
                                    <Plus class="size-4" />
                                    Nouveau patient
                                </Button>
                            </div>

                            <div
                                v-if="selectedPatient"
                                class="flex items-center justify-between gap-2 rounded-md border border-sidebar-border/70 px-3 py-2 dark:border-sidebar-border"
                            >
                                <span>
                                    <span class="font-medium text-foreground">{{
                                        selectedPatient.full_name
                                    }}</span>
                                    <span
                                        class="ml-2 font-mono text-xs text-muted-foreground"
                                    >
                                        {{ selectedPatient.patient_number }}
                                    </span>
                                </span>
                                <div class="flex items-center gap-1">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        @click="openEditPatient"
                                    >
                                        <Pencil class="size-4" />
                                        Modifier
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        @click="clearPatient"
                                    >
                                        <X class="size-4" />
                                        Changer
                                    </Button>
                                </div>
                            </div>

                            <template v-else>
                                <div class="relative">
                                    <Search
                                        class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                                    />
                                    <Input
                                        id="modal-patient-search"
                                        v-model="patientSearch"
                                        type="search"
                                        class="pl-8"
                                        placeholder="Rechercher par nom ou numéro de dossier"
                                        autocomplete="off"
                                    />
                                </div>
                                <div
                                    class="max-h-56 overflow-y-auto rounded-md border border-sidebar-border/70 dark:border-sidebar-border"
                                >
                                    <p
                                        v-if="filteredPatients.length === 0"
                                        class="px-3 py-4 text-center text-sm text-muted-foreground"
                                    >
                                        Aucun patient trouvé.
                                    </p>
                                    <button
                                        v-for="patient in filteredPatients"
                                        :key="patient.id"
                                        type="button"
                                        class="flex w-full items-center justify-between gap-2 border-b border-sidebar-border/40 px-3 py-2 text-left text-sm last:border-b-0 hover:bg-accent"
                                        @click="choosePatient(patient)"
                                    >
                                        <span
                                            class="font-medium text-foreground"
                                            >{{ patient.full_name }}</span
                                        >
                                        <span
                                            class="font-mono text-xs text-muted-foreground"
                                        >
                                            {{ patient.patient_number }}
                                        </span>
                                    </button>
                                </div>
                            </template>
                            <InputError :message="form.errors.patient_id" />
                        </div>

                        <!-- Prestation (searchable Acts dropdown) -->
                        <div class="grid gap-2">
                            <Label>Prestation</Label>
                            <Popover v-model:open="prestationOpen">
                                <PopoverTrigger as-child>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        class="w-full justify-between gap-2 font-normal"
                                    >
                                        <span
                                            v-if="selectedPrestation"
                                            class="truncate"
                                        >
                                            {{ selectedPrestation.label }}
                                            <span
                                                v-if="
                                                    selectedPrestation.amount !==
                                                    null
                                                "
                                                class="text-muted-foreground"
                                            >
                                                ·
                                                {{
                                                    formatPrestationAmount(
                                                        selectedPrestation.amount,
                                                    )
                                                }}
                                            </span>
                                        </span>
                                        <span
                                            v-else
                                            class="text-muted-foreground"
                                        >
                                            Choisir une prestation
                                        </span>
                                        <ChevronsUpDown
                                            class="size-4 shrink-0 opacity-50"
                                        />
                                    </Button>
                                </PopoverTrigger>
                                <PopoverContent
                                    class="w-[22rem] max-w-[90vw] p-0"
                                    align="start"
                                >
                                    <div
                                        class="border-b border-sidebar-border/70 p-2 dark:border-sidebar-border"
                                    >
                                        <div class="relative">
                                            <Search
                                                class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                                            />
                                            <Input
                                                v-model="prestationSearch"
                                                type="search"
                                                class="h-8 pl-8"
                                                placeholder="Rechercher un acte"
                                                autocomplete="off"
                                            />
                                        </div>
                                    </div>
                                    <div class="max-h-52 overflow-y-auto p-1">
                                        <p
                                            v-if="
                                                filteredPrestations.length === 0
                                            "
                                            class="px-2 py-4 text-center text-sm text-muted-foreground"
                                        >
                                            Aucun acte trouvé.
                                        </p>
                                        <div
                                            v-for="prestation in filteredPrestations"
                                            :key="`${prestation.source}-${prestation.id}`"
                                            class="group flex items-center gap-1 rounded-md hover:bg-accent"
                                        >
                                            <button
                                                type="button"
                                                class="flex flex-1 items-center justify-between gap-2 px-2 py-1.5 text-left text-sm"
                                                @click="
                                                    selectPrestation(prestation)
                                                "
                                            >
                                                <span
                                                    class="flex min-w-0 items-center gap-2"
                                                >
                                                    <Check
                                                        v-if="
                                                            selectedPrestation &&
                                                            selectedPrestation.source ===
                                                                prestation.source &&
                                                            selectedPrestation.id ===
                                                                prestation.id
                                                        "
                                                        class="size-4 shrink-0 text-primary"
                                                    />
                                                    <span class="truncate">{{
                                                        prestation.label
                                                    }}</span>
                                                </span>
                                                <span
                                                    v-if="
                                                        prestation.amount !==
                                                        null
                                                    "
                                                    class="shrink-0 font-mono text-xs text-muted-foreground"
                                                >
                                                    {{
                                                        formatPrestationAmount(
                                                            prestation.amount,
                                                        )
                                                    }}
                                                </span>
                                            </button>
                                            <Button
                                                v-if="permissions.manageActs"
                                                type="button"
                                                variant="ghost"
                                                size="icon-sm"
                                                class="mr-1 shrink-0 opacity-0 transition group-hover:opacity-100"
                                                title="Renommer l’acte"
                                                @click="
                                                    startPrestationEdit(
                                                        prestation,
                                                    )
                                                "
                                            >
                                                <Pencil class="size-3.5" />
                                            </Button>
                                        </div>
                                    </div>
                                    <div
                                        v-if="permissions.manageActs"
                                        class="border-t border-sidebar-border/70 p-2 dark:border-sidebar-border"
                                    >
                                        <template v-if="prestationEditorMode">
                                            <Label
                                                class="text-xs text-muted-foreground"
                                            >
                                                {{
                                                    prestationEditorMode ===
                                                    'edit'
                                                        ? 'Renommer l’acte'
                                                        : 'Nouvel acte'
                                                }}
                                            </Label>
                                            <div
                                                class="mt-1 flex items-center gap-2"
                                            >
                                                <Input
                                                    v-model="
                                                        prestationEditorValue
                                                    "
                                                    class="h-8"
                                                    placeholder="Nom de l’acte"
                                                    autocomplete="off"
                                                    @keydown.enter.prevent="
                                                        savePrestation
                                                    "
                                                />
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    :disabled="
                                                        prestationProcessing
                                                    "
                                                    @click="savePrestation"
                                                >
                                                    Enregistrer
                                                </Button>
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="ghost"
                                                    @click="
                                                        cancelPrestationEditor
                                                    "
                                                >
                                                    Annuler
                                                </Button>
                                            </div>
                                            <p
                                                v-if="prestationEditorError"
                                                class="mt-1 text-xs text-destructive"
                                            >
                                                {{ prestationEditorError }}
                                            </p>
                                        </template>
                                        <Button
                                            v-else
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            class="w-full justify-start gap-2"
                                            @click="startPrestationCreate"
                                        >
                                            <Plus class="size-4" />
                                            Ajouter un acte
                                        </Button>
                                    </div>
                                </PopoverContent>
                            </Popover>
                        </div>

                        <!-- Reason -->
                        <div class="grid gap-2">
                            <Label for="modal-reason">Motif (facultatif)</Label>
                            <Textarea
                                id="modal-reason"
                                v-model="form.reason"
                                rows="2"
                                placeholder="Motif de la visite"
                            />
                            <InputError :message="form.errors.reason" />
                        </div>

                        <!-- Ready summary -->
                        <div
                            class="rounded-md bg-muted/40 px-3 py-2 text-sm text-muted-foreground"
                        >
                            <template v-if="selectedPatient && activeSlot">
                                <span class="font-medium text-foreground"
                                    >Prêt :</span
                                >
                                {{ selectedPatient.full_name }} ·
                                {{ selectedDateLabel }} ·
                                {{ activeSlot.label }}–{{
                                    activeSlot.end_label
                                }}
                            </template>
                            <template v-else>
                                Sélectionnez un patient et un créneau pour
                                continuer.
                            </template>
                        </div>
                    </div>

                    <!-- Right column: calendar + slots -->
                    <div class="space-y-3">
                        <p
                            v-if="!hasDoctor"
                            class="rounded-lg border border-amber-300/70 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-200"
                        >
                            Aucun médecin actif n’est configuré pour le cabinet.
                            Les disponibilités apparaîtront après la
                            configuration du médecin et de ses horaires.
                        </p>

                        <div
                            v-else
                            class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(200px,240px)]"
                        >
                            <AvailabilityCalendar
                                :month="currentMonth"
                                :selected-date="selectedDate"
                                :loading="loadingMonth"
                                @select-date="loadDay"
                                @change-month="
                                    (year, month) => loadMonth(year, month)
                                "
                            />

                            <div
                                class="rounded-xl border border-sidebar-border/70 bg-background p-3 dark:border-sidebar-border"
                            >
                                <div
                                    class="flex items-center gap-2 text-foreground"
                                >
                                    <Clock
                                        class="size-4 text-muted-foreground"
                                    />
                                    <h3 class="text-sm font-semibold">
                                        {{
                                            selectedDate
                                                ? 'Créneaux'
                                                : 'Choisir une journée'
                                        }}
                                    </h3>
                                </div>

                                <p
                                    v-if="!selectedDate"
                                    class="mt-3 text-xs text-muted-foreground"
                                >
                                    Choisissez une journée disponible pour
                                    afficher les créneaux libres.
                                </p>

                                <div
                                    v-else-if="loadingDay"
                                    class="mt-3 space-y-2"
                                >
                                    <div
                                        class="h-8 animate-pulse rounded-md bg-muted/50"
                                    />
                                    <div
                                        class="h-8 animate-pulse rounded-md bg-muted/50"
                                    />
                                    <div
                                        class="h-8 animate-pulse rounded-md bg-muted/50"
                                    />
                                </div>

                                <template v-else-if="dayData">
                                    <div
                                        v-if="hasSlots"
                                        class="mt-3 grid grid-cols-2 gap-1.5 sm:grid-cols-3"
                                    >
                                        <button
                                            v-for="slot in dayData.slots"
                                            :key="slot.starts_at"
                                            type="button"
                                            :class="slotButtonClasses(slot)"
                                            :disabled="
                                                !slot.available ||
                                                !permissions.book
                                            "
                                            :title="
                                                slot.reason === 'booked'
                                                    ? 'Déjà réservé'
                                                    : ''
                                            "
                                            @click="selectSlot(slot)"
                                        >
                                            <span class="font-semibold">{{
                                                slot.label
                                            }}</span>
                                        </button>
                                    </div>

                                    <p
                                        v-else
                                        class="mt-3 rounded-md bg-muted/40 px-3 py-2 text-xs text-muted-foreground"
                                    >
                                        {{ closedReasonLabel }}
                                    </p>
                                </template>
                            </div>
                        </div>

                        <InputError :message="form.errors.starts_at" />
                    </div>
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="showBooking = false"
                        >Annuler</Button
                    >
                    <Button
                        type="button"
                        :disabled="!canConfirm"
                        @click="submitBooking"
                        >Confirmer le rendez-vous</Button
                    >
                </DialogFooter>
            </DialogScrollContent>
        </Dialog>

        <!-- Patient create / edit dialog -->
        <Dialog v-model:open="patientFormOpen">
            <DialogScrollContent class="max-h-[calc(100vh-2rem)] sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>
                        {{
                            patientFormMode === 'edit'
                                ? 'Modifier le patient'
                                : 'Créer un patient'
                        }}
                    </DialogTitle>
                    <DialogDescription>
                        {{
                            patientFormMode === 'edit'
                                ? 'Mettez à jour les informations du patient sélectionné.'
                                : 'Ajoutez un patient ; il sera sélectionné automatiquement après sa création.'
                        }}
                    </DialogDescription>
                </DialogHeader>

                <div
                    v-if="patientFormLoading"
                    class="py-12 text-center text-sm text-muted-foreground"
                >
                    Chargement du patient…
                </div>
                <PatientForm
                    v-else
                    :key="patientFormKey"
                    :genders="genders"
                    :blood-groups="bloodGroups"
                    :method="patientFormMode === 'edit' ? 'put' : 'post'"
                    :submit-url="
                        patientFormMode === 'edit' && patientFormPatient
                            ? `/app/patients/${patientFormPatient.id}/json`
                            : '/app/patients/json'
                    "
                    :submit-label="
                        patientFormMode === 'edit'
                            ? 'Enregistrer les modifications'
                            : 'Créer le patient'
                    "
                    :patient="patientFormPatient"
                    mode="json"
                    @success="handlePatientSaved"
                    @cancel="patientFormOpen = false"
                />
            </DialogScrollContent>
        </Dialog>

        <!-- Cancel appointment dialog -->
        <Dialog
            :open="cancelTarget !== null"
            @update:open="
                (value) => {
                    if (!value) cancelTarget = null;
                }
            "
        >
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Annuler le rendez-vous</DialogTitle>
                    <DialogDescription>
                        <template v-if="cancelTarget">
                            Annuler le rendez-vous de
                            {{ cancelTarget.patient_name }} à
                            {{ cancelTarget.time_label }} ? Un motif est requis.
                        </template>
                    </DialogDescription>
                </DialogHeader>

                <form class="space-y-3" @submit.prevent="submitCancel">
                    <div class="grid gap-2">
                        <Label for="cancel-reason">Motif</Label>
                        <Textarea
                            id="cancel-reason"
                            v-model="cancelForm.reason"
                            rows="3"
                            placeholder="Pourquoi ce rendez-vous est-il annulé ?"
                            required
                        />
                        <InputError :message="cancelForm.errors.reason" />
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="cancelTarget = null"
                            >Conserver le rendez-vous</Button
                        >
                        <Button
                            type="submit"
                            variant="destructive"
                            :disabled="cancelForm.processing"
                        >
                            Annuler le rendez-vous
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
