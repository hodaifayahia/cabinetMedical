<?php

namespace App\Actions\Patients;

use App\Enums\PatientRelation;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\PatientRelative;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Records that two dossiers of the cabinet belong to the same family, in
 * both directions: « Ali est le frère de Sara » also stores « Sara est la
 * sœur d'Ali », the reverse relation following Sara's sex.
 */
final class LinkPatientRelativeAction
{
    public function link(Patient $patient, Patient $relative, PatientRelation $relation, ?User $user = null): PatientRelative
    {
        if ($patient->is($relative)) {
            throw new InvalidArgumentException('Un patient ne peut pas être lié à lui-même.');
        }

        if ($patient->cabinet_id !== $relative->cabinet_id) {
            throw new InvalidArgumentException('Les deux dossiers doivent appartenir au même cabinet.');
        }

        return DB::transaction(function () use ($patient, $relative, $relation, $user): PatientRelative {
            $link = $this->store($patient, $relative, $relation, $user);
            $this->store($relative, $patient, $relation->inverse($patient->gender), $user);

            AuditLog::record('patient.relative_linked', $patient, [
                'relative_patient_id' => $relative->getKey(),
                'relation' => $relation->value,
            ]);

            return $link;
        });
    }

    /**
     * Removes the link in both directions.
     */
    public function unlink(Patient $patient, Patient $relative): void
    {
        DB::transaction(function () use ($patient, $relative): void {
            $deleted = PatientRelative::query()
                ->where(function ($query) use ($patient, $relative): void {
                    $query->where(function ($pair) use ($patient, $relative): void {
                        $pair->where('patient_id', $patient->getKey())
                            ->where('relative_patient_id', $relative->getKey());
                    })->orWhere(function ($pair) use ($patient, $relative): void {
                        $pair->where('patient_id', $relative->getKey())
                            ->where('relative_patient_id', $patient->getKey());
                    });
                })
                ->delete();

            if ($deleted > 0) {
                AuditLog::record('patient.relative_unlinked', $patient, [
                    'relative_patient_id' => $relative->getKey(),
                ]);
            }
        });
    }

    private function store(Patient $patient, Patient $relative, PatientRelation $relation, ?User $user): PatientRelative
    {
        /** @var PatientRelative $link */
        $link = PatientRelative::query()->firstOrNew([
            'patient_id' => $patient->getKey(),
            'relative_patient_id' => $relative->getKey(),
        ]);

        $link->relation = $relation;
        $link->cabinet_id ??= $patient->cabinet_id;
        $link->created_by ??= $user?->getKey();
        $link->save();

        return $link;
    }
}
