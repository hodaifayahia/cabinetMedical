<?php

namespace Database\Factories;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Patient;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = CarbonImmutable::instance(
            fake()->dateTimeBetween('+1 day', '+2 weeks'),
        )->setTime(
            (int) fake()->randomElement([9, 10, 11, 14, 15, 16]),
            0,
        );

        $endsAt = $startsAt->addMinutes(30);

        return [
            'patient_id' => Patient::factory(),
            'appointment_date' => $startsAt->toDateString(),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => AppointmentStatus::SCHEDULED,
            'reason' => fake()->sentence(),
            'reception_notes' => null,
            'created_by' => null,
            'cancelled_by' => null,
            'cancellation_reason' => null,
            'confirmed_at' => null,
            'checked_in_at' => null,
            'started_at' => null,
            'completed_at' => null,
            'cancelled_at' => null,
        ];
    }

    public function confirmed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AppointmentStatus::CONFIRMED,
            'confirmed_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AppointmentStatus::CANCELLED,
            'cancelled_at' => now(),
            'cancellation_reason' => 'Patient requested cancellation.',
        ]);
    }
}
