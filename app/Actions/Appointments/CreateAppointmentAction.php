<?php

namespace App\Actions\Appointments;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\DoctorProfile;
use App\Models\Patient;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateAppointmentAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Patient $patient, User $createdBy, array $attributes): Appointment
    {
        if ($patient->trashed()) {
            throw ValidationException::withMessages([
                'patient' => 'Soft-deleted patients cannot receive new appointments.',
            ]);
        }

        $startsAt = $this->normalizeDateTime($attributes['starts_at'] ?? null, 'starts_at');
        $endsAt = $this->normalizeDateTime($attributes['ends_at'] ?? null, 'ends_at');
        $status = $this->resolveStatus($attributes['status'] ?? null);

        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            throw ValidationException::withMessages([
                'ends_at' => 'The appointment end time must be after the start time.',
            ]);
        }

        return DB::transaction(function () use ($attributes, $createdBy, $endsAt, $patient, $startsAt, $status): Appointment {
            // Serialize concurrent bookings by locking the single active doctor row.
            $doctorQuery = DoctorProfile::query()->active();

            if (DB::connection()->getDriverName() !== 'sqlite') {
                $doctorQuery->lockForUpdate();
            }

            $doctor = $doctorQuery->first();

            if (! $doctor instanceof DoctorProfile) {
                throw ValidationException::withMessages([
                    'doctor' => 'No active doctor is configured for the cabinet.',
                ]);
            }

            $hasConflict = Appointment::query()
                ->whereIn('status', AppointmentStatus::blockingValues())
                ->where('starts_at', '<', $endsAt)
                ->where('ends_at', '>', $startsAt)
                ->exists();

            if ($hasConflict) {
                throw ValidationException::withMessages([
                    'starts_at' => 'There is already an overlapping active appointment for the selected time range.',
                ]);
            }

            return Appointment::query()->create([
                'patient_id' => $patient->getKey(),
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'status' => $status,
                'reason' => $attributes['reason'] ?? null,
                'reception_notes' => $attributes['reception_notes'] ?? null,
                'prestation' => $attributes['prestation'] ?? null,
                'created_by' => $createdBy->getKey(),
                'confirmed_at' => $status === AppointmentStatus::CONFIRMED ? now() : null,
                'mobile_idempotency_key_hash' => $attributes['mobile_idempotency_key_hash'] ?? null,
                'mobile_idempotency_fingerprint' => $attributes['mobile_idempotency_fingerprint'] ?? null,
            ]);
        });
    }

    private function normalizeDateTime(mixed $value, string $field): CarbonImmutable
    {
        if ($value instanceof CarbonImmutable) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance(DateTimeImmutable::createFromInterface($value));
        }

        if (is_string($value) && $value !== '') {
            return CarbonImmutable::parse($value);
        }

        throw ValidationException::withMessages([
            $field => sprintf('The %s field must be a valid date and time.', $field),
        ]);
    }

    private function resolveStatus(mixed $value): AppointmentStatus
    {
        if ($value === null) {
            return AppointmentStatus::SCHEDULED;
        }

        $status = match (true) {
            $value instanceof AppointmentStatus => $value,
            is_string($value) => AppointmentStatus::tryFrom($value),
            default => null,
        };

        if (! $status instanceof AppointmentStatus || ! in_array($status->value, AppointmentStatus::creatableValues(), true)) {
            throw ValidationException::withMessages([
                'status' => 'Appointments may only be created in a scheduled or confirmed state.',
            ]);
        }

        return $status;
    }
}
