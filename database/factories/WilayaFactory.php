<?php

namespace Database\Factories;

use App\Models\Wilaya;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Wilaya>
 */
class WilayaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->numberBetween(1, 58),
            'name_fr' => fake()->unique()->city(),
            'name_ar' => 'ولاية '.fake()->numberBetween(1, 999),
        ];
    }
}
