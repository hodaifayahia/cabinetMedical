// The patient's medical history (antécédents) and what the dossiers of their
// relatives report: one set of labels for the patient form, the dossier,
// the consultation workspace and the visit history.

export type PatientHistoryKey =
    | 'allergies'
    | 'antecedents_medical'
    | 'antecedents_surgical'
    | 'antecedents_family'
    | 'antecedents_gyneco'
    | 'antecedents_other';

export type PatientHistoryField = {
    key: PatientHistoryKey;
    label: string;
    placeholder: string;
};

/** Longest text accepted per field (mirrors PatientValidationRules). */
export const PATIENT_HISTORY_MAX = 10000;

export const PATIENT_HISTORY_FIELDS: readonly PatientHistoryField[] = [
    {
        key: 'allergies',
        label: 'Allergies et réactions connues',
        placeholder: 'Ex. pénicilline (urticaire), latex, iode',
    },
    {
        key: 'antecedents_medical',
        label: 'Maladies chroniques / antécédents médicaux',
        placeholder: 'Ex. diabète type 2, HTA, asthme',
    },
    {
        key: 'antecedents_surgical',
        label: 'Antécédents chirurgicaux',
        placeholder: 'Ex. appendicectomie en 2020',
    },
    {
        key: 'antecedents_family',
        label: 'Antécédents familiaux',
        placeholder: 'Ex. père diabétique, mère hypertendue',
    },
    {
        key: 'antecedents_gyneco',
        label: 'Antécédents gynéco-obstétricaux',
        placeholder: 'Ex. G2P2, césarienne en 2018',
    },
    {
        key: 'antecedents_other',
        label: 'Autres antécédents',
        placeholder: 'Ex. mode de vie, vaccinations, habitudes',
    },
];

export const patientHistoryLabel = (key: PatientHistoryKey): string =>
    PATIENT_HISTORY_FIELDS.find((field) => field.key === key)?.label ?? key;

/**
 * The filled history fields of a dossier, with their labels, in the usual
 * order. Blank values are left out.
 */
export const filledPatientHistory = (
    patient: Partial<Record<PatientHistoryKey, string | null | undefined>>,
): { key: PatientHistoryKey; label: string; value: string }[] =>
    PATIENT_HISTORY_FIELDS.map((field) => ({
        key: field.key,
        label: field.label,
        value: (patient[field.key] ?? '').trim(),
    })).filter((field) => field.value !== '');

export type FamilyFindingCategory =
    'allergy' | 'condition' | 'surgical' | 'family' | 'diagnosis';

export type FamilyFinding = {
    category: FamilyFindingCategory;
    label: string;
    text: string;
    display: string;
};

/** A relative of the patient and what their own dossier reports. */
export type FamilyMedicalRelative = {
    patient_id: number;
    full_name: string;
    short_name: string;
    patient_number: string | null;
    gender: string | null;
    age: number | null;
    relation: string | null;
    relation_label: string;
    first_degree: boolean;
    /** « link »: linked by the cabinet; « mobile »: same mobile family account. */
    source: 'link' | 'mobile';
    link_id: string | null;
    items: FamilyFinding[];
    summary: string | null;
    has_alert: boolean;
};

/** Relatives whose dossier reports at least one finding. */
export const relativesWithFindings = (
    relatives: readonly FamilyMedicalRelative[],
): FamilyMedicalRelative[] =>
    relatives.filter((relative) => relative.items.length > 0);

/**
 * Parents, brothers, sisters and children with a chronic disease or an
 * allergy: worth a visible notice during the consultation.
 */
export const firstDegreeAlerts = (
    relatives: readonly FamilyMedicalRelative[],
): FamilyMedicalRelative[] =>
    relatives.filter((relative) => relative.first_degree && relative.has_alert);

/** « Frère — Ahmed B. : Diabète type 2 ; Allergie : pénicilline » */
export const formatRelativeFinding = (
    relative: FamilyMedicalRelative,
): string =>
    `${relative.relation_label} — ${relative.short_name}` +
    (relative.summary ? ` : ${relative.summary}` : '');
