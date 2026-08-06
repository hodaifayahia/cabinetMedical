<?php

namespace App\Actions\Patients;

use App\Models\Patient;
use Illuminate\Support\Str;

class GeneratePatientNumberAction
{
    public function handle(): string
    {
        do {
            $candidate = sprintf(
                'PAT-%s-%s',
                now()->format('Ymd'),
                Str::upper(Str::random(6)),
            );
        } while ($this->exists($candidate));

        return $candidate;
    }

    private function exists(string $candidate): bool
    {
        return Patient::withTrashed()
            ->where('patient_number', $candidate)
            ->exists();
    }
}
