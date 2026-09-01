<?php

namespace App\Http\Resources\Mobile;

use App\Models\Appointment;
use App\Models\CabinetPublicProfile;
use App\Models\DoctorProfile;
use App\Support\MedicalSpecialtyCatalog;
use App\Support\SpecialtyArabicLabels;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The patient-facing appointment payload. Deliberately narrower than the
 * staff AppointmentResource: no reception_notes, no sync fields, no internal
 * ids beyond the documented ones. The controller attaches the clinic context
 * (active doctor + public listing) as the `mobileDoctor` and
 * `mobilePublicProfile` relations before serialising.
 *
 * @mixin Appointment
 */
class MobileAppointmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $doctor = $this->contextRelation('mobileDoctor');
        $doctor = $doctor instanceof DoctorProfile ? $doctor : null;

        $publicProfile = $this->contextRelation('mobilePublicProfile');
        $publicProfile = $publicProfile instanceof CabinetPublicProfile ? $publicProfile : null;

        return [
            'public_id' => $this->public_id,
            'status' => $this->status->value,
            'appointment_date' => $this->appointment_date?->toDateString(),
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'reason' => $this->reason,
            'cancellation_reason' => $this->cancellation_reason,
            'booked_for' => [
                'type' => $this->family_member_id === null ? 'self' : 'family',
                'family_member_id' => $this->family_member_id,
                'name' => $this->patient?->full_name,
            ],
            'doctor' => $doctor === null ? null : [
                'id' => $doctor->getKey(),
                'name' => $doctor->doctor_name ?? $doctor->user?->name,
                'specialty' => $this->specialtyPayload($doctor),
            ],
            'clinic' => [
                'id' => $this->cabinet_id,
                'name' => $this->cabinet?->name,
                'address' => $publicProfile?->address,
                'phones' => $publicProfile->phones ?? [],
            ],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{code: string, label_fr: string, label_ar: string}|null
     */
    private function specialtyPayload(DoctorProfile $doctor): ?array
    {
        $catalog = app(MedicalSpecialtyCatalog::class);

        $code = $doctor->specialty_code
            ?? (filled($doctor->specialty) ? $catalog->codeFor((string) $doctor->specialty) : null);

        if ($code === null) {
            return null;
        }

        $labelFr = $catalog->display($doctor->specialty, $code);

        return [
            'code' => $code,
            'label_fr' => $labelFr,
            'label_ar' => SpecialtyArabicLabels::labelFor($code) ?? $labelFr,
        ];
    }

    /**
     * A context relation attached by the controller, or null when absent.
     */
    private function contextRelation(string $name): mixed
    {
        /** @var Appointment $appointment */
        $appointment = $this->resource;

        return $appointment->relationLoaded($name) ? $appointment->getRelation($name) : null;
    }
}
