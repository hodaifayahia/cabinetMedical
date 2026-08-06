<?php

namespace App\Actions\Encounters;

use App\Enums\EncounterStatus;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateEncounterAction
{
    /** @param array<string, mixed>|null $data */
    public function handle(Patient $patient, User $provider, ?array $data = null): Encounter
    {
        return DB::transaction(function () use ($patient, $provider, $data) {
            $encounter = new Encounter([
                'patient_id' => $patient->id,
                'provider_id' => $provider->id,
                'status' => EncounterStatus::Draft,
                'occurred_at' => $data['occurred_at'] ?? now(),
                'started_at' => now(),
                'revision_number' => 1,
                'lock_version' => 1,
            ]);

            if (isset($data['appointment_id'])) {
                $encounter->appointment_id = $data['appointment_id'];
            }

            $encounter->save();

            return $encounter;
        });
    }
}
