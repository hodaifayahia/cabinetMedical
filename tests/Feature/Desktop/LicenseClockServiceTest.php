<?php

namespace Tests\Feature\Desktop;

use App\Configuration\ApplicationSettingRegistry;
use App\Models\ApplicationSetting;
use App\Services\ApplicationSettingService;
use App\Services\LicenseClockService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LicenseClockServiceTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-09-01T10:00:00Z');
        $this->travelTo($this->now);
        config(['medismart.licensing.clock_rollback_tolerance_hours' => 6]);
    }

    public function test_a_missing_anchor_is_an_integrity_failure_and_is_not_recreated(): void
    {
        $result = $this->clock()->evaluate($this->now->subHour());

        $this->assertTrue($result['rollback_detected']);
        $this->assertNull($result['trusted_at']);
        $this->assertTrue($result['effective_now']->equalTo($this->now));
        $this->assertDatabaseMissing('application_settings', ['key' => ApplicationSettingRegistry::LICENSING_TRUSTED_TIME]);
    }

    public function test_recorded_server_time_is_stored_encrypted_and_never_in_plain_text(): void
    {
        $this->clock()->recordServerTime($this->now);

        $row = ApplicationSetting::query()->where('key', ApplicationSettingRegistry::LICENSING_TRUSTED_TIME)->firstOrFail();

        $this->assertNull($row->plain_value);
        $this->assertNotNull($row->getRawOriginal('encrypted_value'));
        $this->assertStringNotContainsString('2026-09-01', (string) $row->getRawOriginal('encrypted_value'));
        $this->assertSame(
            '2026-09-01T10:00:00+00:00',
            app(ApplicationSettingService::class)->get(ApplicationSettingRegistry::LICENSING_TRUSTED_TIME),
        );
    }

    public function test_a_consistent_clock_is_trusted_without_a_warning(): void
    {
        $this->clock()->recordServerTime($this->now->subMinutes(5));

        $result = $this->clock()->evaluate($this->now->subMinutes(5));

        $this->assertFalse($result['rollback_detected']);
        $this->assertTrue($result['effective_now']->equalTo($this->now));
        $this->assertTrue($result['trusted_at']->equalTo($this->now->subMinutes(5)));
    }

    public function test_a_newer_signed_issue_time_advances_the_anchor(): void
    {
        $this->clock()->recordServerTime($this->now->subDays(2));

        $result = $this->clock()->evaluate($this->now->subMinutes(1));

        $this->assertFalse($result['rollback_detected']);
        $this->assertTrue($result['trusted_at']->equalTo($this->now->subMinutes(1)));
        $this->assertSame(
            $this->now->subMinutes(1)->toIso8601String(),
            app(ApplicationSettingService::class)->get(ApplicationSettingRegistry::LICENSING_TRUSTED_TIME),
        );
    }

    public function test_the_anchor_moves_forward_with_local_time_after_fifteen_minutes(): void
    {
        $this->clock()->recordServerTime($this->now->subHours(3));

        $result = $this->clock()->evaluate($this->now->subDays(10));

        $this->assertFalse($result['rollback_detected']);
        $this->assertTrue($result['trusted_at']->equalTo($this->now));
        $this->assertSame(
            $this->now->toIso8601String(),
            app(ApplicationSettingService::class)->get(ApplicationSettingRegistry::LICENSING_TRUSTED_TIME),
        );
    }

    public function test_the_anchor_is_not_rewritten_within_fifteen_minutes(): void
    {
        $anchor = $this->now->subMinutes(14);
        $this->clock()->recordServerTime($anchor);

        $result = $this->clock()->evaluate($this->now->subDays(1));

        $this->assertTrue($result['trusted_at']->equalTo($anchor));
        $this->assertSame(
            $anchor->toIso8601String(),
            app(ApplicationSettingService::class)->get(ApplicationSettingRegistry::LICENSING_TRUSTED_TIME),
        );
    }

    public function test_a_small_backwards_drift_within_tolerance_uses_the_anchor_as_effective_time(): void
    {
        $anchor = $this->now->addHours(5);
        $this->clock()->recordServerTime($anchor);

        $result = $this->clock()->evaluate($this->now->subDay());

        $this->assertFalse($result['rollback_detected']);
        $this->assertTrue($result['effective_now']->equalTo($anchor));
    }

    public function test_a_rollback_beyond_the_tolerance_is_detected(): void
    {
        $anchor = $this->now->addHours(7);
        $this->clock()->recordServerTime($anchor);

        $result = $this->clock()->evaluate($this->now->subDay());

        $this->assertTrue($result['rollback_detected']);
        $this->assertTrue($result['effective_now']->equalTo($anchor));
        $this->assertTrue($result['trusted_at']->equalTo($anchor));
    }

    public function test_the_rollback_tolerance_is_clamped_to_at_least_one_hour(): void
    {
        config(['medismart.licensing.clock_rollback_tolerance_hours' => 0]);
        $this->clock()->recordServerTime($this->now->addMinutes(50));

        $this->assertFalse($this->clock()->evaluate($this->now->subDay())['rollback_detected']);

        $this->clock()->recordServerTime($this->now->addMinutes(70));

        $this->assertTrue($this->clock()->evaluate($this->now->subDay())['rollback_detected']);
    }

    public function test_the_rollback_tolerance_is_clamped_to_at_most_one_week(): void
    {
        config(['medismart.licensing.clock_rollback_tolerance_hours' => 10_000]);
        $this->clock()->recordServerTime($this->now->addHours(167));

        $this->assertFalse($this->clock()->evaluate($this->now->subDay())['rollback_detected']);

        $this->clock()->recordServerTime($this->now->addHours(169));

        $this->assertTrue($this->clock()->evaluate($this->now->subDay())['rollback_detected']);
    }

    public function test_a_new_signed_certificate_can_reset_a_bad_anchor(): void
    {
        $this->clock()->recordServerTime($this->now->addDays(30));
        $this->assertTrue($this->clock()->evaluate($this->now)['rollback_detected']);

        // recordServerTime is called from an authenticated certificate refresh.
        $this->clock()->recordServerTime($this->now);

        $this->assertFalse($this->clock()->evaluate($this->now)['rollback_detected']);
    }

    public function test_clearing_the_anchor_removes_the_row_and_fails_closed_afterwards(): void
    {
        $this->clock()->recordServerTime($this->now);
        $this->clock()->clear();

        $this->assertDatabaseMissing('application_settings', ['key' => ApplicationSettingRegistry::LICENSING_TRUSTED_TIME]);
        $this->assertTrue($this->clock()->evaluate($this->now)['rollback_detected']);
    }

    public function test_an_undecryptable_anchor_fails_closed_without_resetting_trust(): void
    {
        $this->clock()->recordServerTime($this->now->subHour());
        DB::table('application_settings')
            ->where('key', ApplicationSettingRegistry::LICENSING_TRUSTED_TIME)
            ->update(['encrypted_value' => 'not-a-valid-ciphertext']);

        $result = $this->clock()->evaluate($this->now);

        $this->assertTrue($result['rollback_detected']);
        $this->assertNull($result['trusted_at']);
        $this->assertSame(
            'not-a-valid-ciphertext',
            DB::table('application_settings')
                ->where('key', ApplicationSettingRegistry::LICENSING_TRUSTED_TIME)
                ->value('encrypted_value'),
        );
    }

    public function test_a_plain_text_anchor_row_is_not_trusted(): void
    {
        DB::table('application_settings')->insert([
            'key' => ApplicationSettingRegistry::LICENSING_TRUSTED_TIME,
            'plain_value' => '2020-01-01T00:00:00+00:00',
            'encrypted_value' => null,
            'type' => 'string',
            'group' => 'licensing',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = $this->clock()->evaluate($this->now);

        $this->assertTrue($result['rollback_detected']);
        $this->assertNull($result['trusted_at']);
    }

    private function clock(): LicenseClockService
    {
        return app(LicenseClockService::class);
    }
}
