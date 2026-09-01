<?php

namespace Database\Factories;

use App\Enums\FamilyMemberStatus;
use App\Enums\FamilyRelation;
use App\Enums\Gender;
use App\Models\FamilyMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FamilyMember>
 */
class FamilyMemberFactory extends Factory
{
    /**
     * Define the model's default state: an active dependent profile.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_user_id' => User::factory(),
            'relation' => fake()->randomElement(FamilyRelation::cases()),
            'status' => FamilyMemberStatus::ACTIVE,
            'linked_user_id' => null,
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'gender' => fake()->randomElement(Gender::cases()),
            'date_of_birth' => fake()->dateTimeBetween('-80 years', '-1 year'),
            'place_of_birth' => fake()->optional()->city(),
            'wilaya_code' => null,
            'baladiya_id' => null,
        ];
    }

    /**
     * An inline dependent profile without an account of its own.
     */
    public function dependent(): static
    {
        return $this->state(fn (array $attributes) => [
            'linked_user_id' => null,
            'status' => FamilyMemberStatus::ACTIVE,
        ]);
    }

    /**
     * A pending link to another patient account.
     */
    public function linked(): static
    {
        return $this->state(fn (array $attributes) => [
            'linked_user_id' => User::factory(),
            'status' => FamilyMemberStatus::PENDING,
            'first_name' => null,
            'last_name' => null,
            'gender' => null,
            'date_of_birth' => null,
            'place_of_birth' => null,
        ]);
    }

    /**
     * A link the target account has approved.
     */
    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => FamilyMemberStatus::APPROVED,
        ]);
    }
}
