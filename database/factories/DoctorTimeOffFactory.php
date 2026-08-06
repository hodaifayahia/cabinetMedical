<?php

namespace Database\Factories;

use App\Models\DoctorProfile;
use App\Models\DoctorTimeOff;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DoctorTimeOff>
 */
class DoctorTimeOffFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = CarbonImmutable::tomorrow()->setTime(9, 0);

        return [
            'doctor_id' => DoctorProfile::factory(),
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addHours(8),
            'is_all_day' => false,
            'reason' => 'Conference',
            'notes' => fake()->sentence(),
        ];
    }
}
