<?php

namespace Database\Factories;

use App\Enums\EncounterStatus;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Encounter>
 */
class EncounterFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'appointment_id' => null,
            'provider_id' => User::factory(),
            'status' => EncounterStatus::Draft,
            'occurred_at' => now(),
            'started_at' => now(),
            'signed_at' => null,
            'signed_by' => null,
            'revision_number' => 1,
            'amends_encounter_id' => null,
            'amendment_reason' => null,
            'content_hash' => null,
            'lock_version' => 1,
        ];
    }

    public function inProgress(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => EncounterStatus::InProgress,
        ]);
    }

    public function signed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => EncounterStatus::Signed,
            'signed_at' => now(),
            'signed_by' => User::factory(),
            'content_hash' => hash('sha256', 'signed'),
        ]);
    }
}
