<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cabinet-scoped tables receive a nullable, indexed cabinet_id foreign key.
     * Nullable is deliberate: console/seeder created rows and the platform
     * (cross-cabinet) context may legitimately produce unscoped records, and a
     * later backfill migration attaches existing single-cabinet data.
     *
     * @var list<string>
     */
    private array $tables = [
        'patients',
        'appointments',
        'doctor_profiles',
        'doctor_schedules',
        'doctor_open_months',
        'doctor_time_off',
        'encounters',
        'encounter_notes',
        'diagnoses',
        'clinical_observations',
        'patient_antecedents',
        'patient_measurements',
        'consultations',
        'prescriptions',
        'medications',
        'acts',
        'exams',
        'bilan_types',
        'consultation_fees',
        'payment_methods',
        'practitioners',
        'documents',
        'uploaded_documents',
        'upload_sessions',
        'audit_logs',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'cabinet_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->foreignId('cabinet_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('cabinets')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'cabinet_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropConstrainedForeignId('cabinet_id');
            });
        }
    }
};
