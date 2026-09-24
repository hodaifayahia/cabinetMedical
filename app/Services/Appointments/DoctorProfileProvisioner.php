<?php

namespace App\Services\Appointments;

use App\Enums\RoleName;
use App\Enums\Weekday;
use App\Models\AuditLog;
use App\Models\Cabinet;
use App\Models\DoctorProfile;
use App\Models\DoctorSchedule;
use App\Models\User;
use App\Support\MedicalSpecialtyCatalog;
use Illuminate\Support\Facades\DB;

/**
 * Gives a cabinet the doctor profile booking depends on when it has none.
 *
 * Sign-up creates the profile with the cabinet, but cabinets created before
 * that existed have no profile, and nothing else in the app can create one:
 * the agenda then shows "Aucun médecin actif" forever. This repairs them with
 * the same defaults sign-up uses (Monday–Friday, 09:00–17:00), which the
 * doctor adjusts on the availability screen.
 *
 * A cabinet whose doctor profile was deliberately deactivated is left alone.
 */
final class DoctorProfileProvisioner
{
    public function __construct(private readonly MedicalSpecialtyCatalog $specialties) {}

    public function ensureFor(?int $cabinetId): ?DoctorProfile
    {
        if ($cabinetId === null) {
            return null;
        }

        $profiles = DoctorProfile::withoutCabinetScope()->where('cabinet_id', $cabinetId);

        if ((clone $profiles)->exists()) {
            return (clone $profiles)->where('is_active', true)->orderBy('id')->first();
        }

        $cabinet = Cabinet::query()->find($cabinetId);
        $doctor = $cabinet instanceof Cabinet ? $this->doctorOf($cabinet) : null;

        if (! $cabinet instanceof Cabinet || ! $doctor instanceof User) {
            return null;
        }

        return DB::transaction(function () use ($cabinet, $doctor): DoctorProfile {
            $duration = (int) config('clinic.appointments.default_duration', 30);
            // The cabinet's own specialty when it has one; the doctor can
            // correct it later on the clinic identity screen.
            $specialty = trim((string) ($cabinet->specialization ?? '')) ?: $this->specialties->display(null, 'general_medicine');

            $profile = new DoctorProfile([
                'user_id' => $doctor->getKey(),
                'doctor_name' => $doctor->name,
                'specialty' => $this->specialties->display($specialty),
                'specialty_code' => $this->specialties->codeFor($specialty),
                'clinic_name' => $cabinet->name,
                'phone' => $doctor->phone,
                'email' => $doctor->email,
                'consultation_duration' => $duration,
                'consultation_fee_minor' => 0,
                'is_active' => true,
            ]);
            $profile->forceFill(['cabinet_id' => $cabinet->getKey()])->save();

            foreach ([Weekday::MONDAY, Weekday::TUESDAY, Weekday::WEDNESDAY, Weekday::THURSDAY, Weekday::FRIDAY] as $day) {
                $schedule = new DoctorSchedule([
                    'doctor_id' => $profile->getKey(),
                    'day_of_week' => $day->value,
                    'starts_at' => '09:00:00',
                    'ends_at' => '17:00:00',
                    'slot_duration' => $duration,
                    'is_active' => true,
                ]);
                $schedule->forceFill(['cabinet_id' => $cabinet->getKey()])->save();
            }

            AuditLog::record('doctor_profile.provisioned', $profile, [
                'reason' => 'cabinet_without_doctor_profile',
                'user_id' => $doctor->getKey(),
            ]);

            return $profile;
        });
    }

    /**
     * The cabinet's owner when they are a doctor, otherwise its first doctor.
     */
    private function doctorOf(Cabinet $cabinet): ?User
    {
        $doctors = User::query()
            ->where('cabinet_id', $cabinet->getKey())
            ->whereHas('roles', static fn ($query) => $query->where('name', RoleName::DOCTOR->value))
            ->orderBy('id')
            ->get();

        return $doctors->firstWhere('id', $cabinet->owner_user_id) ?? $doctors->first();
    }
}
