<?php

namespace App\Http\Resources\Mobile\Admin;

use App\Models\Cabinet;
use App\Models\DoctorProfile;
use App\Models\Wilaya;
use App\Support\MedicalSpecialtyCatalog;
use App\Support\SpecialtyArabicLabels;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full platform view of one clinic: identity, lifecycle, directory
 * visibility, owner contact, doctor, live counters and licence.
 *
 * Nothing secret is ever serialised here — no password hash, no API token, no
 * signed certificate, no PIN digest, and no licence code. The collaborators
 * are injected rather than lazily loaded so the controller stays the single
 * place that decides which cross-tenant queries run.
 *
 * @property Cabinet $resource
 */
class AdminCabinetResource extends JsonResource
{
    /**
     * @param  array{staff: int, patients: int, appointments: int}  $counts
     */
    public function __construct(
        Cabinet $cabinet,
        private readonly ?Wilaya $wilaya,
        private readonly ?DoctorProfile $doctor,
        private readonly bool $isListed,
        private readonly array $counts,
    ) {
        parent::__construct($cabinet);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $license = $this->resource->license;

        return [
            'id' => $this->resource->getKey(),
            'name' => $this->resource->name,
            'status' => $this->resource->status->value,
            'status_label' => $this->resource->status->label(),
            'specialization' => $this->resource->specialization,
            'facility_type' => $this->resource->facility_type->value,
            'facility_type_label' => $this->resource->facility_type->label(),
            'facility_type_label_ar' => $this->resource->facility_type->labelAr(),
            'wilaya' => $this->wilayaPayload(),
            'created_at' => $this->resource->created_at?->toIso8601String(),
            'activated_at' => $this->resource->activated_at?->toIso8601String(),
            'is_listed' => $this->isListed,
            'owner' => $this->ownerPayload(),
            'doctor' => $this->doctorPayload(),
            'counts' => [
                'staff' => $this->counts['staff'],
                'patients' => $this->counts['patients'],
                'appointments' => $this->counts['appointments'],
            ],
            // Every account attached to the clinic, pending ones included,
            // occupies a seat; "staff" above is that same count.
            'seats' => [
                'limit' => $this->resource->seatLimit(),
                'used' => $this->counts['staff'],
                'price' => $this->resource->seat_price,
            ],
            'license' => $license === null ? null : [
                'plan' => $license->plan?->value,
                'plan_label' => $license->typeLabel(),
                'status' => $license->effectiveStatus(),
                'expires_at' => $license->expires_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * @return array{code: int, name_fr: string, name_ar: string}|null
     */
    private function wilayaPayload(): ?array
    {
        if ($this->wilaya === null) {
            return null;
        }

        return [
            'code' => $this->wilaya->code,
            'name_fr' => $this->wilaya->name_fr,
            'name_ar' => $this->wilaya->name_ar,
        ];
    }

    /**
     * @return array{id: int, name: string, email: string|null, phone: string|null}|null
     */
    private function ownerPayload(): ?array
    {
        $owner = $this->resource->owner;

        if ($owner === null) {
            return null;
        }

        return [
            'id' => $owner->getKey(),
            'name' => $owner->name,
            'email' => $owner->email,
            // Registration — web and admin alike — records the clinic phone on
            // the doctor profile rather than on the account, so fall back to it
            // instead of showing the admin an empty contact field.
            'phone' => $owner->phone ?? $this->doctor?->phone,
        ];
    }

    /**
     * @return array{id: int, name: string|null, specialty: array{code: string, label_fr: string, label_ar: string}|null}|null
     */
    private function doctorPayload(): ?array
    {
        if ($this->doctor === null) {
            return null;
        }

        $code = $this->doctor->specialty_code;
        $specialty = null;

        if ($code !== null) {
            $labelFr = app(MedicalSpecialtyCatalog::class)->display($this->doctor->specialty, $code);

            $specialty = [
                'code' => $code,
                'label_fr' => $labelFr,
                'label_ar' => SpecialtyArabicLabels::labelFor($code) ?? $labelFr,
            ];
        }

        return [
            'id' => $this->doctor->getKey(),
            'name' => $this->doctor->doctor_name,
            'specialty' => $specialty,
        ];
    }
}
