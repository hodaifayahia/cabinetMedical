<?php

namespace App\Services\Appointments;

use App\Enums\FamilyRelation;
use App\Models\Appointment;
use App\Models\AppointmentSyncEvent;
use App\Models\FamilyMember;
use App\Models\Patient;
use App\Models\PatientProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class AppointmentSyncService
{
    /**
     * The longest string the portable booking block carries.
     *
     * Generous next to anything the local producer can emit —
     * `booking_channel` is `varchar(20)` and the name columns are 100 —
     * precisely because that bound lives on the *sending* side, which an
     * importer must not trust. Without it a publisher with a corrupted or
     * hostile row could ship a half-megabyte block that this installation
     * stores in `booking_context`, copies into every subsequent
     * `appointment_sync_events.payload` row forever, renders into the agenda's
     * Inertia props, and pushes back 100 envelopes at a time until the POST
     * exceeds `post_max_size` and `push_cursor` stops advancing for good.
     */
    private const MAX_PORTABLE_STRING = 120;

    public function publishUpsert(Appointment $appointment): ?AppointmentSyncEvent
    {
        return $this->publish($appointment, 'upsert');
    }

    public function publishDeletion(Appointment $appointment): ?AppointmentSyncEvent
    {
        return $this->publish($appointment, 'delete');
    }

    /**
     * Requeue an unacknowledged snapshot, or publish a fresh version when the
     * mobile client already acknowledged the current one.
     */
    public function publishOrRetry(Appointment $appointment): ?AppointmentSyncEvent
    {
        if ($appointment->cabinet_id === null || $appointment->public_id === null) {
            return null;
        }

        return DB::transaction(function () use ($appointment): ?AppointmentSyncEvent {
            $query = Appointment::withoutCabinetScope()
                ->whereKey($appointment->getKey());

            if (DB::connection()->getDriverName() !== 'sqlite') {
                $query->lockForUpdate();
            }

            /** @var Appointment $locked */
            $locked = $query->firstOrFail();
            $latest = AppointmentSyncEvent::withoutCabinetScope()
                ->where('cabinet_id', $locked->cabinet_id)
                ->where('appointment_public_id', $locked->public_id)
                ->orderByDesc('version')
                ->first();
            $currentPayloadSha256 = $this->payloadSha256($locked);

            if ($latest !== null
                && $latest->version === $locked->sync_version
                && hash_equals($latest->payload_sha256, $currentPayloadSha256)
                && $latest->status !== AppointmentSyncEvent::STATUS_ACKNOWLEDGED) {
                $latest->update([
                    'status' => AppointmentSyncEvent::STATUS_PENDING,
                    'attempts' => $latest->attempts + 1,
                    'last_attempted_at' => now(),
                    'last_error' => null,
                ]);

                return $latest->fresh();
            }

            $locked->forceFill([
                'sync_version' => max(1, (int) $locked->sync_version) + 1,
            ])->saveQuietly();

            return $this->publishUpsert($locked->fresh());
        });
    }

    /**
     * Repair changes made through a bulk Eloquent update, which intentionally
     * bypasses model events. This keeps the cursor stream complete without
     * requiring consultation/payment controllers to know about sync internals.
     */
    public function reconcileRecent(int $limit = 500): void
    {
        Appointment::query()
            ->withTrashed()
            // `patientIdentity()` reads the patient with trashed rows
            // included. Eager-loading without them would yield a null identity
            // here, a different hash, and a needless republish on every poll
            // for every appointment whose patient has been archived.
            // The booking block resolves these too; without them a single
            // reconciliation pass would issue three extra queries per mobile
            // appointment just to decide nothing changed.
            ->with([
                'latestSyncEvent',
                'patient' => fn ($query) => $query->withTrashed(),
                'bookedBy.patientProfile',
                'familyMember',
            ])
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get()
            ->each(function (Appointment $appointment): void {
                $latest = $appointment->latestSyncEvent;

                if ($latest !== null
                    && hash_equals($latest->payload_sha256, $this->payloadSha256($appointment))) {
                    return;
                }

                $this->publishCurrentAsNewVersion($appointment);
            });
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(Appointment $appointment): array
    {
        $booking = $this->bookingProvenance($appointment);

        return [
            'public_id' => $appointment->public_id,
            'legacy_id' => (int) $appointment->getKey(),
            // `patient_id` is this installation's auto-increment key and is
            // meaningless anywhere else. `patient` carries the portable
            // identity a consumer needs to attach the appointment to its own
            // record, or to create it when the patient is not known yet.
            'patient_id' => (int) $appointment->patient_id,
            'patient' => $this->patientIdentity($appointment),
            // Same reasoning one level further: `booked_by_user_id` and
            // `family_member_id` are local auto-increment keys, so the
            // provenance travels as a portable block or not at all.
            //
            // The key is OMITTED rather than sent as null when there is
            // nothing to carry. `appointment_sync_events.payload_sha256` is a
            // hash of this array persisted across releases, and
            // `publishOrRetry()`, `reconcileRecent()` and
            // `publishCurrentAsNewVersion()` each compare a freshly computed
            // hash against a stored one. Emitting `"booking":null` would
            // change the encoding of every staff-created appointment ever
            // published, so the first sync after this deploy would
            // version-bump and republish up to 500 unchanged appointments per
            // poll, on both installations, inside the mixed-version window.
            // Omitting the key keeps them byte-identical to the previous
            // release and confines the churn to appointments that genuinely
            // gained provenance.
            ...($booking === null ? [] : ['booking' => $booking]),
            'appointment_date' => $appointment->appointment_date?->toDateString(),
            'starts_at' => $appointment->starts_at?->toIso8601String(),
            'ends_at' => $appointment->ends_at?->toIso8601String(),
            'status' => $appointment->status->value,
            'reason' => $appointment->reason,
            'prestation' => $appointment->prestation,
            'reception_notes' => $appointment->reception_notes,
            'cancellation_reason' => $appointment->cancellation_reason,
            'confirmed_at' => $appointment->confirmed_at?->toIso8601String(),
            'checked_in_at' => $appointment->checked_in_at?->toIso8601String(),
            'started_at' => $appointment->started_at?->toIso8601String(),
            'completed_at' => $appointment->completed_at?->toIso8601String(),
            'cancelled_at' => $appointment->cancelled_at?->toIso8601String(),
            'deleted_at' => $appointment->deleted_at?->toIso8601String(),
            'version' => (int) $appointment->sync_version,
            'created_at' => $appointment->created_at?->toIso8601String(),
            'updated_at' => $appointment->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The portable patient identity carried alongside every appointment.
     *
     * It is inlined so a consumer can resolve or create the patient without a
     * second round trip per appointment. Family identity appears only for
     * mobile-linked dossiers and never contains a local foreign key.
     *
     * @return array<string, mixed>|null
     */
    private function patientIdentity(Appointment $appointment): ?array
    {
        $patient = $this->appointmentPatient($appointment);

        if (! $patient instanceof Patient) {
            return null;
        }

        $identity = [
            'public_id' => $patient->public_id,
            'patient_number' => $patient->patient_number,
            'first_name' => $patient->first_name,
            'last_name' => $patient->last_name,
            'date_of_birth' => $patient->date_of_birth?->toDateString(),
            'gender' => $patient->gender?->value,
            'phone' => $patient->phone,
            'email' => $patient->email,
        ];

        // Keep older and staff-created patient payloads byte-compatible. The
        // family extension travels only when this dossier has a real mobile
        // account relationship to carry.
        if (filled($patient->family_group_public_id)) {
            $identity += [
                'family_group_public_id' => $patient->family_group_public_id,
                'family_relation' => $patient->family_relation,
                'family_contact_name' => $patient->family_contact_name,
            ];
        }

        return $identity;
    }

    /**
     * Resolve the appointment's patient once, memoising it on the model.
     *
     * `patientIdentity()` and the booking block both need the dossier; without
     * the memo, publishing one event would run the same query twice for every
     * appointment reconciliation touches.
     */
    private function appointmentPatient(Appointment $appointment): ?Patient
    {
        if ($appointment->relationLoaded('patient')) {
            $loaded = $appointment->getRelation('patient');

            return $loaded instanceof Patient ? $loaded : null;
        }

        $patient = Patient::withoutCabinetScope()
            ->withTrashed()
            ->find($appointment->patient_id);

        if ($patient instanceof Patient) {
            $appointment->setRelation('patient', $patient);
        }

        return $patient;
    }

    /**
     * The portable record of how this appointment came to exist.
     *
     * `booked_by_user_id` and `family_member_id` are this installation's
     * auto-increment keys, exactly like `patient_id`: the receiving desktop
     * has no matching `users` row and no `family_members` table content at
     * all, so copying them would either break its foreign keys or point at an
     * unrelated person. What reception actually needs is the channel, the name
     * and relation of the person the visit is for, and a phone number to call
     * — all of which are portable text.
     *
     * Returns `null` for a staff-created appointment, which has no mobile
     * provenance to carry.
     *
     * @return array<string, mixed>|null
     */
    private function bookingProvenance(Appointment $appointment): ?array
    {
        try {
            // An imported appointment has null foreign keys but a stored
            // context, so provenance survives hosted -> desktop -> re-push
            // -> hosted instead of being dropped on the first hop back.
            return $this->liveBookingProvenance($appointment)
                ?? $this->normalisedBookingProvenance($appointment->booking_context);
        } catch (Throwable) {
            // This runs inside `publish()`, which runs on the `created` and
            // `updated` hooks of every appointment write. A missing user row,
            // a deleted family member, or an unreadable JSON column must cost
            // a line of provenance, never a clinician's save.
            return null;
        }
    }

    /**
     * Build the block from the local foreign keys, for an appointment booked
     * on this installation.
     *
     * @return array<string, mixed>|null
     */
    private function liveBookingProvenance(Appointment $appointment): ?array
    {
        $channel = $appointment->booking_channel;
        $bookedByUserId = $appointment->booked_by_user_id;
        $familyMemberId = $appointment->family_member_id;

        if ($channel === null && $bookedByUserId === null && $familyMemberId === null) {
            return null;
        }

        return $this->normalisedBookingProvenance([
            'channel' => $channel,
            'booked_for' => $this->bookedFor($appointment, $familyMemberId),
            'booked_by' => $this->bookedBy($appointment, $bookedByUserId),
        ]);
    }

    /**
     * The person the visit is for: the account holder, or a family member.
     *
     * @return array<string, mixed>
     */
    private function bookedFor(Appointment $appointment, ?int $familyMemberId): array
    {
        $patientName = $this->appointmentPatient($appointment)?->full_name;
        $patientName = is_string($patientName) && trim($patientName) !== ''
            ? trim($patientName)
            : null;

        if ($familyMemberId === null) {
            return $this->visitIsForTheAccountHolder($appointment)
                ? ['type' => 'self', 'name' => $patientName, 'relation' => null]
                // The column was nulled by the family member's deletion, not
                // by the booking. The relation is unrecoverable, but the
                // dossier still names the right person and the visit was
                // never the account holder's.
                : ['type' => 'family', 'name' => $patientName, 'relation' => null];
        }

        $member = $this->appointmentFamilyMember($appointment, $familyMemberId);

        if (! $member instanceof FamilyMember) {
            // The family row is gone, but the appointment was still booked for
            // someone other than the account holder; saying "self" here would
            // be a lie about whose visit it is.
            return ['type' => 'family', 'name' => $patientName, 'relation' => null];
        }

        $relation = $member->relation;

        return [
            'type' => 'family',
            // A linked (rather than dependent) member carries no inline names;
            // the cabinet's own dossier holds the copy made at booking time.
            'name' => $this->personName($member->first_name, $member->last_name) ?? $patientName,
            'relation' => $relation instanceof FamilyRelation ? $relation->value : null,
        ];
    }

    /**
     * Whether a null `family_member_id` really means "the account holder's
     * own visit".
     *
     * It does not always. `appointments.family_member_id` is `nullOnDelete`
     * and `FamilyMember` has no soft deletes, so removing a member wipes the
     * column on every appointment ever booked for them — including past ones
     * and a linked member's upcoming ones, neither of which
     * `FamilyMemberController::destroy()` guards. Reading that null as "self"
     * would re-attribute the visit to the account holder, and because
     * {@see self::bookingProvenance()} lets the live block outrank the stored
     * `booking_context`, the rewrite is republished and overwrites the peer
     * installation's correct copy.
     *
     * The cabinet's own dossier survives the delete and answers honestly:
     * `PatientBookingService::resolvePatientRow()` keys a self booking's
     * patient by `patient_user_id` and a family booking's by
     * `family_member_id`, so a dossier that is not the booking account's own
     * means the visit never was either. Absent positive evidence to the
     * contrary — no booking account, or no dossier to read — "self" stands.
     */
    private function visitIsForTheAccountHolder(Appointment $appointment): bool
    {
        $bookedByUserId = $appointment->booked_by_user_id;

        if ($bookedByUserId === null) {
            return true;
        }

        $patient = $this->appointmentPatient($appointment);

        if (! $patient instanceof Patient) {
            return true;
        }

        $patientUserId = $patient->patient_user_id;

        return $patientUserId !== null && (int) $patientUserId === (int) $bookedByUserId;
    }

    /**
     * Resolve the family member once, memoising it on the appointment.
     *
     * Same reason as {@see self::appointmentPatient()}: `publish()` builds the
     * payload twice and `envelopesFor()` builds it twice more, so an
     * unmemoised lookup here multiplies into round trips inside the
     * cabinet-wide slot lock every booking is queued behind. A miss is
     * memoised too — a missing row must not be looked up again.
     */
    private function appointmentFamilyMember(Appointment $appointment, int $familyMemberId): ?FamilyMember
    {
        if ($appointment->relationLoaded('familyMember')) {
            $loaded = $appointment->getRelation('familyMember');

            return $loaded instanceof FamilyMember ? $loaded : null;
        }

        $member = FamilyMember::query()->find($familyMemberId);
        $appointment->setRelation('familyMember', $member);

        return $member instanceof FamilyMember ? $member : null;
    }

    /**
     * The mobile account that made the booking — the number reception calls.
     *
     * @return array<string, mixed>|null
     */
    private function bookedBy(Appointment $appointment, ?int $userId): ?array
    {
        if ($userId === null) {
            return null;
        }

        // Memoised on the models, like `appointmentPatient()`: the payload is
        // built twice per publish and twice more per push envelope, and
        // `publishUpsert()` runs inside the transaction that holds the
        // cabinet's slot lock.
        if ($appointment->relationLoaded('bookedBy')) {
            $user = $appointment->getRelation('bookedBy');
        } else {
            $user = User::query()->find($userId);
            $appointment->setRelation('bookedBy', $user);
        }

        if (! $user instanceof User) {
            return null;
        }

        if ($user->relationLoaded('patientProfile')) {
            $profile = $user->getRelation('patientProfile');
        } else {
            $profile = PatientProfile::query()->where('user_id', $userId)->first();
            $user->setRelation('patientProfile', $profile);
        }

        $name = ($profile instanceof PatientProfile
            ? $this->personName($profile->first_name, $profile->last_name)
            : null) ?? $this->personName($user->name, null);

        $phone = is_string($user->phone) && trim($user->phone) !== '' ? trim($user->phone) : null;

        return $name === null && $phone === null
            ? null
            : ['name' => $name, 'phone' => $phone];
    }

    private function personName(mixed $first, mixed $last): ?string
    {
        $name = trim(sprintf(
            '%s %s',
            is_string($first) ? $first : '',
            is_string($last) ? $last : '',
        ));

        return $name === '' ? null : $name;
    }

    /**
     * Coerce a booking block — freshly built, or read back from a stored
     * `booking_context` — into one canonical shape.
     *
     * Both producers run through here so a re-published imported appointment
     * serialises byte-identically to the payload it arrived in. Anything
     * unrecognised degrades to null rather than travelling on.
     *
     * @param  mixed  $raw  The `booking` key of a payload, or a stored context.
     * @return array<string, mixed>|null
     */
    public function normalisedBookingProvenance(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $channel = $this->portableString($raw['channel'] ?? null);
        $bookedFor = is_array($raw['booked_for'] ?? null)
            ? [
                'type' => ($raw['booked_for']['type'] ?? null) === 'family' ? 'family' : 'self',
                'name' => $this->portableString($raw['booked_for']['name'] ?? null),
                'relation' => $this->portableString($raw['booked_for']['relation'] ?? null),
            ]
            : null;

        $bookedBy = is_array($raw['booked_by'] ?? null)
            ? [
                'name' => $this->portableString($raw['booked_by']['name'] ?? null),
                'phone' => $this->portableString($raw['booked_by']['phone'] ?? null),
            ]
            : null;

        if ($bookedBy !== null && $bookedBy['name'] === null && $bookedBy['phone'] === null) {
            $bookedBy = null;
        }

        if ($channel === null && $bookedFor === null && $bookedBy === null) {
            return null;
        }

        return ['channel' => $channel, 'booked_for' => $bookedFor, 'booked_by' => $bookedBy];
    }

    private function portableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        // Trimmed again after the cut: a truncation landing on a space must
        // not leave a trailing one, or the same block would normalise to two
        // different strings depending on where the cut fell.
        return trim(mb_substr($value, 0, self::MAX_PORTABLE_STRING));
    }

    public function payloadSha256(Appointment $appointment): string
    {
        return hash('sha256', $this->encodedPayload($appointment));
    }

    /**
     * Record a change that arrived from another installation.
     *
     * The event is written so this cabinet's history stays complete and
     * {@see self::reconcileRecent()} does not mistake an imported appointment
     * for an unpublished one. It is marked `imported` rather than `pending`,
     * because the installation that sent it already has it: pushing it back
     * would bounce the same appointment between the two forever.
     */
    public function recordImported(Appointment $appointment, string $action): ?AppointmentSyncEvent
    {
        return $this->publish($appointment, $action, AppointmentSyncEvent::STATUS_IMPORTED);
    }

    private function publish(
        Appointment $appointment,
        string $action,
        string $status = AppointmentSyncEvent::STATUS_PENDING,
    ): ?AppointmentSyncEvent {
        if ($appointment->cabinet_id === null || $appointment->public_id === null) {
            return null;
        }

        // Encoded from the array already built, not rebuilt from the model:
        // the two must describe the same snapshot, and this publish runs
        // inside the transaction holding the cabinet's slot lock.
        $payload = $this->payload($appointment);
        $encodedPayload = $this->encode($payload);

        return AppointmentSyncEvent::withoutCabinetScope()->firstOrCreate(
            [
                'cabinet_id' => $appointment->cabinet_id,
                'appointment_public_id' => $appointment->public_id,
                'version' => $appointment->sync_version,
            ],
            [
                'event_id' => (string) Str::uuid7(),
                'appointment_id' => $appointment->getKey(),
                'action' => $action,
                'payload' => $payload,
                'payload_sha256' => hash('sha256', $encodedPayload),
                'status' => $status,
                'attempts' => 1,
                'last_attempted_at' => now(),
            ],
        );
    }

    private function publishCurrentAsNewVersion(Appointment $appointment): ?AppointmentSyncEvent
    {
        if ($appointment->cabinet_id === null || $appointment->public_id === null) {
            return null;
        }

        return DB::transaction(function () use ($appointment): ?AppointmentSyncEvent {
            $query = Appointment::withoutCabinetScope()
                ->withTrashed()
                ->where('cabinet_id', $appointment->cabinet_id)
                ->whereKey($appointment->getKey());

            if (DB::connection()->getDriverName() !== 'sqlite') {
                $query->lockForUpdate();
            }

            /** @var Appointment $locked */
            $locked = $query->firstOrFail();
            $latest = AppointmentSyncEvent::withoutCabinetScope()
                ->where('cabinet_id', $locked->cabinet_id)
                ->where('appointment_public_id', $locked->public_id)
                ->orderByDesc('version')
                ->first();

            if ($latest !== null
                && hash_equals($latest->payload_sha256, $this->payloadSha256($locked))) {
                return $latest;
            }

            $latestVersion = $latest === null ? 0 : (int) $latest->version;

            $locked->forceFill([
                'sync_version' => max(
                    1,
                    (int) $locked->sync_version,
                    $latestVersion,
                ) + 1,
            ])->saveQuietly();

            return $locked->trashed()
                ? $this->publishDeletion($locked)
                : $this->publishUpsert($locked);
        });
    }

    private function encodedPayload(Appointment $appointment): string
    {
        return $this->encode($this->payload($appointment));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encode(array $payload): string
    {
        return json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}
