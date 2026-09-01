<?php

namespace App\Services\Mobile;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\CabinetPublicProfile;
use App\Models\DoctorProfile;
use App\Models\FamilyMember;
use App\Models\Patient;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Books appointments on behalf of mobile patient accounts.
 *
 * Patient users carry cabinet_id = null, which leaves the BelongsToCabinet
 * global scope inert: every query here is therefore explicit about tenancy
 * (withoutCabinetScope + explicit cabinet_id) and about ownership, and every
 * created row receives its cabinet_id explicitly because the trait's creating
 * hook cannot infer a cabinet from a null-cabinet session.
 */
class PatientBookingService
{
    public function __construct(private readonly PublicAvailabilityService $availability) {}

    /**
     * Resolve a doctor a mobile patient is allowed to book with: an active
     * profile, in an active cabinet, whose public profile is listed. Null
     * means "not discoverable" and callers answer 404.
     */
    public function resolveBookableDoctor(int $doctorProfileId): ?DoctorProfile
    {
        /** @var DoctorProfile|null $doctor */
        $doctor = DoctorProfile::withoutCabinetScope()
            ->with('cabinet.settings')
            ->whereKey($doctorProfileId)
            ->where('is_active', true)
            ->first();

        if ($doctor === null || $doctor->cabinet === null || ! $doctor->cabinet->isActive()) {
            return null;
        }

        $isListed = CabinetPublicProfile::withoutCabinetScope()
            ->where('cabinet_id', $this->cabinetIdOf($doctor))
            ->where('is_listed', true)
            ->exists();

        return $isListed ? $doctor : null;
    }

    /**
     * Book a slot for the authenticated patient, or for one of their usable
     * family members. Returns null when the slot is no longer available so
     * the controller can answer 409 slot_unavailable.
     */
    public function book(
        User $user,
        DoctorProfile $doctor,
        CarbonImmutable $startsAt,
        ?FamilyMember $familyMember = null,
        ?string $reason = null,
    ): ?Appointment {
        // Defense in depth: a Carbon carrying a foreign offset would shift the
        // slot grid and be stored verbatim in the naive datetime column.
        $startsAt = $startsAt->setTimezone(config('app.timezone'));

        return DB::transaction(function () use ($user, $doctor, $startsAt, $familyMember, $reason): ?Appointment {
            // Serialize concurrent bookings for this cabinet by locking its
            // single doctor row — the same lock CreateAppointmentAction takes
            // on the staff path — so two check-then-insert flows (patient vs
            // patient, or patient vs staff) cannot both see the slot as free.
            // A transaction alone would not serialize the plain SELECT below.
            // SQLite serializes writers on its own and rejects FOR UPDATE.
            if (DB::connection()->getDriverName() !== 'sqlite') {
                DoctorProfile::withoutCabinetScope()
                    ->whereKey($doctor->getKey())
                    ->lockForUpdate()
                    ->first();
            }

            if (! $this->availability->isSlotAvailable($doctor, $startsAt)) {
                return null;
            }

            $endsAt = $startsAt->addMinutes($this->availability->slotDurationFor($doctor, $startsAt));

            $patient = $this->resolvePatientRow($user, $doctor, $familyMember);

            $appointment = new Appointment;
            $appointment->fill([
                'patient_id' => $patient->getKey(),
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'status' => AppointmentStatus::SCHEDULED,
                'reason' => $reason,
                'created_by' => $user->getKey(),
            ]);

            // Deliberately not mass assignable: tenancy and booking channel
            // are always set by code, never by client payloads.
            $appointment->setAttribute('cabinet_id', $this->cabinetIdOf($doctor));
            $appointment->setAttribute('booked_by_user_id', $user->getKey());
            $appointment->setAttribute('family_member_id', $familyMember?->getKey());
            $appointment->setAttribute('booking_channel', 'mobile_patient');
            $appointment->save();

            return $appointment;
        });
    }

    /**
     * Find or create the cabinet-side dossier for the person the appointment
     * is for: keyed by (cabinet_id, patient_user_id) for self bookings and by
     * (cabinet_id, family_member_id) for family bookings. Demographics are
     * copied only on first creation so later staff edits are never clobbered.
     */
    private function resolvePatientRow(User $user, DoctorProfile $doctor, ?FamilyMember $familyMember): Patient
    {
        $keys = $familyMember === null
            ? ['cabinet_id' => $this->cabinetIdOf($doctor), 'patient_user_id' => $user->getKey()]
            : ['cabinet_id' => $this->cabinetIdOf($doctor), 'family_member_id' => $familyMember->getKey()];

        /** @var Patient $patient */
        $patient = Patient::withoutCabinetScope()->firstOrNew($keys);

        if (! $patient->exists) {
            $patient->fill($familyMember === null
                ? $this->demographicsForSelf($user)
                : $this->demographicsForFamilyMember($user, $familyMember));

            // The identity keys are intentionally not fillable; firstOrNew
            // discards them from the fresh instance, so set them explicitly.
            foreach ($keys as $column => $value) {
                $patient->setAttribute($column, $value);
            }

            // patient_number and public_id are assigned by the Patient
            // model's creating hook (GeneratePatientNumberAction).
            $patient->save();
        }

        return $patient;
    }

    /**
     * @return array<string, mixed>
     */
    private function demographicsForSelf(User $user): array
    {
        $profile = $user->patientProfile;

        return [
            'first_name' => $profile->first_name ?? $user->name,
            'last_name' => $profile->last_name ?? '',
            'gender' => $profile?->gender?->value,
            'date_of_birth' => $profile?->date_of_birth,
            'place_of_birth' => $profile?->place_of_birth,
            'wilaya_code' => $profile?->wilaya_code,
            'baladiya_id' => $profile?->baladiya_id,
            'phone' => $user->phone,
            'email' => $user->email,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function demographicsForFamilyMember(User $owner, FamilyMember $familyMember): array
    {
        if ($familyMember->isDependent()) {
            return [
                'first_name' => $familyMember->first_name ?? '',
                'last_name' => $familyMember->last_name ?? '',
                'gender' => $familyMember->gender?->value,
                'date_of_birth' => $familyMember->date_of_birth,
                'place_of_birth' => $familyMember->place_of_birth,
                'wilaya_code' => $familyMember->wilaya_code,
                'baladiya_id' => $familyMember->baladiya_id,
                // Dependents have no account of their own: the cabinet
                // reaches them through the owner's phone.
                'phone' => $owner->phone,
            ];
        }

        $linkedUser = $familyMember->linkedUser;
        $profile = $linkedUser?->patientProfile;

        return [
            'first_name' => $profile->first_name ?? $linkedUser->name ?? '',
            'last_name' => $profile->last_name ?? '',
            'gender' => $profile?->gender?->value,
            'date_of_birth' => $profile?->date_of_birth,
            'place_of_birth' => $profile?->place_of_birth,
            'wilaya_code' => $profile?->wilaya_code,
            'baladiya_id' => $profile?->baladiya_id,
            'phone' => $linkedUser->phone ?? $owner->phone,
        ];
    }

    /**
     * The doctor's tenant id as a concrete int. DoctorProfile does not
     * declare the cabinet_id property, so read it through the attribute bag.
     */
    private function cabinetIdOf(DoctorProfile $doctor): int
    {
        return (int) $doctor->getAttribute('cabinet_id');
    }
}
