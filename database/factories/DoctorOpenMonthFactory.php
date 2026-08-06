<?php

namespace Database\Factories;

use App\Models\DoctorOpenMonth;
use App\Models\DoctorProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DoctorOpenMonth>
 */
class DoctorOpenMonthFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'doctor_id' => DoctorProfile::factory(),
            'year' => (int) now()->year,
            'month' => (int) now()->month,
            'is_open' => true,
            'note' => null,
        ];
    }

    public function closed(): static
    {
        return $this->state(fn (): array => ['is_open' => false]);
    }
}
