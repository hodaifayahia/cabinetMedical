<?php

namespace Tests\Feature\Api\Mobile\Admin;

use App\Enums\AppointmentStatus;
use App\Enums\CabinetStatus;
use App\Enums\RoleName;
use App\Models\Appointment;
use App\Models\Cabinet;
use App\Models\CabinetPublicProfile;
use App\Models\Patient;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MobileTestHelpers;
use Tests\TestCase;

/**
 * The platform dashboard counts the whole platform, not one tenant.
 *
 * That is the load-bearing assertion here: a platform admin has
 * cabinet_id = null, which makes the BelongsToCabinet scope inert, so a
 * regression that reintroduced tenant scoping would not fail loudly — it would
 * quietly return one clinic's numbers. Every fixture below therefore spans two
 * clinics and the expected totals are the sums.
 */
class AdminOverviewTest extends TestCase
{
    use MobileTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_the_counters_aggregate_across_every_cabinet(): void
    {
        $first = $this->makeListedClinic();
        $second = $this->makeListedClinic();

        $pending = Cabinet::query()->create([
            'name' => 'Cabinet En Attente',
            'status' => CabinetStatus::PENDING,
            'wilaya_code' => 31,
        ]);
        Cabinet::query()->create([
            'name' => 'Cabinet Suspendu',
            'status' => CabinetStatus::SUSPENDED,
            'wilaya_code' => 31,
        ]);

        // One receptionist in each of the two listed clinics.
        foreach ([$first, $second] as $clinic) {
            $reception = User::factory()->create([
                'cabinet_id' => $clinic['cabinet']->getKey(),
                'approved_at' => now(),
            ]);
            $reception->assignRole(RoleName::ASSISTANT->value);
        }

        // Two dossiers in the first clinic, one in the second.
        $patients = [];
        foreach ([$first, $first, $second] as $clinic) {
            $patients[] = Patient::factory()->create([
                'cabinet_id' => $clinic['cabinet']->getKey(),
            ]);
        }

        // appointment_date is derived from starts_at on save, so the slot is
        // what decides which day an appointment lands on.
        $today = now()->setTime(9, 0);

        Appointment::factory()->create([
            'cabinet_id' => $first['cabinet']->getKey(),
            'patient_id' => $patients[0]->getKey(),
            'starts_at' => $today,
            'ends_at' => $today->addMinutes(30),
        ]);
        Appointment::factory()->create([
            'cabinet_id' => $second['cabinet']->getKey(),
            'patient_id' => $patients[2]->getKey(),
            'starts_at' => $today->addDays(3),
            'ends_at' => $today->addDays(3)->addMinutes(30),
        ]);
        // A cancelled appointment is not platform activity.
        Appointment::factory()->create([
            'cabinet_id' => $second['cabinet']->getKey(),
            'patient_id' => $patients[2]->getKey(),
            'starts_at' => $today->addHour(),
            'ends_at' => $today->addHour()->addMinutes(30),
            'status' => AppointmentStatus::CANCELLED,
        ]);

        Sanctum::actingAs($this->makePlatformAdmin(), ['mobile']);

        $this->getJson('/api/v1/admin/overview')
            ->assertOk()
            ->assertJsonPath('data.cabinets.total', 4)
            ->assertJsonPath('data.cabinets.active', 2)
            ->assertJsonPath('data.cabinets.pending', 1)
            ->assertJsonPath('data.cabinets.suspended', 1)
            ->assertJsonPath('data.doctors', 2)
            ->assertJsonPath('data.staff', 2)
            ->assertJsonPath('data.patients', 3)
            ->assertJsonPath('data.appointments.today', 1)
            ->assertJsonPath('data.appointments.upcoming', 1)
            ->assertJsonPath('data.listed_clinics', 2)
            ->assertJsonCount(4, 'data.recent_cabinets')
            // Newest first, so the suspended clinic created last leads.
            ->assertJsonPath('data.recent_cabinets.0.name', 'Cabinet Suspendu')
            ->assertJsonPath('data.recent_cabinets.1.id', $pending->getKey())
            ->assertJsonPath('data.recent_cabinets.1.status', 'pending');
    }

    public function test_platform_and_patient_accounts_are_not_counted_as_clinic_staff(): void
    {
        $this->makeListedClinic();
        $this->makePatientUser();
        $this->makePlatformAdmin();

        Sanctum::actingAs($this->makePlatformAdmin(), ['mobile']);

        $this->getJson('/api/v1/admin/overview')
            ->assertOk()
            ->assertJsonPath('data.doctors', 1)
            ->assertJsonPath('data.staff', 0);
    }

    /**
     * Regression: `listed_clinics` counted public-profile rows alone, so a
     * clinic provisioned with `is_listed: true, activate: false` — or an active
     * listed clinic that was later suspended — inflated the tile while
     * GET /doctors, which requires status=active AND is_listed, showed nothing.
     */
    public function test_listed_clinics_counts_only_what_public_discovery_shows(): void
    {
        $active = $this->makeListedClinic();
        $suspended = $this->makeListedClinic();
        $suspended['cabinet']->forceFill(['status' => CabinetStatus::SUSPENDED])->save();

        // Listed on a clinic that was never activated.
        $pending = Cabinet::query()->create([
            'name' => 'Cabinet Listé En Attente',
            'status' => CabinetStatus::PENDING,
            'wilaya_code' => 31,
        ]);
        CabinetPublicProfile::factory()->listed()->create(['cabinet_id' => $pending->getKey()]);

        Sanctum::actingAs($this->makePlatformAdmin(), ['mobile']);

        $this->getJson('/api/v1/admin/overview')
            ->assertOk()
            ->assertJsonPath('data.listed_clinics', 1);

        // The public directory agrees: one clinic, the active one.
        $this->getJson('/api/v1/doctors')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.clinic.id', $active['cabinet']->getKey());
    }

    public function test_the_overview_never_leaks_credentials(): void
    {
        $this->makeListedClinic();

        Sanctum::actingAs($this->makePlatformAdmin(), ['mobile']);

        $body = $this->getJson('/api/v1/admin/overview')->assertOk()->getContent() ?: '';

        foreach (['password', 'remember_token', 'two_factor', 'local_pin_hash', 'signed_certificate'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }

        $this->assertStringNotContainsString('$2y$', $body);
    }

    public function test_the_cabinet_list_spans_every_tenant_and_filters(): void
    {
        $first = $this->makeListedClinic();
        Cabinet::query()->create([
            'name' => 'Clinique Ophta Oran',
            'status' => CabinetStatus::PENDING,
            'wilaya_code' => 31,
        ]);

        Sanctum::actingAs($this->makePlatformAdmin(), ['mobile']);

        $this->getJson('/api/v1/admin/cabinets')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);

        $this->getJson('/api/v1/admin/cabinets?status=pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Clinique Ophta Oran')
            ->assertJsonPath('data.0.is_listed', false);

        $this->getJson('/api/v1/admin/cabinets?wilaya_code=16')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $first['cabinet']->getKey())
            ->assertJsonPath('data.0.is_listed', true);

        $this->getJson('/api/v1/admin/cabinets?q=Ophta')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Clinique Ophta Oran');

        $this->getJson('/api/v1/admin/cabinets?q='.urlencode($first['doctorUser']->email))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $first['cabinet']->getKey());
    }

    public function test_the_cabinet_detail_reports_live_counts_for_that_clinic_only(): void
    {
        $first = $this->makeListedClinic();
        $second = $this->makeListedClinic();

        Patient::factory()->count(2)->create(['cabinet_id' => $first['cabinet']->getKey()]);
        Patient::factory()->create(['cabinet_id' => $second['cabinet']->getKey()]);

        Sanctum::actingAs($this->makePlatformAdmin(), ['mobile']);

        $this->getJson('/api/v1/admin/cabinets/'.$first['cabinet']->getKey())
            ->assertOk()
            ->assertJsonPath('data.counts.patients', 2)
            ->assertJsonPath('data.counts.staff', 1)
            ->assertJsonPath('data.counts.appointments', 0)
            ->assertJsonPath('data.owner.id', $first['doctorUser']->getKey())
            ->assertJsonPath('data.license', null);
    }
}
