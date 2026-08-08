<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\DesktopDownloadLeads\DesktopDownloadLeadResource;
use App\Filament\Resources\DesktopDownloadLeads\Pages\ListDesktopDownloadLeads;
use App\Models\DesktopDownloadLead;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

final class DesktopDownloadLeadResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    public function test_platform_admin_can_review_desktop_leads_in_a_read_only_table(): void
    {
        $platformAdmin = User::factory()->create(['is_platform_admin' => true]);
        $lead = DesktopDownloadLead::query()->create($this->leadAttributes());
        $downloaded = DesktopDownloadLead::query()->create([
            ...$this->leadAttributes(),
            'email' => 'amine@example.dz',
            'name' => 'Dr Amine Bensaid',
            'downloaded_at' => now(),
        ]);

        $this->actingAs($platformAdmin);

        $this->assertTrue(DesktopDownloadLeadResource::canAccess());
        $this->assertFalse(DesktopDownloadLeadResource::canCreate());
        $this->assertFalse(DesktopDownloadLeadResource::canEdit($lead));
        $this->assertFalse(DesktopDownloadLeadResource::canDelete($lead));
        $this->assertFalse(DesktopDownloadLeadResource::canDeleteAny());
        $this->assertSame(['index'], array_keys(DesktopDownloadLeadResource::getPages()));

        $this->get(DesktopDownloadLeadResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Téléchargements desktop')
            ->assertSee('Dr Nadia Benali')
            ->assertSee('nadia@example.dz');

        Livewire::actingAs($platformAdmin)
            ->test(ListDesktopDownloadLeads::class)
            ->assertCanSeeTableRecords([$lead, $downloaded]);
    }

    public function test_cabinet_users_cannot_open_the_platform_lead_directory(): void
    {
        $cabinetUser = User::factory()->create(['is_platform_admin' => false]);

        $this->actingAs($cabinetUser);

        $this->assertFalse(DesktopDownloadLeadResource::canAccess());
        $this->get(DesktopDownloadLeadResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_no_download_authorization_secret_is_persisted_with_a_lead(): void
    {
        $this->assertFalse(Schema::hasColumn('desktop_download_leads', 'token'));
        $this->assertFalse(Schema::hasColumn('desktop_download_leads', 'download_url'));
        $this->assertFalse(Schema::hasColumn('desktop_download_leads', 'storage_path'));
    }

    /** @return array<string, mixed> */
    private function leadAttributes(): array
    {
        return [
            'name' => 'Dr Nadia Benali',
            'email' => 'nadia@example.dz',
            'phone' => '0555 12 34 56',
            'cabinet_name' => 'Cabinet El Amal',
            'specialization' => 'Cardiologie',
        ];
    }
}
