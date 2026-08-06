<?php

namespace App\Actions\Patients;

use App\Models\Patient;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreatePatientAction
{
    /**
     * Persist a new patient owned by the acting user.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function handle(array $attributes, User $creator): Patient
    {
        return DB::transaction(static function () use ($attributes, $creator): Patient {
            return Patient::query()->create([
                ...$attributes,
                'created_by' => $creator->getKey(),
            ]);
        });
    }
}
