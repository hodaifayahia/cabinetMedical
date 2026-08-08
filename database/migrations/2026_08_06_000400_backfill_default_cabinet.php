<?php

use App\Enums\CabinetStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tables that carry a cabinet_id and should be attached to the default
     * cabinet on an existing single-cabinet install.
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

    /**
     * Upgrade an existing single-cabinet install: promote the legacy
     * CabinetSetting singleton into a real, active Cabinet and backfill every
     * scoped row and user. Fresh installs (no users / no settings) no-op.
     */
    public function up(): void
    {
        // A fresh install has no users yet — nothing to backfill.
        if (! Schema::hasTable('users') || DB::table('users')->count() === 0) {
            return;
        }

        $setting = Schema::hasTable('cabinet_settings')
            ? DB::table('cabinet_settings')->orderBy('id')->first()
            : null;

        // If a cabinet already exists (e.g. re-run), reuse the earliest one.
        $cabinetId = DB::table('cabinets')->orderBy('id')->value('id');

        if ($cabinetId === null) {
            $owner = DB::table('users')->orderBy('id')->first();

            $cabinetId = DB::table('cabinets')->insertGetId([
                'name' => $setting->name ?? (string) config('clinic.name', config('app.name', 'Cabinet')),
                'status' => CabinetStatus::ACTIVE->value,
                'specialization' => null,
                'wilaya_code' => null,
                'owner_user_id' => $owner->id ?? null,
                'activated_at' => now(),
                'license_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Link the legacy settings row to the cabinet.
        if ($setting !== null && Schema::hasColumn('cabinet_settings', 'cabinet_id')) {
            DB::table('cabinet_settings')
                ->where('id', $setting->id)
                ->whereNull('cabinet_id')
                ->update(['cabinet_id' => $cabinetId]);
        }

        // Attach all existing users to the cabinet and mark them approved.
        DB::table('users')->whereNull('cabinet_id')->update(['cabinet_id' => $cabinetId]);

        if (Schema::hasColumn('users', 'approved_at')) {
            DB::table('users')->whereNull('approved_at')->update(['approved_at' => now()]);
        }

        // Backfill every scoped table.
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'cabinet_id')) {
                continue;
            }

            DB::table($table)->whereNull('cabinet_id')->update(['cabinet_id' => $cabinetId]);
        }
    }

    public function down(): void
    {
        // Backfill is not reversible; leaving data in place is the safe choice.
    }
};
