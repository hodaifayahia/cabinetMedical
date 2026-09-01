<?php

namespace Database\Factories;

use App\Enums\Gender;
use App\Models\PatientProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PatientProfile>
 */
class PatientProfileFactory extends Factory
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
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'gender' => fake()->randomElement(Gender::cases()),
            'date_of_birth' => fake()->dateTimeBetween('-80 years', '-18 years'),
            'place_of_birth' => fake()->optional()->city(),
            'wilaya_code' => null,
            'baladiya_id' => null,
            'avatar_path' => null,
        ];
    }
}
