<?php

namespace App\Actions\Patients;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Folds a duplicate dossier into the one the cabinet keeps.
 *
 * Every row that points at the duplicate (visits, appointments, prescriptions,
 * payments, documents, ECGs, alerts…) is moved to the kept dossier. The tables
 * are discovered from the schema, so a table added later is merged too without
 * touching this class. The duplicate is then archived with a pointer to the
 * kept dossier, which is what sync follows.
 */
final class MergePatientsAction
{
    /** Identity fields: the kept dossier's value wins unless the doctor picks the other. */
    public const IDENTITY_FIELDS = [
        'first_name',
        'last_name',
        'date_of_birth',
        'gender',
        'blood_group',
        'phone',
        'secondary_phone',
        'email',
        'address',
        'city',
        'wilaya_code',
        'baladiya_id',
        'place_of_birth',
        'marital_status',
        'profession',
        'smoking_status',
        'referred_by',
        'emergency_contact_name',
        'emergency_contact_phone',
    ];

    /** Clinical free text: both dossiers' content is kept, never dropped. */
    public const CLINICAL_TEXT_FIELDS = [
        'allergies',
        'antecedents_medical',
        'antecedents_surgical',
        'antecedents_family',
        'antecedents_gyneco',
        'antecedents_other',
        'notes',
    ];

    /**
     * @param  array<string, string>  $choices  field => 'primary'|'duplicate' for conflicting identity fields.
     * @return array<string, int> Rows moved, per table.
     */
    public function handle(Patient $primary, Patient $duplicate, User $user, array $choices = []): array
    {
        if ($primary->is($duplicate)) {
            throw new InvalidArgumentException('Un dossier ne peut pas être fusionné avec lui-même.');
        }

        if ($primary->cabinet_id !== $duplicate->cabinet_id) {
            throw new InvalidArgumentException('Les deux dossiers doivent appartenir au même cabinet.');
        }

        if ($primary->trashed() || $duplicate->trashed()) {
            throw new InvalidArgumentException('Un des dossiers est déjà archivé ou fusionné.');
        }

        return DB::transaction(function () use ($primary, $duplicate, $user, $choices): array {
            $moved = $this->moveRelatedRows($primary, $duplicate);
            $relatives = $this->moveRelatives($primary, $duplicate);

            if ($relatives > 0) {
                $moved['patient_relatives'] = $relatives;
            }

            $this->mergeFields($primary, $duplicate, $choices);
            $this->moveMobileLinks($primary, $duplicate);

            $duplicate->forceFill([
                'merged_into_id' => $primary->getKey(),
                'merged_at' => now(),
                'patient_user_id' => null,
                'family_member_id' => null,
            ])->saveQuietly();
            $duplicate->delete();

            AuditLog::record('patient.merged', $primary, [
                'merged_patient_id' => $duplicate->getKey(),
                'merged_patient_number' => $duplicate->patient_number,
                'merged_public_id' => $duplicate->public_id,
                'rows_moved' => $moved,
                'choices' => $choices,
            ], $user->getKey());

            return $moved;
        });
    }

    /**
     * Rows that would move, per table, without changing anything.
     *
     * @return array<string, int>
     */
    public function preview(Patient $duplicate): array
    {
        $counts = [];

        foreach ($this->patientTables() as $table) {
            $count = DB::table($table)->where('patient_id', $duplicate->getKey())->count();

            if ($count > 0) {
                $counts[$table] = $count;
            }
        }

        $relatives = Schema::hasTable('patient_relatives')
            ? DB::table('patient_relatives')->where('patient_id', $duplicate->getKey())->count()
            : 0;

        if ($relatives > 0) {
            $counts['patient_relatives'] = $relatives;
        }

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    private function moveRelatedRows(Patient $primary, Patient $duplicate): array
    {
        $moved = [];

        // Through the model, so each appointment gets a new sync version and
        // the mobile app / hosted service learn which dossier it now belongs to.
        $appointments = Appointment::withoutCabinetScope()
            ->withTrashed()
            ->where('patient_id', $duplicate->getKey())
            ->get();

        foreach ($appointments as $appointment) {
            $appointment->patient_id = $primary->getKey();
            $appointment->save();
        }

        if ($appointments->isNotEmpty()) {
            $moved['appointments'] = $appointments->count();
        }

        foreach ($this->patientTables() as $table) {
            if ($table === 'appointments') {
                continue;
            }

            $count = DB::table($table)
                ->where('patient_id', $duplicate->getKey())
                ->update(['patient_id' => $primary->getKey()]);

            if ($count > 0) {
                $moved[$table] = $count;
            }
        }

        return $moved;
    }

    /**
     * Family links follow the person: the duplicate's relatives become the
     * kept dossier's relatives (both directions), without duplicating a link
     * the kept dossier already has or linking it to itself.
     */
    private function moveRelatives(Patient $primary, Patient $duplicate): int
    {
        if (! Schema::hasTable('patient_relatives')) {
            return 0;
        }

        $primaryId = $primary->getKey();
        $duplicateId = $duplicate->getKey();
        $moved = 0;

        // A link between the two dossiers of the same person is meaningless.
        DB::table('patient_relatives')
            ->whereIn('patient_id', [$primaryId, $duplicateId])
            ->whereIn('relative_patient_id', [$primaryId, $duplicateId])
            ->delete();

        foreach (['patient_id' => 'relative_patient_id', 'relative_patient_id' => 'patient_id'] as $column => $other) {
            $alreadyLinked = DB::table('patient_relatives')
                ->where($column, $primaryId)
                ->pluck($other)
                ->all();

            DB::table('patient_relatives')
                ->where($column, $duplicateId)
                ->whereIn($other, $alreadyLinked)
                ->delete();

            $moved += DB::table('patient_relatives')
                ->where($column, $duplicateId)
                ->update([$column => $primaryId, 'updated_at' => now()]);
        }

        return $moved;
    }

    /**
     * @param  array<string, string>  $choices
     */
    private function mergeFields(Patient $primary, Patient $duplicate, array $choices): void
    {
        $updates = [];

        foreach (self::IDENTITY_FIELDS as $field) {
            $kept = $primary->getRawOriginal($field);
            $other = $duplicate->getRawOriginal($field);

            if ($this->blank($other)) {
                continue;
            }

            if ($this->blank($kept) || ($choices[$field] ?? 'primary') === 'duplicate') {
                $updates[$field] = $other;
            }
        }

        foreach (self::CLINICAL_TEXT_FIELDS as $field) {
            $kept = trim((string) $primary->getRawOriginal($field));
            $other = trim((string) $duplicate->getRawOriginal($field));

            if ($other === '' || $other === $kept || str_contains($kept, $other)) {
                continue;
            }

            $updates[$field] = $kept === '' ? $other : $kept."\n".$other;
        }

        if ($updates !== []) {
            $primary->forceFill($updates)->save();
        }
    }

    /**
     * A duplicate created by a mobile booking carries the link to the patient's
     * account. The kept dossier takes it over when it has none, so the next
     * booking finds the kept dossier instead of creating a third one.
     */
    private function moveMobileLinks(Patient $primary, Patient $duplicate): void
    {
        $updates = [];

        foreach (['patient_user_id', 'family_member_id'] as $field) {
            if ($primary->{$field} === null && $duplicate->{$field} !== null) {
                $updates[$field] = $duplicate->{$field};
            }
        }

        if ($updates !== []) {
            // The duplicate releases the link first; the column is indexed per cabinet.
            $duplicate->forceFill(array_fill_keys(array_keys($updates), null))->saveQuietly();
            $primary->forceFill($updates)->saveQuietly();
        }
    }

    /**
     * Every table with a `patient_id` column, other than the patients table.
     *
     * @return list<string>
     */
    private function patientTables(): array
    {
        $tables = [];

        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            $table = (string) $table;

            if (! in_array($table, ['patients', 'patient_relatives'], true)
                && ! str_starts_with($table, 'sqlite_')
                && Schema::hasColumn($table, 'patient_id')) {
                $tables[] = $table;
            }
        }

        return $tables;
    }

    private function blank(mixed $value): bool
    {
        return $value === null || trim((string) $value) === '';
    }
}
