<?php

namespace Tests\Feature\Filament;

use App\Enums\CabinetStatus;
use App\Enums\LicensePlan;
use App\Filament\Pages\PlatformDashboard;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Widgets\AdminOverview;
use App\Filament\Widgets\PendingCabinets;
use App\Models\Cabinet;
use App\Models\CabinetSetting;
use App\Models\License;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PlatformDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    public function test_the_platform_dashboard_and_widgets_are_registered(): void
    {
        $panel = Filament::getPanel('admin');

        $this->assertContains(PlatformDashboard::class, $panel->getPages());
        $this->assertContains(AdminOverview::class, $panel->getWidgets());
        $this->assertContains(PendingCabinets::class, $panel->getWidgets());
    }

    public function test_only_platform_administrators_can_open_the_dashboard(): void
    {
        $platformAdmin = User::factory()->create(['is_platform_admin' => true]);
        $cabinetUser = User::factory()->create(['is_platform_admin' => false]);

        $this->get('/admin')
            ->assertRedirect('/admin/login');

        $this->actingAs($cabinetUser)
            ->get('/admin')
            ->assertForbidden();

        $this->actingAs($platformAdmin)
            ->get('/admin')
            ->assertOk()
            ->assertSee('Pilotage de la plateforme')
            ->assertSee('Gérer les cabinets');
    }

    public function test_the_overview_reports_cabinet_and_license_states(): void
    {
        $platformAdmin = User::factory()->create(['is_platform_admin' => true]);
        $this->actingAs($platformAdmin);

        Cabinet::query()->create([
            'name' => 'Cabinet en attente',
            'status' => CabinetStatus::PENDING,
        ]);
        Cabinet::query()->create([
            'name' => 'Cabinet suspendu',
            'status' => CabinetStatus::SUSPENDED,
        ]);
        $this->licensedCabinet('Essai actif', LicensePlan::TRIAL, now()->addDays(3));
        $this->licensedCabinet('Licence à vie', LicensePlan::LIFETIME);
        $this->licensedCabinet('Essai expiré', LicensePlan::TRIAL, now()->subMinute());

        $widget = new class extends AdminOverview
        {
            public function statsForTesting(): array
            {
                return $this->getStats();
            }
        };

        $stats = collect($widget->statsForTesting())
            ->mapWithKeys(fn ($stat): array => [(string) $stat->getLabel() => (int) $stat->getValue()]);

        $this->assertSame([
            'En attente' => 1,
            'Accès autorisés' => 2,
            'Suspendus' => 1,
            'Essais en cours' => 1,
            'Licences à vie' => 1,
            'Licences expirées' => 1,
            'Demandes desktop' => 0,
        ], $stats->all());
    }

    public function test_the_recent_pending_table_includes_contact_details_and_excludes_other_states(): void
    {
        $platformAdmin = User::factory()->create(['is_platform_admin' => true]);
        $owner = User::factory()->create([
            'name' => 'Dr Pending',
            'email' => 'pending-owner@example.com',
        ]);
        $pending = Cabinet::query()->create([
            'name' => 'Cabinet Atlas',
            'status' => CabinetStatus::PENDING,
            'owner_user_id' => $owner->getKey(),
            'specialization' => 'Cardiologie',
            'wilaya_code' => 16,
        ]);
        $owner->forceFill(['cabinet_id' => $pending->getKey()])->save();
        CabinetSetting::query()->create([
            ...CabinetSetting::defaults(),
            'cabinet_id' => $pending->getKey(),
            'name' => $pending->name,
            'phone' => '0550 00 00 00',
        ]);

        $active = Cabinet::query()->create([
            'name' => 'Cabinet déjà actif',
            'status' => CabinetStatus::ACTIVE,
        ]);

        Livewire::actingAs($platformAdmin)
            ->test(PendingCabinets::class)
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$active])
            ->assertSee('Dr Pending')
            ->assertSee('pending-owner@example.com')
            ->assertSee('0550 00 00 00')
            ->assertSee('Cardiologie')
            ->assertSee('16 - Alger')
            ->assertSee('Attribuer une licence')
            ->assertSee('Gérer tous les cabinets');
    }

    public function test_dashboard_components_reject_non_platform_users(): void
    {
        $cabinetUser = User::factory()->create(['is_platform_admin' => false]);
        $platformAdmin = User::factory()->create(['is_platform_admin' => true]);

        $this->actingAs($cabinetUser);
        $this->assertFalse(PlatformDashboard::canAccess());
        $this->assertFalse(AdminOverview::canView());
        $this->assertFalse(PendingCabinets::canView());

        $this->actingAs($platformAdmin);
        $this->assertTrue(PlatformDashboard::canAccess());
        $this->assertTrue(AdminOverview::canView());
        $this->assertTrue(PendingCabinets::canView());
    }

    public function test_platform_user_directory_is_read_only_and_cannot_create_unscoped_accounts(): void
    {
        $platformAdmin = User::factory()->create(['is_platform_admin' => true]);
        $tenantUser = User::factory()->create();
        $this->actingAs($platformAdmin);

        $this->assertFalse(UserResource::canCreate());
        $this->assertFalse(UserResource::canEdit($tenantUser));
        $this->assertFalse(UserResource::canDelete($tenantUser));
        $this->assertFalse(UserResource::canDeleteAny());
        $this->assertSame(['index', 'view'], array_keys(UserResource::getPages()));

        $this->get('/admin/users/create')->assertNotFound();
        $this->get("/admin/users/{$tenantUser->getKey()}/edit")->assertNotFound();
    }

    private function licensedCabinet(string $name, LicensePlan $plan, mixed $expiresAt = null): Cabinet
    {
        $license = License::query()->create([
            'license_id' => 'TEST-'.str()->uuid(),
            'product' => 'DrClickDz',
            'edition' => 'hosted',
            'plan' => $plan,
            'status' => 'active',
            'issued_at' => now(),
            'expires_at' => $expiresAt,
        ]);

        return Cabinet::query()->create([
            'name' => $name,
            'status' => CabinetStatus::ACTIVE,
            'license_id' => $license->getKey(),
        ]);
    }
}
