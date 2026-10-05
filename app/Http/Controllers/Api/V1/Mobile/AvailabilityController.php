<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Enums\CabinetStatus;
use App\Http\Controllers\Controller;
use App\Models\Cabinet;
use App\Models\CabinetPublicProfile;
use App\Models\DoctorProfile;
use App\Services\Mobile\PublicAvailabilityService;
use App\Support\FacilityTypeAvailability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public availability calendar of a listed doctor. Mirrors the staff
 * month/day payload shapes, minus the staff-only appointments array of the
 * day view.
 */
class AvailabilityController extends Controller
{
    public function __construct(private readonly PublicAvailabilityService $availability) {}

    public function month(Request $request, int $doctorProfile): JsonResponse
    {
        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $doctor = $this->visibleDoctor($doctorProfile);

        return response()->json($this->availability->monthForDoctor(
            $doctor,
            (int) $validated['year'],
            (int) $validated['month'],
        ));
    }

    public function day(Request $request, int $doctorProfile): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        $doctor = $this->visibleDoctor($doctorProfile);

        return response()->json($this->availability->dayForDoctor($doctor, $validated['date']));
    }

    /**
     * Resolve a doctor the public may see — an active profile in an active,
     * listed cabinet — and 404 otherwise, the same rule as discovery.
     */
    private function visibleDoctor(int $doctorProfileId): DoctorProfile
    {
        /** @var DoctorProfile|null $doctor */
        $doctor = DoctorProfile::withoutCabinetScope()
            ->whereKey($doctorProfileId)
            ->where('is_active', true)
            ->first();

        if ($doctor === null || ! $this->clinicIsVisible($doctor)) {
            abort(404, 'Médecin introuvable.');
        }

        return $doctor;
    }

    private function clinicIsVisible(DoctorProfile $doctor): bool
    {
        $cabinetId = $doctor->getAttribute('cabinet_id');
        $disabledTypes = app(FacilityTypeAvailability::class)->disabledValues();

        return Cabinet::query()
            ->whereKey($cabinetId)
            ->where('status', CabinetStatus::ACTIVE->value)
            ->when(
                $disabledTypes !== [],
                static fn ($query) => $query->whereNotIn('facility_type', $disabledTypes),
            )
            ->exists()
            && CabinetPublicProfile::withoutCabinetScope()
                ->where('cabinet_id', $cabinetId)
                ->where('is_listed', true)
                ->exists();
    }
}
