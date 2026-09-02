<?php

namespace App\Support\Appointments;

use App\Enums\FamilyRelation;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\FamilyMember;
use App\Models\Patient;
use App\Models\PatientProfile;
use App\Models\User;
use App\Services\Appointments\AppointmentSyncService;
use Throwable;

/**
 * The read side of an appointment's booking provenance: the channel it came
 * in through, the person the visit is for, and the account reception would
 * call about it.
 *
 * Two surfaces need the same three facts and neither can reach the other: the
 * staff {@see AppointmentResource} and the desktop appointment book's Inertia
 * payload. Both come through here so a hosted appointment (live foreign keys,
 * no stored context) and the imported copy of it on the doctor's desktop
 * (foreign keys null, `booking_context` populated) read identically to a
 * human.
 *
 * The shape is delegated to
 * {@see AppointmentSyncService::normalisedBookingProvenance()} — the same
 * normaliser the sync payload uses — so a surface can never present a block
 * the wire format would not recognise, and `relation` stays the enum VALUE
 * (`son`, never `Fils`): each surface owns its own copy.
 */
final class BookingProvenance
{
    /**
     * The canonical block for one appointment, or null when it carries no
     * provenance at all — which is every staff-created appointment.
     *
     * @return array<string, mixed>|null
     */
    public static function for(Appointment $appointment): ?array
    {
        $normaliser = app(AppointmentSyncService::class);

        try {
            // An imported appointment has null foreign keys and only the
            // context the sending installation shipped, so the stored block
            // is a fallback rather than an exception.
            return $normaliser->normalisedBookingProvenance(self::fromRelations($appointment))
                ?? $normaliser->normalisedBookingProvenance($appointment->booking_context);
        } catch (Throwable) {
            // A deleted family row or an unreadable JSON column costs a line
            // of provenance; it must never cost the agenda its page render.
            return null;
        }
    }

    /**
     * Build the block from this installation's own foreign keys.
     *
     * @return array<string, mixed>|null
     */
    private static function fromRelations(Appointment $appointment): ?array
    {
        if ($appointment->booking_channel === null
            && $appointment->booked_by_user_id === null
            && $appointment->family_member_id === null) {
            return null;
        }

        return [
            'channel' => $appointment->booking_channel,
            'booked_for' => self::bookedFor($appointment),
            'booked_by' => self::bookedBy($appointment),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function bookedFor(Appointment $appointment): array
    {
        $patientName = self::trimmed($appointment->patient?->full_name);

        if ($appointment->family_member_id === null) {
            return self::visitIsForTheAccountHolder($appointment)
                ? ['type' => 'self', 'name' => $patientName, 'relation' => null]
                // The column was nulled by the family member's deletion, not
                // by the booking. The relation is unrecoverable, but the
                // dossier still names the right person and the visit was
                // never the account holder's own.
                : ['type' => 'family', 'name' => $patientName, 'relation' => null];
        }

        $member = $appointment->familyMember;

        if (! $member instanceof FamilyMember) {
            // The family row is gone, but the visit is still not the account
            // holder's own: calling it 'self' would misname the patient.
            return ['type' => 'family', 'name' => $patientName, 'relation' => null];
        }

        $relation = $member->relation;

        return [
            'type' => 'family',
            // A member linked to another account carries no inline names; the
            // cabinet's own dossier holds the copy made at booking time.
            'name' => self::personName($member->first_name, $member->last_name) ?? $patientName,
            'relation' => $relation instanceof FamilyRelation ? $relation->value : null,
        ];
    }

    /**
     * Whether a null `family_member_id` really means "the account holder's
     * own visit".
     *
     * `appointments.family_member_id` is `nullOnDelete` and `FamilyMember`
     * has no soft deletes, so removing a member wipes the column on every
     * appointment ever booked for them. Reading that null as "self" would
     * re-attribute the visit to the account holder on the agenda and in the
     * staff API. The cabinet's own dossier survives the delete and answers
     * honestly: `PatientBookingService::resolvePatientRow()` keys a self
     * booking's patient by `patient_user_id`, so a dossier that is not the
     * booking account's own means the visit never was either. This mirrors
     * {@see AppointmentSyncService} so both surfaces read a degraded row the
     * same way.
     */
    private static function visitIsForTheAccountHolder(Appointment $appointment): bool
    {
        $bookedByUserId = $appointment->booked_by_user_id;

        if ($bookedByUserId === null) {
            return true;
        }

        $patient = $appointment->patient;

        if (! $patient instanceof Patient) {
            return true;
        }

        $patientUserId = $patient->patient_user_id;

        return $patientUserId !== null && (int) $patientUserId === (int) $bookedByUserId;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function bookedBy(Appointment $appointment): ?array
    {
        $user = $appointment->bookedBy;

        if (! $user instanceof User) {
            return null;
        }

        $profile = $user->patientProfile;
        $name = ($profile instanceof PatientProfile
            ? self::personName($profile->first_name, $profile->last_name)
            : null) ?? self::personName($user->name, null);

        return ['name' => $name, 'phone' => self::trimmed($user->phone)];
    }

    private static function personName(mixed $first, mixed $last): ?string
    {
        return self::trimmed(sprintf(
            '%s %s',
            is_string($first) ? $first : '',
            is_string($last) ? $last : '',
        ));
    }

    private static function trimmed(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
