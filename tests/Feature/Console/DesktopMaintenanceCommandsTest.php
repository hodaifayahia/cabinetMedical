<?php

namespace Tests\Feature\Console;

use App\Enums\CabinetStatus;
use App\Models\Cabinet;
use App\Models\CabinetSetting;
use App\Models\GoogleDriveOAuthAttempt;
use App\Models\License;
use App\Models\User;
use App\Services\Sync\MobileSyncSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\ActivatesSignedLicense;
use Tests\TestCase;

/**
 * The scheduled console commands the desktop runtime relies on.
 */
class DesktopMaintenanceCommandsTest extends TestCase
{
    use ActivatesSignedLicense;
    use RefreshDatabase;

    private const string ENDPOINT = 'https://online.example.test';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(CarbonImmutable::parse('2026-09-01T10:00:00Z'));
    }

    protected function tearDown(): void
    {
        $this->cleanUpSignedLicenseFeatures();

        parent::tearDown();
    }

    public function test_license_refresh_is_skipped_without_a_license_server(): void
    {
        config(['medismart.licensing.status_url' => null]);

        $this->artisan('medismart:license:refresh')
            ->expectsOutputToContain('serveur de licences non configuré')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_license_refresh_is_skipped_without_an_activated_license(): void
    {
        $this->configureLicenseServer();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertInstanceOf(\OpenSSLAsymmetricKey::class, $key);
        $path = (string) tempnam(sys_get_temp_dir(), 'medismart-command-key-');
        file_put_contents($path, openssl_pkey_get_details($key)['key']);
        config(['medismart.licensing.public_key_path' => $path]);

        $this->artisan('medismart:license:refresh')
            ->expectsOutputToContain('aucune licence active')
            ->assertSuccessful();

        Http::assertNothingSent();
        unlink($path);
    }

    public function test_a_recently_verified_license_is_not_refreshed_again(): void
    {
        $this->activateSignedLicenseFeatures(['remote_upload' => true]);
        $this->configureLicenseServer();
        $this->travel(5)->hours();

        $this->artisan('medismart:license:refresh')
            ->expectsOutputToContain('encore récente')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_a_failed_forced_refresh_keeps_the_local_certificate(): void
    {
        $this->activateSignedLicenseFeatures(['remote_upload' => true]);
        $this->configureLicenseServer();
        $certificate = License::query()->firstOrFail()->signed_certificate;
        Http::fake(['licenses.medismart.test/*' => Http::response(['error' => 'down'], 503)]);

        $this->artisan('medismart:license:refresh', ['--force' => true])
            ->expectsOutputToContain('certificat local reste inchangé')
            ->assertFailed();

        Http::assertSent(static fn ($request): bool => $request->url() === 'https://licenses.medismart.test/v1/licenses/status');
        $this->assertSame($certificate, License::query()->firstOrFail()->signed_certificate);
    }

    public function test_an_old_verification_is_refreshed_without_force(): void
    {
        $this->activateSignedLicenseFeatures(['remote_upload' => true]);
        $this->configureLicenseServer();
        $this->travel(7)->hours();
        Http::fake(['licenses.medismart.test/*' => Http::response(['license_certificate' => 'not-a-certificate'])]);

        $this->artisan('medismart:license:refresh')->assertFailed();

        Http::assertSent(static fn ($request): bool => $request->url() === 'https://licenses.medismart.test/v1/licenses/status');
    }

    public function test_seat_sync_does_nothing_on_an_unlinked_installation(): void
    {
        $this->artisan('drclick:sync-seats')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_seat_sync_reports_the_granted_seats(): void
    {
        $cabinet = $this->cabinetOwnedBy('doctor@clinic.test');
        app(MobileSyncSettings::class)->configure(self::ENDPOINT, '1|token', cabinetId: $cabinet->getKey());
        Http::fake([self::ENDPOINT.'/api/v1/cabinet/seats' => Http::response([
            'data' => ['seat_limit' => 6, 'owner_email' => 'doctor@clinic.test'],
        ])]);

        $this->artisan('drclick:sync-seats')
            ->expectsOutputToContain(sprintf('Cabinet %d : 6 sièges accordés.', $cabinet->getKey()))
            ->assertSuccessful();

        $this->assertSame(6, $cabinet->fresh()->seat_limit);
    }

    public function test_seat_sync_treats_being_offline_as_normal(): void
    {
        $cabinet = $this->cabinetOwnedBy('doctor@clinic.test');
        app(MobileSyncSettings::class)->configure(self::ENDPOINT, '1|token', cabinetId: $cabinet->getKey());
        Http::fake(['*' => Http::failedConnection()]);

        $this->artisan('drclick:sync-seats')
            ->expectsOutputToContain('injoignable')
            ->assertSuccessful();

        $this->assertSame(Cabinet::DEFAULT_SEATS, $cabinet->fresh()->seat_limit);
    }

    public function test_seat_sync_fails_when_the_online_account_belongs_to_another_cabinet(): void
    {
        $this->cabinetOwnedBy('doctor@clinic.test');
        app(MobileSyncSettings::class)->configure(self::ENDPOINT, '1|token');
        Http::fake(['*' => Http::response(['data' => ['seat_limit' => 6, 'owner_email' => 'stranger@clinic.test']])]);

        $this->artisan('drclick:sync-seats')
            ->expectsOutputToContain('ne correspond à aucun cabinet')
            ->assertFailed();
    }

    public function test_appointment_sync_refuses_to_run_unconfigured(): void
    {
        $this->artisan('drclick:sync-appointments')
            ->expectsOutputToContain('pas configurée')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_appointment_sync_refuses_to_run_while_disabled(): void
    {
        $this->cabinetOwnedBy('doctor@clinic.test');
        app(MobileSyncSettings::class)->configure(self::ENDPOINT, '1|token');
        app(MobileSyncSettings::class)->disable();

        $this->artisan('drclick:sync-appointments')->assertFailed();

        Http::assertNothingSent();
    }

    public function test_appointment_sync_without_any_cabinet_has_nothing_to_do(): void
    {
        app(MobileSyncSettings::class)->configure(self::ENDPOINT, '1|token');

        $this->artisan('drclick:sync-appointments')
            ->expectsOutputToContain('Aucun cabinet')
            ->assertFailed();
    }

    public function test_appointment_sync_for_a_linked_cabinet_that_no_longer_exists_has_nothing_to_do(): void
    {
        $this->cabinetOwnedBy('doctor@clinic.test');
        app(MobileSyncSettings::class)->configure(self::ENDPOINT, '1|token', cabinetId: 9999);

        $this->artisan('drclick:sync-appointments')
            ->expectsOutputToContain('Aucun cabinet')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_appointment_sync_reports_an_offline_run_as_a_failed_run(): void
    {
        $cabinet = $this->cabinetOwnedBy('doctor@clinic.test');
        app(MobileSyncSettings::class)->configure(self::ENDPOINT, '1|token', cabinetId: $cabinet->getKey());
        Http::fake(['*' => Http::failedConnection()]);

        $this->artisan('drclick:sync-appointments')
            ->expectsOutputToContain(sprintf('Cabinet %d :', $cabinet->getKey()))
            ->assertFailed();
    }

    public function test_oauth_attempt_pruning_expires_abandons_and_deletes_by_age(): void
    {
        $settings = CabinetSetting::query()->create(CabinetSetting::defaults());
        $actor = User::factory()->create();
        $pendingExpired = $this->attempt($settings, $actor, GoogleDriveOAuthAttempt::STATUS_PENDING, now()->subMinute());
        $pendingLive = $this->attempt($settings, $actor, GoogleDriveOAuthAttempt::STATUS_PENDING, now()->addMinutes(5));
        $claimedStale = $this->attempt($settings, $actor, GoogleDriveOAuthAttempt::STATUS_CLAIMED, now()->subMinutes(6));
        $claimedRecent = $this->attempt($settings, $actor, GoogleDriveOAuthAttempt::STATUS_CLAIMED, now()->subMinutes(4));
        $oldCompleted = $this->attempt($settings, $actor, GoogleDriveOAuthAttempt::STATUS_COMPLETED, now()->subDays(8), now()->subDays(8));
        $recentFailed = $this->attempt($settings, $actor, GoogleDriveOAuthAttempt::STATUS_FAILED, now()->subDays(2), now()->subDays(2));

        $this->artisan('medismart:oauth-attempts:prune')
            ->expectsOutputToContain('OAuth attempts: 1 expired, 1 abandoned, 1 old terminal rows pruned.')
            ->assertSuccessful();

        $pendingExpired->refresh();
        $this->assertSame(GoogleDriveOAuthAttempt::STATUS_EXPIRED, $pendingExpired->status);
        $this->assertSame('expired', $pendingExpired->failure_code);
        $this->assertNull($pendingExpired->encrypted_pkce_verifier);
        $this->assertNotNull($pendingExpired->failed_at);

        $claimedStale->refresh();
        $this->assertSame(GoogleDriveOAuthAttempt::STATUS_FAILED, $claimedStale->status);
        $this->assertSame('claim_timeout', $claimedStale->failure_code);

        $this->assertSame(GoogleDriveOAuthAttempt::STATUS_PENDING, $pendingLive->fresh()->status);
        $this->assertNotNull($pendingLive->fresh()->encrypted_pkce_verifier);
        $this->assertSame(GoogleDriveOAuthAttempt::STATUS_CLAIMED, $claimedRecent->fresh()->status);
        $this->assertNull($oldCompleted->fresh());
        $this->assertNotNull($recentFailed->fresh());
    }

    private function configureLicenseServer(): void
    {
        config([
            'medismart.licensing.activation_url' => 'https://licenses.medismart.test/v1/activations',
            'medismart.licensing.status_url' => 'https://licenses.medismart.test/v1/licenses/status',
            'medismart.licensing.deactivation_url' => 'https://licenses.medismart.test/v1/activations/deactivate',
        ]);
    }

    private function cabinetOwnedBy(string $email): Cabinet
    {
        $cabinet = Cabinet::query()->create([
            'name' => 'Cabinet '.$email,
            'status' => CabinetStatus::ACTIVE,
            'activated_at' => now(),
        ]);
        $owner = User::factory()->create([
            'email' => $email,
            'cabinet_id' => $cabinet->getKey(),
            'approved_at' => now(),
        ]);
        $cabinet->forceFill(['owner_user_id' => $owner->getKey()])->save();

        return $cabinet->refresh();
    }

    private function attempt(
        CabinetSetting $settings,
        User $actor,
        string $status,
        CarbonImmutable $expiresAt,
        ?CarbonImmutable $updatedAt = null,
    ): GoogleDriveOAuthAttempt {
        $attempt = GoogleDriveOAuthAttempt::query()->create([
            'state_sha256' => hash('sha256', Str::random(32)),
            'encrypted_pkce_verifier' => 'verifier-'.Str::random(20),
            'redirect_uri' => 'http://127.0.0.1:43123/app/configuration/backup/google/callback',
            'cabinet_setting_id' => $settings->getKey(),
            'actor_id' => $actor->getKey(),
            'status' => $status,
            'expires_at' => $expiresAt,
        ]);

        if ($updatedAt !== null) {
            $attempt->timestamps = false;
            $attempt->forceFill(['updated_at' => $updatedAt])->save();
        }

        return $attempt;
    }
}
