<?php

namespace Database\Factories;

use App\Models\DoctorProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DoctorProfile>
 */
class DoctorProfileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'specialty' => fake()->randomElement([
                'General Medicine',
                'Family Medicine',
                'Pediatrics',
                'Cardiology',
                'Dermatology',
            ]),
            'professional_identifier' => fake()->unique()->bothify('LIC-#####'),
            'consultation_duration' => fake()->randomElement([15, 20, 30, 45]),
            'consultation_fee_minor' => fake()->randomElement([150000, 200000, 250000, 300000]),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
