<?php

namespace Tests\Feature\Api\Mobile\Boundaries;

use App\Enums\AppointmentStatus;
use App\Enums\Weekday;
use App\Models\Appointment;
use App\Models\Cabinet;
use App\Models\DoctorProfile;
use App\Models\DoctorSchedule;
use App\Models\Patient;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MobileTestHelpers;
use Tests\TestCase;

/**
 * Role boundary: a doctor sees and edits only their own cabinet. Another
 * cabinet's appointments are invisible (404), and the weekly schedule rewrite
 * may only ever touch the caller's own doctor profile rows — never 200 on
 * foreign data.
 */
class DoctorScopedToOwnDataTest extends TestCase
{
    use MobileTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        // A fixed Tuesday morning keeps every relative date deterministic.
        $this->travelTo(CarbonImmutable::parse('2026-10-06 08:00:00'));
    }

    public function test_reading_another_cabinets_appointment_is_a_404(): void
    {
        $clinicA = $this->makeListedClinic();
        $clinicB = $this->makeListedClinic();

        $mine = $this->makeAppointment($clinicA, CarbonImmutable::parse('2026-10-07 09:00:00'));
        $other = $this->makeAppointment($clinicB, CarbonImmutable::parse('2026-10-07 09:00:00'));

        Sanctum::actingAs($clinicA['doctorUser']);

        // Control: the doctor can read their own cabinet's appointment...
        $this->getJson('/api/v1/appointments/'.$mine->getKey())->assertOk();

        // ...but cabinet B's row does not exist for them — by id or public id.
        $this->getJson('/api/v1/appointments/'.$other->getKey())->assertNotFound();
        $this->getJson('/api/v1/appointments/'.$other->public_id)->assertNotFound();

        // Nor does it ever surface in their mobile agenda.
        $publicIds = collect(
            $this->getJson('/api/v1/mobile/appointments/today?date=2026-10-07')
                ->assertOk()
                ->json('data'),
        )->pluck('public_id');

        $this->assertTrue($publicIds->contains($mine->public_id));
        $this->assertFalse($publicIds->contains($other->public_id));
    }

    public function test_rescheduling_another_cabinets_appointment_is_out_of_reach(): void
    {
        $clinicA = $this->makeListedClinic();
        $clinicB = $this->makeListedClinic();

        $other = $this->makeAppointment($clinicB, CarbonImmutable::parse('2026-10-07 09:00:00'));

        Sanctum::actingAs($clinicA['doctorUser']);

        $this->patchJson("/api/v1/mobile/appointments/{$other->getKey()}/reschedule", [
            'starts_at' => '2026-10-20 10:00:00',
        ])->assertNotFound();

        $other->refresh();
        $this->assertSame('2026-10-07 09:00', $other->starts_at->format('Y-m-d H:i'));
        $this->assertSame(AppointmentStatus::SCHEDULED, $other->status);
    }

    public function test_the_schedule_rewrite_touches_only_the_own_doctors_rows(): void
    {
        $clinicA = $this->makeListedClinic();
        $clinicB = $this->makeListedClinic();

        DoctorSchedule::factory()->create([
            'doctor_id' => $clinicA['doctor']->getKey(),
            'cabinet_id' => $clinicA['cabinet']->getKey(),
            'day_of_week' => Weekday::MONDAY,
            'starts_at' => '08:00:00',
            'ends_at' => '12:00:00',
            'is_active' => true,
        ]);

        foreach ([Weekday::MONDAY, Weekday::TUESDAY] as $day) {
            DoctorSchedule::factory()->create([
                'doctor_id' => $clinicB['doctor']->getKey(),
                'cabinet_id' => $clinicB['cabinet']->getKey(),
                'day_of_week' => $day,
                'starts_at' => '09:00:00',
                'ends_at' => '13:00:00',
                'is_active' => true,
            ]);
        }

        $foreignRowIds = DoctorSchedule::withoutCabinetScope()
            ->where('doctor_id', $clinicB['doctor']->getKey())
            ->orderBy('id')
            ->pluck('id');

        Sanctum::actingAs($clinicA['doctorUser']);

        $this->putJson('/api/v1/mobile/schedule', [
            'days' => [
                [
                    'day_of_week' => Weekday::SATURDAY->value,
                    'ranges' => [
                        ['starts_at' => '08:00', 'ends_at' => '12:00', 'slot_duration' => 30],
                    ],
                ],
            ],
        ])->assertOk();

        // The caller's rows were wiped and rewritten: Monday gone, Saturday in.
        $ownRows = DoctorSchedule::withoutCabinetScope()
            ->where('doctor_id', $clinicA['doctor']->getKey())
            ->get();

        $this->assertCount(1, $ownRows);
        $this->assertSame(Weekday::SATURDAY, $ownRows->first()->day_of_week);

        // The other doctor's rows survived untouched — same ids, same days.
        $foreignRows = DoctorSchedule::withoutCabinetScope()
            ->where('doctor_id', $clinicB['doctor']->getKey())
            ->orderBy('id')
            ->get();

        $this->assertSame($foreignRowIds->all(), $foreignRows->pluck('id')->all());
        $this->assertSame(
            [Weekday::MONDAY, Weekday::TUESDAY],
            $foreignRows->pluck('day_of_week')->all(),
        );
    }

    /**
     * @param  array{cabinet: Cabinet, doctor: DoctorProfile, doctorUser: User}  $clinic
     */
    private function makeAppointment(array $clinic, CarbonImmutable $startsAt): Appointment
    {
        $patient = Patient::factory()->create([
            'cabinet_id' => $clinic['cabinet']->getKey(),
        ]);

        return Appointment::factory()->create([
            'cabinet_id' => $clinic['cabinet']->getKey(),
            'patient_id' => $patient->getKey(),
            'appointment_date' => $startsAt->toDateString(),
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes(30),
            'status' => AppointmentStatus::SCHEDULED,
        ]);
    }
}
