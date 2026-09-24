<?php

namespace Tests\Feature\Appointments;

use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\DoctorProfile;
use App\Models\DoctorSchedule;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Api\Concerns\BuildsCabinets;
use Tests\TestCase;

/**
 * A cabinet created before sign-up provisioned the doctor profile could never
 * book: the agenda said "Aucun médecin actif" and no screen could create one.
 */
class DoctorProfileRepairTest extends TestCase
{
    use BuildsCabinets, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_a_cabinet_without_a_doctor_profile_gets_one_with_default_hours(): void
    {
        [$cabinet, $doctor] = $this->activeCabinetWithOwner('legacy@example.com');
        $this->assertFalse(DoctorProfile::withoutCabinetScope()->where('cabinet_id', $cabinet->getKey())->exists());

        $this->actingAs($doctor)
            ->get(route('app.appointments.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('hasDoctor', true));

        $profile = DoctorProfile::withoutCabinetScope()->where('cabinet_id', $cabinet->getKey())->sole();
        $this->assertSame($doctor->getKey(), $profile->user_id);
        $this->assertTrue((bool) $profile->is_active);
        $this->assertSame(5, DoctorSchedule::withoutCabinetScope()->where('doctor_id', $profile->getKey())->count());
        $this->assertTrue(AuditLog::query()->where('action', 'doctor_profile.provisioned')->exists());

        // Idempotent: a second visit reuses the same profile.
        $this->get(route('app.appointments.index'))->assertOk();
        $this->assertSame(1, DoctorProfile::withoutCabinetScope()->where('cabinet_id', $cabinet->getKey())->count());
    }

    public function test_a_deliberately_deactivated_doctor_is_not_recreated(): void
    {
        [$cabinet, $doctor] = $this->activeCabinetWithOwner('inactive@example.com');
        $this->actingAs($doctor);
        DoctorProfile::factory()->create(['user_id' => $doctor->getKey(), 'is_active' => false]);

        $this->get(route('app.appointments.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('hasDoctor', false));

        $this->assertSame(1, DoctorProfile::withoutCabinetScope()->where('cabinet_id', $cabinet->getKey())->count());
    }

    public function test_an_assistant_opening_the_agenda_gets_the_cabinet_doctor_profile(): void
    {
        [$cabinet, $doctor] = $this->activeCabinetWithOwner('assist@example.com');
        $assistant = User::factory()->create(['cabinet_id' => $cabinet->getKey(), 'approved_at' => now()]);
        $assistant->assignRole(RoleName::ASSISTANT->value);

        $this->actingAs($assistant)->get(route('app.appointments.index'))->assertOk();

        $this->assertSame(
            $doctor->getKey(),
            DoctorProfile::withoutCabinetScope()->where('cabinet_id', $cabinet->getKey())->value('user_id'),
            'The profile belongs to the doctor, never to the assistant who opened the page.',
        );
    }

    public function test_the_booking_dialog_opens_a_month_in_place(): void
    {
        [, $doctor] = $this->activeCabinetWithOwner('open@example.com');
        $this->actingAs($doctor);
        $next = now()->addMonthNoOverflow();

        $this->getJson(route('app.appointments.availability.month', ['year' => $next->year, 'month' => $next->month]))
            ->assertOk()
            ->assertJsonPath('is_open_month', false);

        $this->postJson(route('app.appointments.open-months.store'), ['year' => $next->year, 'month' => $next->month])
            ->assertOk()
            ->assertJsonPath('opened', true);

        $this->getJson(route('app.appointments.availability.month', ['year' => $next->year, 'month' => $next->month]))
            ->assertOk()
            ->assertJsonPath('is_open_month', true);
    }
}
