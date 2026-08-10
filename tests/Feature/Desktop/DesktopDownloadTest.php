<?php

namespace Tests\Feature\Desktop;

use App\Models\DesktopDownloadLead;
use App\Models\DoctorProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class DesktopDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_desktop_installer_uses_the_drclick_filename(): void
    {
        $this->assertSame(
            'DrClickDz-Desktop-Setup.exe',
            config('medismart.desktop_download.installer_path'),
        );
    }

    public function test_missing_desktop_installer_returns_not_found(): void
    {
        $this->configureMissingInstaller();

        $this->get(route('desktop.download'))->assertNotFound();

        $this->post(route('desktop.download.store'), $this->validLead())
            ->assertNotFound();

        $this->assertDatabaseCount('desktop_download_leads', 0);
    }

    public function test_download_gateway_never_redirects_directly_to_the_installer(): void
    {
        $this->configureExternalInstaller();

        $this->get(route('desktop.download'))
            ->assertRedirect(route('home', ['download' => 1]).'#telecharger');
    }

    public function test_invalid_external_desktop_download_url_is_ignored(): void
    {
        config([
            'medismart.desktop_download.url' => 'javascript:alert(1)',
            'medismart.desktop_download.installer_path' => 'missing.exe',
        ]);

        $this->get(route('desktop.download'))->assertNotFound();
    }

    public function test_lead_registration_returns_a_short_lived_download_for_this_session(): void
    {
        $this->configureExternalInstaller();

        $response = $this->post(
            route('desktop.download.store'),
            $this->validLead([
                'name' => '  Dr Nadia Benali  ',
                'email' => '  NADIA@EXAMPLE.DZ  ',
            ]),
            ['X-Inertia' => 'true'],
        );

        $response->assertStatus(409)->assertHeader('X-Inertia-Location');

        $downloadUrl = (string) $response->headers->get('X-Inertia-Location');
        $this->assertStringContainsString('/desktop/download/file/', $downloadUrl);
        $this->assertStringContainsString('expires=', $downloadUrl);
        $this->assertStringContainsString('signature=', $downloadUrl);
        $this->assertStringNotContainsString('NADIA', $downloadUrl);
        $this->assertStringNotContainsString('example.dz', $downloadUrl);
        $this->assertStringNotContainsString(storage_path(), $downloadUrl);

        $this->assertDatabaseHas('desktop_download_leads', [
            'name' => 'Dr Nadia Benali',
            'email' => 'nadia@example.dz',
            'phone' => '0555 12 34 56',
            'cabinet_name' => 'Cabinet El Amal',
            'specialization' => 'Cardiologie',
            'downloaded_at' => null,
        ]);

        $this->get($downloadUrl)
            ->assertRedirect('https://downloads.example.test/Drclick-Setup.exe');

        $this->assertNotNull(
            DesktopDownloadLead::query()->sole()->downloaded_at,
        );
    }

    public function test_local_installer_download_still_uses_private_storage_after_the_lead_gate(): void
    {
        $directory = storage_path('app/private/desktop');
        $path = $directory.'/Drclick-Test.zip';

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($path, 'desktop-installer');

        try {
            config([
                'medismart.desktop_download.url' => null,
                'medismart.desktop_download.installer_path' => 'Drclick-Test.zip',
            ]);

            $registration = $this->post(
                route('desktop.download.store'),
                $this->validLead(),
            )->assertRedirect();

            $this->get((string) $registration->headers->get('Location'))
                ->assertOk()
                ->assertDownload('Drclick-Test.zip');
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_unsigned_or_sessionless_file_routes_are_forbidden(): void
    {
        $this->configureExternalInstaller();
        $lead = DesktopDownloadLead::query()->create($this->validLead());

        $this->get(route('desktop.download.file', $lead))->assertForbidden();

        $signedUrl = URL::temporarySignedRoute(
            'desktop.download.file',
            now()->addMinutes(10),
            ['lead' => $lead],
        );

        $this->get($signedUrl)->assertForbidden();
    }

    public function test_an_expired_session_authorization_is_forbidden_even_with_a_valid_signature(): void
    {
        $this->configureExternalInstaller();
        $lead = DesktopDownloadLead::query()->create($this->validLead());
        $signedUrl = URL::temporarySignedRoute(
            'desktop.download.file',
            now()->addMinutes(10),
            ['lead' => $lead],
        );

        $this->withSession([
            "desktop_download.authorized.{$lead->getKey()}" => now()
                ->subMinute()
                ->getTimestamp(),
        ])->get($signedUrl)->assertForbidden();
    }

    public function test_a_missing_artifact_is_not_reported_as_downloaded(): void
    {
        $this->configureExternalInstaller();
        $registration = $this->post(
            route('desktop.download.store'),
            $this->validLead(),
        )->assertRedirect();

        $this->configureMissingInstaller();

        $this->get((string) $registration->headers->get('Location'))
            ->assertNotFound();
        $this->assertNull(
            DesktopDownloadLead::query()->sole()->downloaded_at,
        );
    }

    public function test_lead_fields_are_validated_and_the_honeypot_is_rejected(): void
    {
        $this->configureExternalInstaller();

        $this->from(route('home'))
            ->post(route('desktop.download.store'), [
                ...$this->validLead(),
                'email' => 'not-an-email',
                'phone' => 'phone number',
                'website' => 'https://spam.example',
            ])
            ->assertRedirect(route('home'))
            ->assertSessionHasErrors(['email', 'phone', 'website']);

        $this->assertDatabaseCount('desktop_download_leads', 0);
    }

    public function test_lead_registration_is_rate_limited(): void
    {
        $this->configureExternalInstaller();

        foreach (range(1, 5) as $attempt) {
            $this->post(
                route('desktop.download.store'),
                $this->validLead(['name' => "Dr Test {$attempt}"]),
            )->assertRedirect();
        }

        $this->post(route('desktop.download.store'), $this->validLead())
            ->assertTooManyRequests();
    }

    public function test_rotating_email_addresses_cannot_bypass_the_ip_limit(): void
    {
        $this->configureExternalInstaller();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.44']);

        foreach (range(1, 20) as $attempt) {
            $this->post(
                route('desktop.download.store'),
                $this->validLead([
                    'email' => "download-{$attempt}@example.dz",
                ]),
            )->assertRedirect();
        }

        $this->post(
            route('desktop.download.store'),
            $this->validLead(['email' => 'download-21@example.dz']),
        )->assertTooManyRequests();
    }

    public function test_signed_file_requests_are_rate_limited_per_lead_and_ip(): void
    {
        $this->configureExternalInstaller();
        $registration = $this->post(
            route('desktop.download.store'),
            $this->validLead(),
        )->assertRedirect();
        $downloadUrl = (string) $registration->headers->get('Location');

        foreach (range(1, 10) as $attempt) {
            $this->get($downloadUrl)
                ->assertRedirect('https://downloads.example.test/Drclick-Setup.exe');
        }

        $this->get($downloadUrl)->assertTooManyRequests();
    }

    public function test_desktop_download_state_shares_only_the_lead_gateway(): void
    {
        $user = User::factory()->create();
        DoctorProfile::factory()->for($user)->create([
            'specialty' => 'General Medicine',
            'specialty_code' => 'general_medicine',
        ]);

        $this->configureExternalInstaller();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('desktopDownload.available', true)
                ->where('desktopDownload.url', route('desktop.download'))
                ->where('desktopDownload.label', 'Télécharger l’app desktop')
                ->missing('desktopDownload.fileUrl')
                ->missing('desktopDownload.token'));
    }

    /** @param array<string, string> $overrides */
    private function validLead(array $overrides = []): array
    {
        return [
            'name' => 'Dr Nadia Benali',
            'email' => 'nadia@example.dz',
            'phone' => '0555 12 34 56',
            'cabinet_name' => 'Cabinet El Amal',
            'specialization' => 'Cardiologie',
            'website' => '',
            ...$overrides,
        ];
    }

    private function configureExternalInstaller(): void
    {
        config([
            'medismart.desktop_download.url' => 'https://downloads.example.test/Drclick-Setup.exe',
            'medismart.desktop_download.installer_path' => 'missing.exe',
        ]);
    }

    private function configureMissingInstaller(): void
    {
        config([
            'medismart.desktop_download.url' => null,
            'medismart.desktop_download.installer_path' => 'missing.exe',
        ]);
    }
}
