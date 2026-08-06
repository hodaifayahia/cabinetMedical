export type ClinicalDocument = {
    id: number;
    category: 'ordonnance' | 'bilan' | 'courrier';
    title: string;
    created_at: string | null;
    paper_size: 'A4' | 'A5';
    has_word_file: boolean;
    editor_config: Record<string, unknown> | null;
    download_url: string | null;
};

export type UploadedConsultationFile = {
    id: number;
    title: string;
    original_filename: string | null;
    mime_type: string | null;
    file_size: number | null;
    created_at: string | null;
    download_url: string;
};

export type ClinicalDocumentTemplate = {
    source: 'built_in';
    key: string;
    category: 'general' | 'ordonnance' | 'bilan' | 'courrier';
    group: string;
    title: string;
    description: string | null;
    body?: string | null;
    default_paper_size: 'A4' | 'A5';
};

export type ClinicalOnlyOfficeSettings = {
    url: string;
    warning: string | null;
};

export type DocumentBranding = {
    doctor_name: string;
    specialty: string;
    order_number: string;
    clinic_name: string;
    phone: string;
    email: string;
    address: string;
    city: string;
    footer: string;
    logo_url: string | null;
};

export type MedicationOption = {
    id: number;
    name: string;
    dci: string | null;
    form: string | null;
    dosage: string | null;
    notes: string | null;
};

export type ExamOption = {
    id: number;
    name: string;
    category: 'labo' | 'cardio' | 'radio' | string;
};
