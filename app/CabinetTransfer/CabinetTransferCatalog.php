<?php

namespace App\CabinetTransfer;

/**
 * What moves when a cabinet's records leave the online service for its PC.
 *
 * The online service sends every row below with its original id, so the
 * references between them (including ids kept inside JSON columns) stay
 * valid on the PC. The PC must be empty: it receives the cabinet as is.
 *
 * Kept on the online service and never sent: billing (ai_usages), licence
 * grants, the mobile sync journal, download leads and Hub binding. They are
 * the service's own state, not the cabinet's records.
 */
final class CabinetTransferCatalog
{
    public const FORMAT = 'drclick-cabinet-transfer-v1';

    /** Rows per page when the PC pulls a table. */
    public const PAGE_SIZE = 500;

    /**
     * Tables scoped by `cabinet_id`, in the order the PC inserts them
     * (parents first; foreign keys are checked once everything is in).
     *
     * @var list<string>
     */
    public const CABINET_TABLES = [
        'cabinet_settings',
        'accounting_settings',
        'cabinet_role_permission_sets',
        'cabinet_public_profiles',
        'doctor_profiles',
        'doctor_schedules',
        'doctor_time_off',
        'doctor_open_months',
        'practitioners',
        'acts',
        'consultation_fees',
        'payment_methods',
        'medications',
        'exams',
        'bilan_types',
        'bilan_templates',
        'document_templates',
        'document_sequences',
        'prescription_protocols',
        'expenses',
        'patients',
        'patient_alerts',
        'patient_antecedents',
        'patient_measurements',
        'patient_recalls',
        'patient_relatives',
        'patient_vaccinations',
        'appointments',
        'encounters',
        'encounter_notes',
        'diagnoses',
        'clinical_observations',
        'consultations',
        'consultation_diagnoses',
        'documents',
        'prescriptions',
        'upload_sessions',
        'uploaded_documents',
        'ecg_records',
        'payments',
        'ai_conversations',
        'ai_insights',
        'audit_logs',
    ];

    /**
     * Columns blanked when a row leaves: secrets tied to the online service
     * (its encryption key, its licence rows) that mean nothing on the PC.
     *
     * @var array<string, list<string>>
     */
    public const BLANKED_ON_EXPORT = [
        'cabinets' => ['license_id', 'clinical_data_transferred_at', 'clinical_data_transferred_to'],
        'users' => ['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'remember_token'],
    ];

    /**
     * Files the rows point to. `disk` null means the disk named in the
     * row's own `disk` column.
     *
     * @var list<array{table: string, column: string, disk: string|null}>
     */
    public const FILES = [
        ['table' => 'documents', 'column' => 'file_path', 'disk' => 'local'],
        ['table' => 'ecg_records', 'column' => 'file_path', 'disk' => 'local'],
        ['table' => 'uploaded_documents', 'column' => 'path', 'disk' => null],
        ['table' => 'cabinet_settings', 'column' => 'logo_path', 'disk' => 'public'],
        ['table' => 'doctor_profiles', 'column' => 'logo_path', 'disk' => 'public'],
    ];

    /**
     * Deleted from the online service once the PC holds a verified copy.
     * Children first.
     *
     * @var list<string>
     */
    public const PURGED_TABLES = [
        'ai_insights',
        'ai_conversations',
        'payments',
        'ecg_records',
        'uploaded_documents',
        'upload_sessions',
        'prescriptions',
        'documents',
        'consultation_diagnoses',
        'consultations',
        'clinical_observations',
        'diagnoses',
        'encounter_notes',
        'encounters',
        'patient_vaccinations',
        'patient_relatives',
        'patient_recalls',
        'patient_measurements',
        'patient_antecedents',
        'patient_alerts',
        'expenses',
        'audit_logs',
    ];

    /**
     * Patients and appointments stay online for the mobile app, which books
     * through the online service. Only what that needs is kept: identity
     * and contact. These medical and personal columns are blanked.
     *
     * @var list<string>
     */
    public const PATIENT_COLUMNS_BLANKED = [
        'notes',
        'blood_group',
        'allergies',
        'antecedents_medical',
        'antecedents_surgical',
        'antecedents_family',
        'antecedents_gyneco',
        'antecedents_other',
        'smoking_status',
        'profession',
        'marital_status',
        'referred_by',
        'address',
        'secondary_phone',
        'emergency_contact_name',
        'emergency_contact_phone',
        'place_of_birth',
    ];

    /** @return list<string> */
    public static function allTables(): array
    {
        return ['cabinets', 'users', 'model_has_roles', 'model_has_permissions', ...self::CABINET_TABLES];
    }
}
