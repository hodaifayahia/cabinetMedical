export type PatientListItem = {
    id: number;
    patient_number: string;
    full_name: string;
    date_of_birth: string | null;
    gender: string | null;
    phone: string | null;
    city: string | null;
    created_at: string | null;
};

export type PatientDetail = {
    id: number;
    patient_number: string;
    first_name: string;
    last_name: string;
    full_name: string;
    date_of_birth: string | null;
    gender: string | null;
    phone: string | null;
    secondary_phone: string | null;
    email: string | null;
    address: string | null;
    city: string | null;
    emergency_contact_name: string | null;
    emergency_contact_phone: string | null;
    blood_group: string | null;
    notes: string | null;
    created_at: string | null;
    updated_at: string | null;
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
