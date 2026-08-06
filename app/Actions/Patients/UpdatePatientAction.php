<?php

namespace App\Actions\Patients;

use App\Models\Patient;
use Illuminate\Support\Facades\DB;

class UpdatePatientAction
{
    /**
     * Update an existing patient's editable attributes.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Patient $patient, array $attributes): Patient
    {
        return DB::transaction(static function () use ($patient, $attributes): Patient {
            $patient->update($attributes);

            return $patient;
        });
    }
}
