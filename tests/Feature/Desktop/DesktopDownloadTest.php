<?php

namespace Tests\Feature\Desktop;

use App\Models\DoctorProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class DesktopDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_desktop_installer_returns_not_found(): void
    {
        config([
            'medismart.desktop_download.url' => null,
            'medismart.desktop_download.installer_path' => 'missing.exe',
        ]);

        $this->get(route('desktop.download'))->assertNotFound();
    }

    public function test_external_desktop_download_url_redirects(): void
    {
        config([
            'medismart.desktop_download.url' => 'https://downloads.example.test/MediSmart-Setup.exe',
            'medismart.desktop_download.installer_path' => 'missing.exe',
        ]);

        $this->get(route('desktop.download'))
            ->assertRedirect('https://downloads.example.test/MediSmart-Setup.exe');
    }

    public function test_invalid_external_desktop_download_url_is_ignored(): void
    {
        config([
            'medismart.desktop_download.url' => 'javascript:alert(1)',
            'medismart.desktop_download.installer_path' => 'missing.exe',
        ]);

        $this->get(route('desktop.download'))->assertNotFound();
    }

    public function test_local_desktop_installer_downloads_from_private_storage(): void
    {
        $directory = storage_path('app/private/desktop');
        $path = $directory.'/MediSmart-Test.zip';

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($path, 'desktop-installer');

        config([
            'medismart.desktop_download.url' => null,
            'medismart.desktop_download.installer_path' => 'MediSmart-Test.zip',
        ]);

        $this->get(route('desktop.download'))
            ->assertOk()
            ->assertDownload('MediSmart-Test.zip');
    }

    public function test_desktop_download_state_is_shared_with_inertia_pages(): void
    {
        $user = User::factory()->create();
        DoctorProfile::factory()->for($user)->create([
            'specialty' => 'General Medicine',
            'specialty_code' => 'general_medicine',
        ]);

        config([
            'medismart.desktop_download.url' => 'https://downloads.example.test/MediSmart-Setup.exe',
            'medismart.desktop_download.installer_path' => 'missing.exe',
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('desktopDownload.available', true)
                ->where('desktopDownload.url', route('desktop.download'))
                ->where('desktopDownload.label', 'Télécharger l’app desktop'));
    }
}
