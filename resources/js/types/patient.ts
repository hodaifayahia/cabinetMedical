export type PatientListItem = {
    id: number;
    patient_number: string;
    full_name: string;
    date_of_birth: string | null;
    gender: string | null;
    phone: string | null;
    city: string | null;
    created_at: string | null;
    visits_count: number;
    last_visit_at: string | null;
    next_appointment_at: string | null;
    alerts_count: number;
};

export type PatientIndexStats = {
    total: number;
    new_this_month: number;
    seen_this_month: number;
    /** Null when the user may not merge dossiers. */
    duplicates: number | null;
};

export type PatientOverview = {
    counts: {
        consultations: number;
        prescriptions: number;
        documents: number;
        ecgs: number;
        appointments: number;
    };
    next_appointment: {
        starts_at: string | null;
        reason: string | null;
    } | null;
    recent_consultations: {
        id: number;
        consulted_at: string | null;
        motif: string | null;
        diagnostic: string | null;
        status: string;
    }[];
    merged: {
        patient_number: string;
        full_name: string;
        merged_at: string | null;
    }[];
};

/** A dossier as shown when finding and merging duplicates. */
export type PatientMergeSummary = {
    id: number;
    patient_number: string;
    full_name: string;
    date_of_birth: string | null;
    gender: string | null;
    phone: string | null;
    city: string | null;
    created_at: string | null;
    visits_count: number;
    last_visit_at: string | null;
};

export type PatientMergePreview = {
    primary: PatientMergeSummary | null;
    duplicate: PatientMergeSummary | null;
    fields: {
        field: string;
        label: string;
        primary: string | null;
        duplicate: string | null;
        clinical: boolean;
        conflict: boolean;
    }[];
    moves: { table: string; label: string; count: number }[];
};

export type PatientDetail = {
    id: number;
    patient_number: string;
    first_name: string;
    last_name: string;
    full_name: string;
    date_of_birth: string | null;
    gender: string | null;
    marital_status: string | null;
    marital_status_label: string | null;
    profession: string | null;
    smoking_status: string | null;
    smoking_status_label: string | null;
    referred_by: string | null;
    phone: string | null;
    secondary_phone: string | null;
    email: string | null;
    address: string | null;
    city: string | null;
    emergency_contact_name: string | null;
    emergency_contact_phone: string | null;
    blood_group: string | null;
    allergies: string | null;
    antecedents_medical: string | null;
    antecedents_surgical: string | null;
    antecedents_family: string | null;
    antecedents_gyneco: string | null;
    antecedents_other: string | null;
    notes: string | null;
    created_at: string | null;
    updated_at: string | null;
};

/** A dossier found when linking a relative. */
export type RelativeCandidate = {
    id: number;
    name: string;
    number: string | null;
    phone: string | null;
    gender: string | null;
    age: number | null;
    linked: boolean;
};

export type PatientOption = {
    value: string;
    label: string;
};

export type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

export type Paginator<T> = {
    data: T[];
    current_page: number;
    from: number | null;
    last_page: number;
    links: PaginationLink[];
    per_page: number;
    to: number | null;
    total: number;
};
