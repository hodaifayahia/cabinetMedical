<?php

namespace Database\Factories;

use App\Enums\BloodGroup;
use App\Enums\Gender;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Patient>
 */
class PatientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'patient_number' => null,
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'date_of_birth' => fake()->dateTimeBetween('-90 years', '-1 year'),
            'gender' => fake()->randomElement(Gender::cases()),
            'phone' => fake()->optional()->phoneNumber(),
            'secondary_phone' => fake()->optional()->phoneNumber(),
            'email' => fake()->optional()->safeEmail(),
            'address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'emergency_contact_name' => fake()->optional()->name(),
            'emergency_contact_phone' => fake()->optional()->phoneNumber(),
            'blood_group' => fake()->optional()->randomElement(BloodGroup::cases()),
            'notes' => fake()->optional()->sentence(),
            'created_by' => null,
        ];
    }
}
