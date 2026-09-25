<?php

use App\Support\MedicalSpecialtyCatalog;
use App\Support\SpecialtyArabicLabels;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The medical specialty catalogue moves from code constants into a table the
 * platform admin manages: add a specialty, fix a label, or switch one off so
 * the patient app's specialty filter only offers what the platform covers.
 *
 * The built-in catalogue is seeded ACTIVE, so the patient app offers exactly
 * the same list as before until an admin changes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medical_specialties', function (Blueprint $table): void {
            $table->id();
            // Stable key stored on doctor profiles (doctor_profiles.specialty_code)
            // and sent by the patient app's filter. Never renamed after creation.
            $table->string('code', 120)->unique();
            $table->string('label_fr', 100);
            $table->string('label_ar', 100)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        $arabic = SpecialtyArabicLabels::map();
        $now = now();

        DB::table('medical_specialties')->insert(
            collect(MedicalSpecialtyCatalog::BUILT_IN_LABELS)
                ->map(static fn (string $labelFr, string $code): array => [
                    'code' => $code,
                    'label_fr' => $labelFr,
                    'label_ar' => $arabic[$code] ?? null,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->values()
                ->all(),
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_specialties');
    }
};
