<?php

namespace Tests\Feature\Api\Mobile\Availability;

use App\Enums\Weekday;
use App\Models\Appointment;
use App\Models\Cabinet;
use App\Models\CabinetPublicProfile;
use App\Models\DoctorOpenMonth;
use App\Models\DoctorProfile;
use App\Models\DoctorSchedule;
use App\Models\DoctorTimeOff;
use App\Models\Patient;
use App\Services\Mobile\PublicAvailabilityService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MobileTestHelpers;
use Tests\TestCase;

/**
 * Public availability of a listed doctor: month gate, day slots, time off,
 * bookings, tenant isolation and the listed+active 404 guard.
 */
class PublicAvailabilityTest extends TestCase
{
    use MobileTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_a_closed_month_is_never_bookable(): void
    {
        [, $doctor, $date] = $this->makeBookableClinic(openMonth: false);

        $this->getJson($this->monthUrl($doctor, $date))
            ->assertOk()
            ->assertJsonPath('is_open_month', false)
            ->assertJsonCount((int) $date->daysInMonth, 'days')
            ->assertJsonMissing(['bookable' => true]);

        $this->getJson($this->dayUrl($doctor, $date))
            ->assertOk()
            ->assertJsonPath('reason', 'month_closed')
            ->assertJsonPath('slots', []);
    }

    public function test_day_slots_reflect_the_schedule_without_the_staff_appointments_array(): void
    {
        [, $doctor, $date] = $this->makeBookableClinic();

        $response = $this->getJson($this->dayUrl($doctor, $date))
            ->assertOk()
            ->assertJsonPath('date', $date->toDateString())
            ->assertJsonPath('reason', null)
            ->assertJsonCount(4, 'slots')
            ->assertJsonPath('slots.0.label', '09:00')
            ->assertJsonPath('slots.0.available', true)
            ->assertJsonPath('slots.3.label', '10:30');

        $this->assertArrayNotHasKey('appointments', $response->json());
    }

    public function test_the_month_overview_marks_the_working_day_bookable(): void
    {
        [, $doctor, $date] = $this->makeBookableClinic();

        $response = $this->getJson($this->monthUrl($doctor, $date))
            ->assertOk()
            ->assertJsonPath('is_open_month', true);

        $day = collect($response->json('days'))->firstWhere('date', $date->toDateString());

        $this->assertNotNull($day);
        $this->assertTrue($day['bookable']);
        $this->assertTrue($day['is_working_day']);
        $this->assertSame(4, $day['available_count']);
    }

    public function test_time_off_closes_the_day_or_blocks_its_slots(): void
    {
        [$cabinet, $doctor, $date] = $this->makeBookableClinic();

        $partial = DoctorTimeOff::factory()->create([
            'doctor_id' => $doctor->getKey(),
            'cabinet_id' => $cabinet->getKey(),
            'starts_at' => $date->setTime(9, 0),
            'ends_at' => $date->setTime(10, 0),
            'is_all_day' => false,
        ]);

        $this->getJson($this->dayUrl($doctor, $date))
            ->assertOk()
            ->assertJsonPath('reason', null)
            ->assertJsonPath('slots.0.available', false)
            ->assertJsonPath('slots.0.reason', 'time_off')
            ->assertJsonPath('slots.1.available', false)
            ->assertJsonPath('slots.2.available', true);

        $partial->delete();

        DoctorTimeOff::factory()->create([
            'doctor_id' => $doctor->getKey(),
            'cabinet_id' => $cabinet->getKey(),
            'starts_at' => $date->startOfDay(),
            'ends_at' => $date->addDay()->startOfDay(),
            'is_all_day' => true,
        ]);

        $this->getJson($this->dayUrl($doctor, $date))
            ->assertOk()
            ->assertJsonPath('reason', 'day_off')
            ->assertJsonPath('slots', []);
    }

    public function test_a_booking_blocks_its_slot_but_other_cabinets_never_do(): void
    {
        [$cabinet, $doctor, $date] = $this->makeBookableClinic();

        $this->makeAppointment($cabinet, $date->setTime(9, 0), $date->setTime(9, 30));

        // Same time-range appointment in ANOTHER cabinet must not leak into
        // this doctor's calendar (appointments are matched per cabinet).
        ['cabinet' => $otherCabinet] = $this->makeListedClinic();
        $this->makeAppointment($otherCabinet, $date->setTime(9, 30), $date->setTime(10, 0));

        $this->getJson($this->dayUrl($doctor, $date))
            ->assertOk()
            ->assertJsonPath('slots.0.available', false)
            ->assertJsonPath('slots.0.reason', 'booked')
            ->assertJsonPath('slots.1.available', true)
            ->assertJsonPath('slots.1.reason', null);
    }

    public function test_the_service_contract_used_by_booking_and_reschedule(): void
    {
        [$cabinet, $doctor, $date] = $this->makeBookableClinic();
        $service = app(PublicAvailabilityService::class);
        $startsAt = $date->setTime(9, 0);

        $this->assertTrue($service->isSlotAvailable($doctor, $startsAt));
        $this->assertSame(30, $service->slotDurationFor($doctor, $startsAt));

        // Outside every working range the duration falls back to the profile.
        $this->assertSame(
            (int) $doctor->consultation_duration,
            $service->slotDurationFor($doctor, $date->setTime(20, 0)),
        );

        $appointment = $this->makeAppointment($cabinet, $startsAt, $startsAt->addMinutes(30));

        $this->assertFalse($service->isSlotAvailable($doctor, $startsAt));
        // A reschedule may keep its own current slot: the appointment's own
        // block is ignored when passed explicitly.
        $this->assertTrue($service->isSlotAvailable($doctor, $startsAt, $appointment));
    }

    public function test_unlisted_or_inactive_doctors_are_404_never_200(): void
    {
        [$cabinet, $doctor, $date] = $this->makeBookableClinic();

        CabinetPublicProfile::withoutCabinetScope()
            ->where('cabinet_id', $cabinet->getKey())
            ->update(['is_listed' => false]);

        $this->getJson($this->monthUrl($doctor, $date))->assertNotFound();
        $this->getJson($this->dayUrl($doctor, $date))->assertNotFound();

        [, $inactiveDoctor, $otherDate] = $this->makeBookableClinic();
        $inactiveDoctor->forceFill(['is_active' => false])->save();

        $this->getJson($this->dayUrl($inactiveDoctor, $otherDate))->assertNotFound();
        $this->getJson('/api/v1/doctors/999999/availability/day?date='.$otherDate->toDateString())
            ->assertNotFound();
    }

    public function test_a_staff_token_of_another_cabinet_still_reads_the_public_calendar(): void
    {
        [, $doctor, $date] = $this->makeBookableClinic();
        ['doctorUser' => $otherStaff] = $this->makeListedClinic();

        Sanctum::actingAs($otherStaff);

        $this->getJson($this->dayUrl($doctor, $date))
            ->assertOk()
            ->assertJsonCount(4, 'slots');
    }

    public function test_month_and_day_parameters_are_validated(): void
    {
        [, $doctor, $date] = $this->makeBookableClinic();

        $this->getJson('/api/v1/doctors/'.$doctor->getKey().'/availability/month')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['year', 'month']);

        $this->getJson('/api/v1/doctors/'.$doctor->getKey().'/availability/day?date=31-12-2030')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date']);
    }

    /**
     * An active, listed clinic whose doctor works next Monday 09:00-11:00 in
     * 30-minute slots. The date returned is always in the future.
     *
     * @return array{0: Cabinet, 1: DoctorProfile, 2: CarbonImmutable}
     */
    private function makeBookableClinic(bool $openMonth = true): array
    {
        ['cabinet' => $cabinet, 'doctor' => $doctor] = $this->makeListedClinic();

        $date = CarbonImmutable::now()->addWeek()->startOfWeek();

        DoctorSchedule::factory()->create([
            'doctor_id' => $doctor->getKey(),
            'cabinet_id' => $cabinet->getKey(),
            'day_of_week' => Weekday::MONDAY,
            'starts_at' => '09:00:00',
            'ends_at' => '11:00:00',
            'slot_duration' => 30,
        ]);

        if ($openMonth) {
            DoctorOpenMonth::factory()->create([
                'doctor_id' => $doctor->getKey(),
                'cabinet_id' => $cabinet->getKey(),
                'year' => (int) $date->year,
                'month' => (int) $date->month,
                'is_open' => true,
            ]);
        }

        return [$cabinet, $doctor, $date];
    }

    private function makeAppointment(Cabinet $cabinet, CarbonImmutable $startsAt, CarbonImmutable $endsAt): Appointment
    {
        return Appointment::factory()->create([
            'cabinet_id' => $cabinet->getKey(),
            'patient_id' => Patient::factory()->create(['cabinet_id' => $cabinet->getKey()])->getKey(),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);
    }

    private function monthUrl(DoctorProfile $doctor, CarbonImmutable $date): string
    {
        return sprintf(
            '/api/v1/doctors/%d/availability/month?year=%d&month=%d',
            $doctor->getKey(),
            $date->year,
            $date->month,
        );
    }

    private function dayUrl(DoctorProfile $doctor, CarbonImmutable $date): string
    {
        return sprintf(
            '/api/v1/doctors/%d/availability/day?date=%s',
            $doctor->getKey(),
            $date->toDateString(),
        );
    }
}
