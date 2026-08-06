<?php

namespace Database\Factories;

use App\Enums\Weekday;
use App\Models\DoctorProfile;
use App\Models\DoctorSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DoctorSchedule>
 */
class DoctorScheduleFactory extends Factory
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
            'day_of_week' => fake()->randomElement(Weekday::cases()),
            'starts_at' => '09:00:00',
            'ends_at' => '17:00:00',
            'slot_duration' => 30,
            'is_active' => true,
        ];
    }
}
