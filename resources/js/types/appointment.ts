export type MonthDay = {
    date: string;
    day: number;
    weekday: number;
    is_open_month: boolean;
    is_working_day: boolean;
    is_day_off: boolean;
    is_past: boolean;
    available_count: number;
    bookable: boolean;
};

export type MonthOverview = {
    year: number;
    month: number;
    is_open_month: boolean;
    days: MonthDay[];
};

export type Slot = {
    starts_at: string;
    ends_at: string;
    label: string;
    end_label: string;
    available: boolean;
    reason: string | null;
};

export type DayAppointment = {
    id: number;
    patient_name: string | null;
    patient_number: string | null;
    starts_at: string | null;
    ends_at: string | null;
    label: string | null;
    status: string;
    reason: string | null;
};

export type AppointmentListItem = {
    id: number;
    date: string | null;
    starts_at: string | null;
    time_label: string | null;
    end_label: string | null;
    patient_id: number | null;
    patient_name: string | null;
    patient_number: string | null;
    status: string;
    reason: string | null;
    prestation: string | null;
    cancellation_reason: string | null;
    can_confirm: boolean;
    can_check_in: boolean;
    can_cancel: boolean;
};

export type AppointmentStatusOption = {
    value: string;
    label: string;
};

export type AppointmentStats = Record<string, number>;

export type AppointmentFilters = {
    date: string;
    search: string;
    status: string | null;
    per_page: number;
};

export type DayAvailability = {
    date: string;
    reason: string | null;
    slots: Slot[];
    appointments: DayAppointment[];
};

export type AppointmentPatientOption = {
    id: number;
    full_name: string;
    patient_number: string;
};

export type AppointmentPrestationOption = {
    id: number;
    label: string;
    amount: number | null;
    category: string | null;
    source: 'consultation_fee' | 'act';
};

export type WeekdayOption = {
    value: number;
    label: string;
};

export type ScheduleDay = {
    day_of_week: number;
    label: string;
    is_working: boolean;
    starts_at: string;
    ends_at: string;
    slot_duration: number | null;
};

export type OpenMonth = {
    id: number;
    year: number;
    month: number;
    is_open: boolean;
    note: string | null;
    label: string;
};

export type TimeOffEntry = {
    id: number;
    starts_at: string;
    ends_at: string;
    is_all_day: boolean;
    reason: string | null;
};
