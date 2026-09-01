<?php

namespace App\Notifications\Mobile;

use App\Models\Appointment;
use Illuminate\Notifications\Notification;

/**
 * In-app notification telling a mobile user that an appointment's status
 * changed: the booking patient when the clinic acted, or the cabinet owner
 * when the patient acted. Database channel only in Phase 1 — push delivery
 * (Expo) ships in a later phase using the stored device tokens.
 */
class AppointmentStatusChanged extends Notification
{
    public function __construct(
        private readonly Appointment $appointment,
        private readonly string $changedByRole,
        private readonly ?string $doctorName = null,
        private readonly ?string $clinicName = null,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'appointment_public_id' => $this->appointment->public_id,
            'status' => $this->appointment->status->value,
            'starts_at' => $this->appointment->starts_at?->toIso8601String(),
            'doctor_name' => $this->doctorName,
            'clinic_name' => $this->clinicName,
            'changed_by_role' => $this->changedByRole,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
