<?php

namespace App\Http\Resources;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Appointment
 */
class AppointmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'public_id' => $this->public_id,
            'sync_version' => (int) $this->sync_version,
            'patient_id' => $this->patient_id,
            'patient' => new PatientResource($this->whenLoaded('patient')),
            'appointment_date' => $this->appointment_date?->toDateString(),
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'status' => $this->status->value,
            'reason' => $this->reason,
            'prestation' => $this->prestation,
            'reception_notes' => $this->reception_notes,
            'cancellation_reason' => $this->cancellation_reason,
            'can_confirm' => $this->status === AppointmentStatus::SCHEDULED,
            'can_check_in' => in_array($this->status, [AppointmentStatus::SCHEDULED, AppointmentStatus::CONFIRMED], true),
            'can_cancel' => ! in_array($this->status, [AppointmentStatus::COMPLETED, AppointmentStatus::CANCELLED, AppointmentStatus::NO_SHOW], true),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'checked_in_at' => $this->checked_in_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
