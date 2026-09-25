<?php

namespace App\Support;

use App\Enums\CabinetStatus;
use Illuminate\Support\Facades\DB;

/**
 * How many doctors a patient could reach through each specialty filter:
 * active doctors of active, listed practices, keyed by CANONICAL code — a
 * profile stored as "pediatrie" counts towards "pediatrics", exactly as the
 * directory's specialty filter matches it (MedicalSpecialtyCatalog::matchingCodes).
 */
final class SpecialtyDoctorCounts
{
    /**
     * @return array<string, int>
     */
    public function listed(): array
    {
        $counts = [];

        $rows = DB::table('doctor_profiles')
            ->join('cabinets', 'cabinets.id', '=', 'doctor_profiles.cabinet_id')
            ->join('cabinet_public_profiles', 'cabinet_public_profiles.cabinet_id', '=', 'doctor_profiles.cabinet_id')
            ->where('doctor_profiles.is_active', true)
            ->where('cabinets.status', CabinetStatus::ACTIVE->value)
            ->where('cabinet_public_profiles.is_listed', true)
            ->whereNotNull('doctor_profiles.specialty_code')
            ->groupBy('doctor_profiles.specialty_code')
            ->selectRaw('doctor_profiles.specialty_code as code, count(*) as total')
            ->get();

        foreach ($rows as $row) {
            $code = SpecialtyArabicLabels::canonicalCode((string) $row->code) ?? (string) $row->code;
            $counts[$code] = ($counts[$code] ?? 0) + (int) $row->total;
        }

        return $counts;
    }
}
