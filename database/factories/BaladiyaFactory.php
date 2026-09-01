<?php

namespace Database\Factories;

use App\Models\Baladiya;
use App\Models\Wilaya;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Baladiya>
 */
class BaladiyaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'wilaya_code' => Wilaya::factory(),
            'name_fr' => fake()->unique()->city(),
            'name_ar' => 'بلدية '.fake()->numberBetween(1, 9999),
        ];
    }
}
