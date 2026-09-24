<?php

namespace Tests\Feature\Patients;

use App\Enums\RoleName;
use App\Models\Patient;
use App\Models\PatientVaccination;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class VaccinationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();
        $user->assignRole(RoleName::ASSISTANT->value);
        $this->actingAs($user);
    }

    public function test_child_calendar_shows_done_due_and_overdue_doses(): void
    {
        // Five months old: birth, 2-month and 4-month doses are expected.
        $baby = Patient::factory()->create(['date_of_birth' => '2026-01-10']);

        $this->post(route('app.patients.vaccinations.store', $baby), [
            'schedule_key' => 'birth:bcg',
            'given_on' => '2026-01-11',
            'lot' => 'B123',
        ])->assertSessionHasNoErrors();
        $this->post(route('app.patients.vaccinations.store', $baby), [
            'schedule_key' => 'nope:x',
            'given_on' => '2026-01-11',
        ])->assertSessionHasErrors('schedule_key');
        $this->post(route('app.patients.vaccinations.store', $baby), [
            'vaccine' => 'Grippe saisonnière',
            'given_on' => '2026-07-01',
        ])->assertSessionHasErrors('given_on');

        $this->assertSame('BCG', PatientVaccination::query()->sole()->vaccine);

        $this->get(route('app.patients.show', $baby))
            ->assertInertia(fn (Assert $page) => $page
                ->where('vaccinations.records.0.vaccine', 'BCG')
                ->where('vaccinations.schedule.0.doses.0.status', 'done')
                // Birth HBV/VPO: more than a month late.
                ->where('vaccinations.schedule.0.doses.1.status', 'overdue')
                // 4-month doses (due 10 May) are late since 10 June.
                ->where('vaccinations.schedule.2.doses.0.status', 'overdue')
                // 11-month dose not yet due.
                ->where('vaccinations.schedule.3.doses.0.status', 'upcoming')
                ->where('vaccinations.overdue', 9)
            );

        $this->get(route('app.patients.vaccinations.print', $baby))
            ->assertOk()
            ->assertSee('CARNET DE VACCINATION')
            ->assertSee('BCG')
            ->assertSee('B123');
    }

    public function test_adults_get_a_free_record_without_the_child_calendar(): void
    {
        $adult = Patient::factory()->create(['date_of_birth' => '1970-03-02']);

        $this->post(route('app.patients.vaccinations.store', $adult), [
            'vaccine' => 'Grippe saisonnière',
            'dose' => 'Annuelle',
            'given_on' => '2025-11-02',
        ])->assertSessionHasNoErrors();

        $this->get(route('app.patients.show', $adult))
            ->assertInertia(fn (Assert $page) => $page
                ->has('vaccinations.schedule', 0)
                ->where('vaccinations.records.0.dose', 'Annuelle')
            );

        $vaccination = PatientVaccination::query()->sole();
        $this->delete(route('app.vaccinations.destroy', $vaccination))->assertSessionHasNoErrors();
        $this->assertSame(0, PatientVaccination::query()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'patient.vaccination_deleted']);
    }
}
