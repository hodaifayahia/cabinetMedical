<?php

namespace App\Services\Sync;

use App\Enums\Gender;
use App\Models\Patient;
use Illuminate\Support\Str;

/**
 * Maps a patient identity published by another installation onto a patient row
 * in *this* database.
 *
 * The rule this implements: an appointment synced in from the mobile app must
 * attach to the patient this installation already holds, keeping the local
 * primary key. A new patient is created only when no local record describes the
 * same person.
 *
 * Matching runs strongest-first:
 *
 *   1. `public_id` — the shared cross-installation identity. Exact, and the
 *      only match that is certain.
 *   2. `patient_number` — the cabinet's own dossier number. Unique per
 *      installation and stable, so it is a reliable second key.
 *   3. Normalised phone **and** first name **and** last name together. All
 *      three must agree; a phone number alone is not enough, because families
 *      routinely share one.
 *
 * Anything weaker is deliberately not attempted. In a medical record a false
 * merge — one person's appointments landing on another person's file — is far
 * more damaging than a duplicate the clinic can merge by hand later.
 */
final class PatientResolver
{
    /** Trailing digits compared when matching a phone number. */
    private const COMPARABLE_PHONE_DIGITS = 9;

    /**
     * Resolve, adopt, or create the local patient for a remote identity.
     *
     * @param  array<string, mixed>|null  $identity  The `patient` block of a sync payload.
     */
    public function resolve(int $cabinetId, ?array $identity): ?Patient
    {
        if ($identity === null) {
            return null;
        }

        $publicId = $this->cleanString($identity['public_id'] ?? null);

        if ($publicId !== null) {
            $byPublicId = Patient::withoutCabinetScope()
                ->withTrashed()
                ->where('cabinet_id', $cabinetId)
                ->where('public_id', $publicId)
                ->first();

            if ($byPublicId instanceof Patient) {
                // An incoming appointment is evidence the patient is active
                // again, and the identity match is exact, so restoring is safe.
                if ($byPublicId->trashed()) {
                    $byPublicId->restore();
                }

                return $byPublicId;
            }
        }

        $match = $this->matchByNaturalKey($cabinetId, $identity);

        if ($match instanceof Patient) {
            $this->adoptPublicId($match, $publicId);

            return $match;
        }

        return $this->create($cabinetId, $identity, $publicId);
    }

    /**
     * @param  array<string, mixed>  $identity
     */
    private function matchByNaturalKey(int $cabinetId, array $identity): ?Patient
    {
        // Deliberately excludes soft-deleted rows. A clinic that archived a
        // patient should not have that decision silently reversed by a
        // heuristic match; only an exact `public_id` may restore.
        $patientNumber = $this->cleanString($identity['patient_number'] ?? null);

        if ($patientNumber !== null) {
            $byNumber = Patient::withoutCabinetScope()
                ->where('cabinet_id', $cabinetId)
                ->where('patient_number', $patientNumber)
                ->first();

            if ($byNumber instanceof Patient) {
                return $byNumber;
            }
        }

        $phone = $this->normalisePhone($identity['phone'] ?? null);
        $firstName = $this->normaliseName($identity['first_name'] ?? null);
        $lastName = $this->normaliseName($identity['last_name'] ?? null);

        if ($phone === null || $firstName === null || $lastName === null) {
            return null;
        }

        // The names narrow the search in SQL (both columns are indexed), so
        // only a handful of rows reach PHP. Phone formatting varies between the
        // desktop and the mobile app, so that comparison is done on normalised
        // values here rather than in the query.
        return Patient::withoutCabinetScope()
            ->where('cabinet_id', $cabinetId)
            ->whereNotNull('phone')
            ->whereRaw('lower(first_name) = ?', [$firstName])
            ->whereRaw('lower(last_name) = ?', [$lastName])
            ->get()
            ->first(fn (Patient $candidate): bool => $this->normalisePhone($candidate->phone) === $phone);
    }

    /**
     * Converge both installations on one identity so later syncs match in O(1).
     *
     * Only ever fills a gap or replaces a value no other local patient holds;
     * the unique index is the final guard.
     */
    private function adoptPublicId(Patient $patient, ?string $publicId): void
    {
        if ($publicId === null || $patient->public_id === $publicId) {
            return;
        }

        // `patients.public_id` is unique across the whole database, not per
        // cabinet, so availability must be checked globally.
        $taken = Patient::withoutCabinetScope()
            ->withTrashed()
            ->where('public_id', $publicId)
            ->whereKeyNot($patient->getKey())
            ->exists();

        if ($taken) {
            return;
        }

        $patient->forceFill(['public_id' => $publicId])->saveQuietly();
    }

    /**
     * @param  array<string, mixed>  $identity
     */
    private function create(int $cabinetId, array $identity, ?string $publicId): Patient
    {
        $patient = new Patient;
        $patient->forceFill([
            'cabinet_id' => $cabinetId,
            // Adopt the remote identity when it is free so both sides converge
            // immediately; otherwise the model mints a fresh one on create.
            'public_id' => $this->availablePublicId($publicId),
            'patient_number' => $this->availablePatientNumber(
                $cabinetId,
                $this->cleanString($identity['patient_number'] ?? null),
            ),
            'first_name' => $this->cleanString($identity['first_name'] ?? null) ?? 'Patient',
            'last_name' => $this->cleanString($identity['last_name'] ?? null) ?? 'Inconnu',
            'date_of_birth' => $this->cleanString($identity['date_of_birth'] ?? null),
            'gender' => $this->gender($identity['gender'] ?? null),
            'phone' => $this->cleanString($identity['phone'] ?? null),
            'email' => $this->cleanString($identity['email'] ?? null),
        ]);
        $patient->save();

        return $patient;
    }

    /**
     * `patients.public_id` is unique database-wide, so a value already held by
     * any patient — including one in another cabinet — cannot be reused. In
     * that case the model mints a fresh identity on create.
     */
    private function availablePublicId(?string $publicId): ?string
    {
        if ($publicId === null) {
            return null;
        }

        $taken = Patient::withoutCabinetScope()
            ->withTrashed()
            ->where('public_id', $publicId)
            ->exists();

        return $taken ? null : $publicId;
    }

    /**
     * Dossier numbers are unique per installation. A remote number that already
     * belongs to a different local patient is dropped so the model generates a
     * fresh one instead of failing the whole sync run on a constraint.
     */
    private function availablePatientNumber(int $cabinetId, ?string $patientNumber): ?string
    {
        if ($patientNumber === null) {
            return null;
        }

        $taken = Patient::withoutCabinetScope()
            ->withTrashed()
            ->where('cabinet_id', $cabinetId)
            ->where('patient_number', $patientNumber)
            ->exists();

        return $taken ? null : $patientNumber;
    }

    /**
     * Only a value this installation understands is stored. An unknown gender
     * from a newer client is dropped rather than failing the whole sync run on
     * an enum cast.
     */
    private function gender(mixed $value): ?Gender
    {
        $gender = $this->cleanString($value);

        return $gender === null ? null : Gender::tryFrom($gender);
    }

    private function cleanString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Reduce a phone number to a comparable subscriber number, so `05 51 22 33
     * 44`, `0551223344`, and `+213 551 22 33 44` are recognised as one person
     * rather than three.
     *
     * Only the last nine digits are compared: that drops a national trunk `0`
     * and any country prefix without needing to know which country the number
     * belongs to. Shorter numbers are compared whole. A collision would still
     * have to coincide with an exact first- and last-name match before two
     * records are treated as the same person.
     */
    private function normalisePhone(mixed $value): ?string
    {
        $phone = $this->cleanString($value);

        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if ($digits === '') {
            return null;
        }

        return mb_strlen($digits) > self::COMPARABLE_PHONE_DIGITS
            ? mb_substr($digits, -self::COMPARABLE_PHONE_DIGITS)
            : $digits;
    }

    private function normaliseName(mixed $value): ?string
    {
        $name = $this->cleanString($value);

        if ($name === null) {
            return null;
        }

        return Str::lower(preg_replace('/\s+/u', ' ', $name) ?? $name);
    }
}
