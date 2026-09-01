<?php

namespace Tests\Feature\Api\Mobile\Staff;

use App\Enums\RoleName;
use App\Enums\Weekday;
use App\Models\DoctorSchedule;
use App\Models\DoctorTimeOff;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MobileTestHelpers;
use Tests\TestCase;

/**
 * Multi-range weekly schedule and time-off management from the staff mobile
 * app. Both sit behind the appointments.configure permission.
 */
class ScheduleWriteTest extends TestCase
{
    use MobileTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_a_multi_range_day_persists_one_row_per_range(): void
    {
        $clinic = $this->makeListedClinic();

        // A pre-existing single-range Monday must be wiped by the rewrite.
        DoctorSchedule::factory()->create([
            'doctor_id' => $clinic['doctor']->getKey(),
            'cabinet_id' => $clinic['cabinet']->getKey(),
            'day_of_week' => Weekday::MONDAY,
        ]);

        Sanctum::actingAs($clinic['doctorUser']);

        $response = $this->putJson('/api/v1/mobile/schedule', [
            'days' => [
                [
                    'day_of_week' => Weekday::SATURDAY->value,
                    'ranges' => [
                        ['starts_at' => '08:00', 'ends_at' => '12:00', 'slot_duration' => 20],
                        ['starts_at' => '14:00', 'ends_at' => '18:00', 'slot_duration' => 30],
                    ],
                ],
            ],
        ])->assertOk();

        $rows = DoctorSchedule::query()
            ->where('doctor_id', $clinic['doctor']->getKey())
            ->orderBy('starts_at')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertTrue($rows->every(
            static fn (DoctorSchedule $row): bool => $row->day_of_week === Weekday::SATURDAY && $row->is_active,
        ));

        $saturday = collect($response->json('data.working_hours'))
            ->firstWhere('weekday', Weekday::SATURDAY->value);

        $this->assertFalse($saturday['is_closed']);
        $this->assertSame(['morning', 'evening'], array_column($saturday['ranges'], 'period'));
        $this->assertSame([20, 30], array_column($saturday['ranges'], 'slot_duration'));

        $monday = collect($response->json('data.working_hours'))
            ->firstWhere('weekday', Weekday::MONDAY->value);

        $this->assertTrue($monday['is_closed']);
        $this->assertCount(7, $response->json('data.working_hours'));
    }

    public function test_overlapping_ranges_within_a_day_are_rejected(): void
    {
        $clinic = $this->makeListedClinic();
        Sanctum::actingAs($clinic['doctorUser']);

        $this->putJson('/api/v1/mobile/schedule', [
            'days' => [
                [
                    'day_of_week' => Weekday::SATURDAY->value,
                    'ranges' => [
                        ['starts_at' => '08:00', 'ends_at' => '12:00'],
                        ['starts_at' => '11:00', 'ends_at' => '15:00'],
                    ],
                ],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('days.0.ranges');

        $this->assertSame(0, DoctorSchedule::query()->where('doctor_id', $clinic['doctor']->getKey())->count());
    }

    public function test_a_range_ending_before_it_starts_is_rejected(): void
    {
        $clinic = $this->makeListedClinic();
        Sanctum::actingAs($clinic['doctorUser']);

        $this->putJson('/api/v1/mobile/schedule', [
            'days' => [
                [
                    'day_of_week' => Weekday::MONDAY->value,
                    'ranges' => [
                        ['starts_at' => '14:00', 'ends_at' => '09:00'],
                    ],
                ],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('days.0.ranges.0.ends_at');
    }

    public function test_the_same_day_may_not_appear_twice(): void
    {
        $clinic = $this->makeListedClinic();
        Sanctum::actingAs($clinic['doctorUser']);

        $this->putJson('/api/v1/mobile/schedule', [
            'days' => [
                ['day_of_week' => 1, 'ranges' => [['starts_at' => '08:00', 'ends_at' => '12:00']]],
                ['day_of_week' => 1, 'ranges' => [['starts_at' => '14:00', 'ends_at' => '18:00']]],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('days.0.day_of_week');
    }

    public function test_an_assistant_without_the_configure_permission_is_forbidden(): void
    {
        $clinic = $this->makeListedClinic();
        $assistant = User::factory()->create([
            'cabinet_id' => $clinic['cabinet']->getKey(),
            'approved_at' => now(),
        ]);
        $assistant->assignRole(RoleName::ASSISTANT->value);

        Sanctum::actingAs($assistant);

        $this->putJson('/api/v1/mobile/schedule', [
            'days' => [
                ['day_of_week' => 1, 'ranges' => [['starts_at' => '08:00', 'ends_at' => '12:00']]],
            ],
        ])->assertStatus(403);

        $this->assertSame(0, DoctorSchedule::withoutCabinetScope()->where('doctor_id', $clinic['doctor']->getKey())->count());
    }

    public function test_a_patient_token_is_rejected(): void
    {
        Sanctum::actingAs($this->makePatientUser());

        $this->putJson('/api/v1/mobile/schedule', ['days' => []])
            ->assertStatus(403)
            ->assertJsonPath('reason', 'patient_token_forbidden');
    }

    public function test_an_all_day_time_off_stores_an_exclusive_end_boundary(): void
    {
        $clinic = $this->makeListedClinic();
        Sanctum::actingAs($clinic['doctorUser']);

        $response = $this->postJson('/api/v1/mobile/schedule/time-off', [
            'starts_at' => '2026-10-20',
            'ends_at' => '2026-10-21',
            'reason' => 'Aïd',
        ])
            ->assertCreated()
            ->assertJsonPath('data.is_all_day', true)
            ->assertJsonPath('data.reason', 'Aïd');

        $timeOff = DoctorTimeOff::query()->findOrFail($response->json('data.id'));

        $this->assertSame($clinic['doctor']->getKey(), (int) $timeOff->doctor_id);
        $this->assertSame('2026-10-20 00:00:00', $timeOff->starts_at->format('Y-m-d H:i:s'));
        // Exclusive boundary: midnight after the last day off.
        $this->assertSame('2026-10-22 00:00:00', $timeOff->ends_at->format('Y-m-d H:i:s'));
    }

    public function test_a_partial_time_off_with_a_utc_offset_stores_local_times(): void
    {
        $clinic = $this->makeListedClinic();
        Sanctum::actingAs($clinic['doctorUser']);

        // 08:00Z == 09:00 Africa/Algiers: a UTC-serializing client must not
        // write shifted naive times into the closure.
        $response = $this->postJson('/api/v1/mobile/schedule/time-off', [
            'starts_at' => '2026-10-20T08:00:00Z',
            'ends_at' => '2026-10-20T11:00:00Z',
            'is_all_day' => false,
        ])->assertCreated();

        $timeOff = DoctorTimeOff::query()->findOrFail($response->json('data.id'));

        $this->assertSame('2026-10-20 09:00:00', $timeOff->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-20 12:00:00', $timeOff->ends_at->format('Y-m-d H:i:s'));
    }

    public function test_a_time_off_can_be_removed(): void
    {
        $clinic = $this->makeListedClinic();
        $timeOff = DoctorTimeOff::factory()->create([
            'doctor_id' => $clinic['doctor']->getKey(),
            'cabinet_id' => $clinic['cabinet']->getKey(),
        ]);

        Sanctum::actingAs($clinic['doctorUser']);

        $this->deleteJson('/api/v1/mobile/schedule/time-off/'.$timeOff->getKey())
            ->assertOk();

        $this->assertDatabaseMissing('doctor_time_off', ['id' => $timeOff->getKey()]);
    }

    public function test_a_time_off_of_another_cabinet_is_out_of_reach(): void
    {
        $clinicA = $this->makeListedClinic();
        $clinicB = $this->makeListedClinic();
        $timeOff = DoctorTimeOff::factory()->create([
            'doctor_id' => $clinicB['doctor']->getKey(),
            'cabinet_id' => $clinicB['cabinet']->getKey(),
        ]);

        Sanctum::actingAs($clinicA['doctorUser']);

        $this->deleteJson('/api/v1/mobile/schedule/time-off/'.$timeOff->getKey())
            ->assertNotFound();

        $this->assertDatabaseHas('doctor_time_off', ['id' => $timeOff->getKey()]);
    }
}
