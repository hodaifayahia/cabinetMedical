<?php

namespace Tests\Feature\Desktop;

use App\Configuration\ApplicationSettingRegistry as Setting;
use App\Models\ApplicationSetting;
use App\Models\Device;
use App\Services\ApplicationSettingService;
use App\Services\MachineFingerprintService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MachineIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'medismart.runtime.desktop_supervised' => false,
            'medismart.licensing.fingerprint_pepper' => 'pepper-one',
        ]);
    }

    public function test_an_unsupervised_installation_generates_and_keeps_one_identity(): void
    {
        $first = $this->fingerprint()->installationId();

        $this->assertTrue(Str::isUuid($first));
        $this->assertSame($first, $this->fingerprint()->installationId());
        $this->assertSame($first, app(ApplicationSettingService::class)->get(Setting::DESKTOP_INSTALLATION_ID));
    }

    public function test_a_corrupted_stored_identity_is_replaced(): void
    {
        ApplicationSetting::putValue(Setting::DESKTOP_INSTALLATION_ID, 'not-a-uuid', group: 'desktop');

        $id = $this->fingerprint()->installationId();

        $this->assertTrue(Str::isUuid($id));
        $this->assertNotSame('not-a-uuid', $id);
    }

    public function test_the_supervised_identity_overrides_a_previous_unsupervised_one(): void
    {
        $legacy = $this->fingerprint()->installationId();
        $native = (string) Str::uuid();
        config([
            'medismart.runtime.desktop_supervised' => true,
            'medismart.runtime.installation_id' => $native,
        ]);

        $this->assertSame($native, $this->fingerprint()->installationId());
        $this->assertNotSame($legacy, $native);
        $this->assertSame($native, app(ApplicationSettingService::class)->get(Setting::DESKTOP_INSTALLATION_ID));
    }

    public function test_the_fingerprint_is_a_stable_hmac_backed_by_an_encrypted_machine_seed(): void
    {
        $hash = $this->fingerprint()->fingerprintHash();

        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $hash);
        $this->assertSame($hash, $this->fingerprint()->fingerprintHash());

        $row = DB::table('application_settings')->where('key', Setting::DESKTOP_MACHINE_SEED)->first();
        $this->assertNotNull($row);
        $this->assertNull($row->plain_value);
        $this->assertNotNull($row->encrypted_value);
        $this->assertSame(64, strlen((string) app(ApplicationSettingService::class)->get(Setting::DESKTOP_MACHINE_SEED)));
    }

    public function test_changing_the_pepper_changes_the_fingerprint(): void
    {
        $before = $this->fingerprint()->fingerprintHash();

        config(['medismart.licensing.fingerprint_pepper' => 'pepper-two']);

        $this->assertNotSame($before, $this->fingerprint()->fingerprintHash());
    }

    public function test_a_lost_machine_seed_produces_a_new_fingerprint(): void
    {
        $before = $this->fingerprint()->fingerprintHash();

        ApplicationSetting::query()->where('key', Setting::DESKTOP_MACHINE_SEED)->delete();

        $this->assertNotSame($before, $this->fingerprint()->fingerprintHash());
    }

    public function test_a_plain_text_seed_is_never_trusted(): void
    {
        ApplicationSetting::putValue(Setting::DESKTOP_MACHINE_SEED, str_repeat('a', 64), group: 'desktop');
        $copied = hash_hmac('sha256', implode('|', [
            PHP_OS_FAMILY,
            php_uname('m'),
            php_uname('n'),
            str_repeat('a', 64),
        ]), 'pepper-one');

        $this->assertNotSame($copied, $this->fingerprint()->fingerprintHash());
    }

    public function test_registering_the_device_is_idempotent_and_tracks_last_seen(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-01T10:00:00Z'));
        $first = $this->fingerprint()->registerDevice();

        $this->travelTo(CarbonImmutable::parse('2026-09-02T10:00:00Z'));
        $second = $this->fingerprint()->registerDevice();

        $this->assertTrue($first->is($second));
        $this->assertSame(1, Device::query()->count());
        $this->assertSame(PHP_OS_FAMILY, $second->platform);
        $this->assertSame('active', $second->status);
        $this->assertSame($this->fingerprint()->installationId(), $second->installation_id);
        $this->assertSame($this->fingerprint()->fingerprintHash(), $second->machine_fingerprint_hash);
        $this->assertSame('2026-09-01', $second->fresh()->first_seen_at->toDateString());
        $this->assertSame('2026-09-02', $second->fresh()->last_seen_at->toDateString());
    }

    private function fingerprint(): MachineFingerprintService
    {
        return app(MachineFingerprintService::class);
    }
}
