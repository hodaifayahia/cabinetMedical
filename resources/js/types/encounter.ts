export type EncounterStatus = 'draft' | 'in_progress' | 'signed' | 'void';

export interface EncounterUser {
    id: number;
    name: string;
}

export interface EncounterNotes {
    reason_for_visit: string;
    clinical_examination: string;
    diagnosis_assessment: string;
    treatment_plan: string;
}

export interface EncounterPatientSummary {
    id: number;
    full_name: string;
    patient_number: string;
}

export interface EncounterListItem {
    id: number;
    status: EncounterStatus;
    occurred_at: string | null;
    started_at: string | null;
    signed_at: string | null;
    provider: EncounterUser | null;
    signed_by: EncounterUser | null;
}

export interface EncounterDetail {
    id: number;
    patient_id: number;
    status: EncounterStatus;
    occurred_at: string | null;
    started_at: string | null;
    signed_at: string | null;
    revision_number: number;
    lock_version: number;
    provider: EncounterUser | null;
    signed_by: EncounterUser | null;
    content_hash: string | null;
    notes: EncounterNotes;
    amends_encounter_id: number | null;
    amendment_reason: string | null;
}
