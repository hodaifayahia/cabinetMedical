<?php

namespace Tests\Feature\Api\Mobile\Discovery;

use App\Filament\Resources\Cabinets\Pages\ListCabinets;
use App\Models\Cabinet;
use App\Models\CabinetPublicProfile;
use App\Models\User;
use App\Models\Wilaya;
use App\Services\Cabinet\CabinetProvisioningService;
use App\Services\CabinetFulfillmentService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\MobileTestHelpers;
use Tests\TestCase;

/**
 * A doctor created anywhere — web registration, the web back office, the
 * mobile back office — must reach the patient app the moment the cabinet is
 * active, without a second "list it" step.
 */
class NewCabinetReachesAppTest extends TestCase
{
    use MobileTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Mail::fake();
        Wilaya::factory()->create(['code' => 16]);
    }

    private function provision(string $email = 'new.doctor@clinic.dz'): Cabinet
    {
        $owner = app(CabinetProvisioningService::class)->provision([
            'name' => 'Dr Salima Rahmani',
            'email' => $email,
            'password' => 'mot-de-passe-solide-2026',
            'phone' => '0550998877',
            'cabinet_name' => 'Cabinet Rahmani',
            'specialization' => 'Cardiologie',
            'wilaya' => 16,
        ]);

        return Cabinet::query()->withoutGlobalScopes()->findOrFail($owner->cabinet_id);
    }

    #[Test]
    public function an_activated_cabinet_appears_in_search_immediately(): void
    {
        $cabinet = $this->provision();

        // Pending cabinets never reach patients.
        $this->getJson('/api/v1/doctors')->assertOk()->assertJsonCount(0, 'data');

        app(CabinetFulfillmentService::class)->activate($cabinet);

        $this->getJson('/api/v1/doctors?wilaya_code=16&specialty=cardiology')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.clinic.id', $cabinet->getKey());
    }

    #[Test]
    public function the_mobile_back_office_can_still_create_a_hidden_clinic(): void
    {
        Sanctum::actingAs($this->makePlatformAdmin(), ['mobile']);

        $this->postJson('/api/v1/admin/cabinets', [
            'cabinet_name' => 'Cabinet Masqué',
            'specialization' => 'Cardiologie',
            'wilaya_code' => 16,
            'doctor_name' => 'Dr Hidden',
            'email' => 'hidden@clinic.dz',
            'phone' => '0550112244',
            'password' => 'mot-de-passe-solide-2026',
            'activate' => true,
            'is_listed' => false,
        ])->assertCreated()->assertJsonPath('data.is_listed', false);

        $this->getJson('/api/v1/doctors')->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function the_web_back_office_can_hide_and_show_a_cabinet(): void
    {
        $cabinet = $this->provision();
        app(CabinetFulfillmentService::class)->activate($cabinet);

        $admin = User::factory()->create(['is_platform_admin' => true, 'cabinet_id' => null]);
        $this->actingAs($admin);

        Livewire::test(ListCabinets::class)->callTableAction('toggleMobileListing', $cabinet);

        $this->assertFalse($this->listed($cabinet));
        $this->getJson('/api/v1/doctors')->assertJsonCount(0, 'data');
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.cabinet_listing_updated']);

        Livewire::test(ListCabinets::class)->callTableAction('toggleMobileListing', $cabinet);

        $this->assertTrue($this->listed($cabinet));
        $this->getJson('/api/v1/doctors')->assertJsonCount(1, 'data');
    }

    private function listed(Cabinet $cabinet): bool
    {
        return (bool) CabinetPublicProfile::withoutCabinetScope()
            ->where('cabinet_id', $cabinet->getKey())
            ->value('is_listed');
    }
}
