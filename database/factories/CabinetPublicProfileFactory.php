<?php

namespace Database\Factories;

use App\Enums\CabinetStatus;
use App\Models\Cabinet;
use App\Models\CabinetPublicProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CabinetPublicProfile>
 */
class CabinetPublicProfileFactory extends Factory
{
    /**
     * Define the model's default state. The Cabinet model has no factory, so
     * an active cabinet is created inline when none is supplied.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cabinet_id' => fn (): int => Cabinet::query()->create([
                'name' => 'Cabinet '.fake()->unique()->lastName(),
                'status' => CabinetStatus::ACTIVE,
                'activated_at' => now(),
            ])->getKey(),
            'is_listed' => false,
            'about' => fake()->optional()->paragraph(),
            'address' => fake()->optional()->streetAddress(),
            'baladiya_id' => null,
            'phones' => null,
            'latitude' => null,
            'longitude' => null,
            'photos' => null,
        ];
    }

    public function listed(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_listed' => true,
        ]);
    }
}
