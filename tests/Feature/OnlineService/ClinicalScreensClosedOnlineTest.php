<?php

namespace Tests\Feature\OnlineService;

use App\Enums\CabinetStatus;
use App\Enums\RoleName;
use App\Models\Cabinet;
use App\Models\Patient;
use App\Models\User;
use App\Support\ClinicalWorkstation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Patient records belong on the cabinet's PC: the online service closes its
 * clinical screens and keeps only the account, licence and staff screens.
 */
class ClinicalScreensClosedOnlineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        config([
            'medismart.runtime.desktop_supervised' => false,
            'hub.enabled' => false,
            'medismart.hosted.clinical_enabled' => false,
        ]);
    }

    private function owner(): User
    {
        $cabinet = Cabinet::query()->create([
            'name' => 'Cabinet en ligne',
            'status' => CabinetStatus::ACTIVE,
            'activated_at' => now(),
        ]);
        $owner = User::factory()->create([
            'cabinet_id' => $cabinet->getKey(),
            'approved_at' => now(),
        ]);
        $owner->assignRole(RoleName::ADMINISTRATOR->value);
        $cabinet->forceFill(['owner_user_id' => $owner->getKey()])->save();

        return $owner;
    }

    public function test_patient_screens_send_the_cabinet_to_its_online_space(): void
    {
        $this->actingAs($this->owner());

        $this->get('/dashboard')->assertRedirect('/espace-cabinet');
        $this->get('/app/patients')->assertRedirect('/espace-cabinet');
        $this->get('/app/appointments')->assertRedirect('/espace-cabinet');
        $this->get('/app/payments')->assertRedirect('/espace-cabinet');
    }

    public function test_json_calls_to_patient_endpoints_are_refused(): void
    {
        $this->actingAs($this->owner());

        $this->postJson('/app/patients/json', ['first_name' => 'A', 'last_name' => 'B'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Les dossiers patients sont gérés dans l’application Drclick installée sur le PC du cabinet.');

        $this->assertSame(0, Patient::query()->withoutGlobalScopes()->count());
    }

    public function test_account_screens_stay_available_online(): void
    {
        $this->actingAs($this->owner());

        $this->get('/app/staff')->assertOk();
        $this->get('/app/configuration/connectivity-backup')->assertOk();
    }

    public function test_the_online_space_counts_the_records_still_on_the_server(): void
    {
        $this->actingAs($this->owner());
        Patient::factory()->count(2)->create();

        $this->get('/espace-cabinet')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('OnlineSpace')
                ->where('clinicalScreensOpen', false)
                ->where('recordsOnline.patients', 2)
                ->where('cabinet.transferred_at', null));
    }

    public function test_the_desktop_and_the_hub_keep_every_screen(): void
    {
        config(['medismart.runtime.desktop_supervised' => true]);
        $this->assertTrue(ClinicalWorkstation::clinicalScreensOpen());

        config(['medismart.runtime.desktop_supervised' => false, 'hub.enabled' => true]);
        $this->assertTrue(ClinicalWorkstation::clinicalScreensOpen());

        config(['hub.enabled' => false]);
        $this->assertFalse(ClinicalWorkstation::clinicalScreensOpen());
    }

    public function test_the_online_service_can_reopen_its_clinical_screens(): void
    {
        config(['medismart.hosted.clinical_enabled' => true]);
        $this->actingAs($this->owner());

        $this->get('/app/patients')->assertOk();
    }
}
