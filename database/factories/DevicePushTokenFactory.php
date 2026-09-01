<?php

namespace Database\Factories;

use App\Models\DevicePushToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DevicePushToken>
 */
class DevicePushTokenFactory extends Factory
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
            'token' => 'ExponentPushToken['.fake()->unique()->regexify('[A-Za-z0-9_-]{22}').']',
            'platform' => fake()->randomElement(['ios', 'android']),
            'last_seen_at' => now(),
        ];
    }
}
