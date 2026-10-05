<?php

namespace Tests\Feature\Desktop;

use App\Backups\BackupSchedule;
use App\Configuration\ApplicationSettingRegistry as Setting;
use App\Models\ApplicationSetting;
use App\Services\ApplicationSettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ApplicationSettingBehaviourTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unknown_key_is_rejected_on_every_entry_point(): void
    {
        foreach ([
            fn () => $this->settings()->get('nope.key'),
            fn () => $this->settings()->set('nope.key', 1),
            fn () => $this->settings()->setInternal('nope.key', 1),
            fn () => $this->settings()->reset('nope.key'),
            fn () => $this->settings()->describe('nope.key'),
        ] as $call) {
            try {
                $call();
                $this->fail('An unregistered setting key was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('Unknown application setting', $exception->getMessage());
            }
        }
    }

    public function test_internal_keys_cannot_be_written_through_the_editable_api(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is internal');

        $this->settings()->set(Setting::DESKTOP_INSTALLATION_ID, '0b7f0a52-7b07-4b0b-8a3b-5f0c3b0f6a11');
    }

    public function test_internal_keys_cannot_be_reset_through_the_editable_api(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->settings()->reset(Setting::BACKUP_DRIVE_AUTO_UPLOAD);
    }

    public function test_internal_keys_are_written_through_set_internal_with_their_own_rules(): void
    {
        $id = '0b7f0a52-7b07-4b0b-8a3b-5f0c3b0f6a11';

        $this->assertSame($id, $this->settings()->setInternal(Setting::DESKTOP_INSTALLATION_ID, $id));
        $this->assertSame($id, $this->settings()->get(Setting::DESKTOP_INSTALLATION_ID));

        $this->expectException(ValidationException::class);
        $this->settings()->setInternal(Setting::DESKTOP_INSTALLATION_ID, 'not-a-uuid');
    }

    public function test_reset_deletes_the_override_and_returns_the_default(): void
    {
        $this->settings()->set(Setting::UPDATE_CHECK_INTERVAL_HOURS, 48);

        $this->assertSame(24, $this->settings()->reset(Setting::UPDATE_CHECK_INTERVAL_HOURS));
        $this->assertDatabaseMissing('application_settings', ['key' => Setting::UPDATE_CHECK_INTERVAL_HOURS]);
        $this->assertSame(24, $this->settings()->get(Setting::UPDATE_CHECK_INTERVAL_HOURS));
    }

    public function test_set_many_is_atomic_when_any_value_is_invalid(): void
    {
        try {
            $this->settings()->setMany([
                Setting::BACKUP_RETENTION_DAILY => 30,
                Setting::BACKUP_RETENTION_WEEKLY => 1000,
            ]);
            $this->fail('An out-of-range retention was accepted.');
        } catch (ValidationException) {
            $this->assertDatabaseMissing('application_settings', ['key' => Setting::BACKUP_RETENTION_DAILY]);
        }
    }

    public function test_set_many_returns_every_effective_value(): void
    {
        $this->assertSame([
            Setting::BACKUP_RETENTION_DAILY => 30,
            Setting::BACKUP_RETENTION_WEEKLY => 8,
        ], $this->settings()->setMany([
            Setting::BACKUP_RETENTION_DAILY => '30',
            Setting::BACKUP_RETENTION_WEEKLY => 8,
        ]));
        $this->assertSame([], $this->settings()->setMany([]));
    }

    /** @return array<string, array{string, mixed}> */
    public static function invalidValues(): array
    {
        return [
            'mode outside options' => [Setting::UPLOAD_DEFAULT_MODE, 'bluetooth'],
            'ttl above 30 minutes' => [Setting::UPLOAD_SESSION_TTL_MINUTES, 31],
            'ttl below one minute' => [Setting::UPLOAD_SESSION_TTL_MINUTES, 0],
            'interval zero' => [Setting::UPDATE_CHECK_INTERVAL_HOURS, 0],
            'interval above a week' => [Setting::UPDATE_CHECK_INTERVAL_HOURS, 169],
            'non boolean flag' => [Setting::UPDATE_AUTO_CHECK, 'sometimes'],
            'privileged preferred port' => [Setting::CONNECTIVITY_PREFERRED_PORT, 80],
            'port above range' => [Setting::CONNECTIVITY_PREFERRED_PORT, 70000],
            'public manual ipv4' => [Setting::CONNECTIVITY_MANUAL_IPV4, '8.8.8.8'],
            'just outside 172.16/12' => [Setting::CONNECTIVITY_MANUAL_IPV4, '172.32.0.1'],
            'log retention below a week' => [Setting::SECURITY_LOG_RETENTION_DAYS, 6],
            'storage cap below 100 MB' => [Setting::BACKUP_MAXIMUM_STORAGE_BYTES, 1024],
            'two backup times' => [Setting::BACKUP_SCHEDULE_TIMES, ['08:00', '12:00']],
            'duplicate backup times' => [Setting::BACKUP_SCHEDULE_TIMES, ['08:00', '08:00', '12:00']],
            'invalid backup hour' => [Setting::BACKUP_SCHEDULE_TIMES, ['08:00', '12:00', '24:00']],
            'required value cleared' => [Setting::BACKUP_RETENTION_DAILY, null],
            'update channel not offered' => [Setting::UPDATE_CHANNEL, 'beta'],
        ];
    }

    #[DataProvider('invalidValues')]
    public function test_invalid_values_are_rejected_and_nothing_is_stored(string $key, mixed $value): void
    {
        try {
            $this->settings()->set($key, $value);
            $this->fail("An invalid value for [{$key}] was accepted.");
        } catch (ValidationException) {
            $this->assertDatabaseMissing('application_settings', ['key' => $key]);
        }
    }

    public function test_valid_values_are_normalized_before_storage(): void
    {
        $this->assertSame('172.31.255.1', $this->settings()->set(Setting::CONNECTIVITY_MANUAL_IPV4, '172.31.255.1'));
        $this->assertFalse($this->settings()->set(Setting::UPDATE_AUTO_CHECK, '0'));
        $this->assertSame(
            ['07:30', '12:00', '19:45'],
            $this->settings()->set(Setting::BACKUP_SCHEDULE_TIMES, ['07:30', '12:00', '19:45']),
        );
        $this->assertSame(54321, $this->settings()->set(Setting::CONNECTIVITY_PREFERRED_PORT, '54321'));
    }

    public function test_clearing_a_nullable_override_removes_its_row(): void
    {
        $this->settings()->set(Setting::CONNECTIVITY_MANUAL_IPV4, '192.168.1.20');
        $this->assertDatabaseHas('application_settings', ['key' => Setting::CONNECTIVITY_MANUAL_IPV4]);

        $this->assertNull($this->settings()->set(Setting::CONNECTIVITY_MANUAL_IPV4, null));
        $this->assertDatabaseMissing('application_settings', ['key' => Setting::CONNECTIVITY_MANUAL_IPV4]);
    }

    public function test_the_beta_channel_is_accepted_only_when_the_release_declares_it(): void
    {
        config(['medismart.updates.allowed_channels' => ['stable', 'beta', 'nightly']]);

        $this->assertSame(['stable', 'beta'], $this->settings()->describe(Setting::UPDATE_CHANNEL)['options']);
        $this->assertSame('beta', $this->settings()->set(Setting::UPDATE_CHANNEL, 'beta'));
    }

    public function test_a_release_without_stable_still_offers_only_stable(): void
    {
        config(['medismart.updates.allowed_channels' => ['beta']]);

        $this->assertSame(['stable'], $this->settings()->describe(Setting::UPDATE_CHANNEL)['options']);

        config(['medismart.updates.allowed_channels' => 'beta']);

        $this->assertSame(['stable'], $this->settings()->describe(Setting::UPDATE_CHANNEL)['options']);
    }

    public function test_the_individual_upload_limit_cannot_exceed_the_stored_total(): void
    {
        config([
            'medismart.uploads.maximum_individual_bytes' => 50 * 1024 * 1024,
            'medismart.uploads.maximum_total_bytes' => 100 * 1024 * 1024,
        ]);
        $this->settings()->setMany([
            Setting::UPLOAD_MAXIMUM_INDIVIDUAL_BYTES => 5 * 1024 * 1024,
            Setting::UPLOAD_MAXIMUM_TOTAL_BYTES => 10 * 1024 * 1024,
        ]);

        try {
            $this->settings()->set(Setting::UPLOAD_MAXIMUM_INDIVIDUAL_BYTES, 20 * 1024 * 1024);
            $this->fail('An individual limit larger than the total was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(Setting::UPLOAD_MAXIMUM_INDIVIDUAL_BYTES, $exception->errors());
        }

        $this->assertSame([
            Setting::UPLOAD_MAXIMUM_INDIVIDUAL_BYTES => 20 * 1024 * 1024,
            Setting::UPLOAD_MAXIMUM_TOTAL_BYTES => 40 * 1024 * 1024,
        ], $this->settings()->setMany([
            Setting::UPLOAD_MAXIMUM_INDIVIDUAL_BYTES => 20 * 1024 * 1024,
            Setting::UPLOAD_MAXIMUM_TOTAL_BYTES => 40 * 1024 * 1024,
        ]));
    }

    public function test_lowering_only_the_total_below_the_default_individual_limit_is_rejected(): void
    {
        config([
            'medismart.uploads.maximum_individual_bytes' => 50 * 1024 * 1024,
            'medismart.uploads.maximum_total_bytes' => 100 * 1024 * 1024,
        ]);

        $this->expectException(ValidationException::class);

        $this->settings()->set(Setting::UPLOAD_MAXIMUM_TOTAL_BYTES, 10 * 1024 * 1024);
    }

    public function test_lowering_the_total_below_the_stored_individual_limit_is_rejected(): void
    {
        config([
            'medismart.uploads.maximum_individual_bytes' => 50 * 1024 * 1024,
            'medismart.uploads.maximum_total_bytes' => 100 * 1024 * 1024,
        ]);
        $this->settings()->set(Setting::UPLOAD_MAXIMUM_INDIVIDUAL_BYTES, 30 * 1024 * 1024);

        $this->expectException(ValidationException::class);

        $this->settings()->set(Setting::UPLOAD_MAXIMUM_TOTAL_BYTES, 20 * 1024 * 1024);
    }

    public function test_editable_settings_are_grouped_and_exclude_internal_keys(): void
    {
        $this->settings()->set(Setting::BACKUP_RETENTION_DAILY, 10);

        $backups = $this->settings()->editableSettings('backups');

        $this->assertArrayHasKey(Setting::BACKUP_SCHEDULE_TIMES, $backups);
        $this->assertArrayNotHasKey(Setting::BACKUP_DRIVE_AUTO_UPLOAD, $backups);
        $this->assertArrayNotHasKey(Setting::BACKUP_DRIVE_AUTO_UPLOAD_PASSPHRASE, $backups);
        $this->assertArrayNotHasKey(Setting::UPDATE_AUTO_CHECK, $backups);
        $this->assertSame(10, $backups[Setting::BACKUP_RETENTION_DAILY]['value']);
        $this->assertSame('override', $backups[Setting::BACKUP_RETENTION_DAILY]['source']);
        $this->assertTrue($backups[Setting::BACKUP_RETENTION_DAILY]['configured']);
        $this->assertSame(BackupSchedule::DEFAULT_TIMES, $backups[Setting::BACKUP_SCHEDULE_TIMES]['value']);
        $this->assertFalse($backups[Setting::BACKUP_SCHEDULE_TIMES]['configured']);

        $all = $this->settings()->editableSettings();
        $this->assertArrayNotHasKey(Setting::DESKTOP_INSTALLATION_ID, $all);
        $this->assertArrayNotHasKey(Setting::LICENSING_TRUSTED_TIME, $all);
        $this->assertArrayHasKey(Setting::SECURITY_IDLE_LOCK_MINUTES, $all);
    }

    public function test_describing_a_sensitive_internal_key_never_returns_its_value(): void
    {
        $this->settings()->setInternal(Setting::BACKUP_DRIVE_AUTO_UPLOAD_PASSPHRASE, 'correct horse battery staple');

        $description = $this->settings()->describe(Setting::BACKUP_DRIVE_AUTO_UPLOAD_PASSPHRASE);

        $this->assertNull($description['value']);
        $this->assertNull($description['default']);
        $this->assertTrue($description['sensitive']);
        $this->assertSame('override', $description['source']);
        $this->assertSame('correct horse battery staple', $this->settings()->get(Setting::BACKUP_DRIVE_AUTO_UPLOAD_PASSPHRASE));
    }

    public function test_a_row_with_a_mismatched_type_is_ignored_without_being_deleted(): void
    {
        ApplicationSetting::putValue(Setting::BACKUP_RETENTION_DAILY, 99, type: 'string', group: 'backups');

        $this->assertSame(7, $this->settings()->get(Setting::BACKUP_RETENTION_DAILY));
        $this->assertSame('default', $this->settings()->describe(Setting::BACKUP_RETENTION_DAILY)['source']);
        $this->assertDatabaseHas('application_settings', ['key' => Setting::BACKUP_RETENTION_DAILY]);
    }

    public function test_a_row_with_a_mismatched_group_is_ignored(): void
    {
        ApplicationSetting::putValue(Setting::BACKUP_RETENTION_DAILY, 99, type: 'integer', group: 'general');

        $this->assertSame(7, $this->settings()->get(Setting::BACKUP_RETENTION_DAILY));
    }

    public function test_a_stored_value_outside_the_current_bounds_falls_back_to_the_default(): void
    {
        ApplicationSetting::putValue(Setting::BACKUP_RETENTION_DAILY, 5000, type: 'integer', group: 'backups');

        $this->assertSame(7, $this->settings()->get(Setting::BACKUP_RETENTION_DAILY));
    }

    public function test_a_sensitive_key_stored_in_plain_text_is_ignored(): void
    {
        ApplicationSetting::putValue(
            Setting::BACKUP_DRIVE_AUTO_UPLOAD_PASSPHRASE,
            'plain text passphrase',
            encrypted: false,
            group: 'backups',
        );

        $this->assertNull($this->settings()->get(Setting::BACKUP_DRIVE_AUTO_UPLOAD_PASSPHRASE));
    }

    public function test_a_row_holding_both_plain_and_encrypted_values_is_ignored(): void
    {
        $this->settings()->set(Setting::UPDATE_CHECK_INTERVAL_HOURS, 12);
        DB::table('application_settings')
            ->where('key', Setting::UPDATE_CHECK_INTERVAL_HOURS)
            ->update(['encrypted_value' => encrypt('48', false)]);

        $this->assertSame(24, $this->settings()->get(Setting::UPDATE_CHECK_INTERVAL_HOURS));
    }

    public function test_storing_the_default_value_does_not_create_a_row(): void
    {
        $this->settings()->set(Setting::DESKTOP_CLOSE_TO_TRAY, true);

        $this->assertDatabaseMissing('application_settings', ['key' => Setting::DESKTOP_CLOSE_TO_TRAY]);
        $this->assertSame('default', $this->settings()->describe(Setting::DESKTOP_CLOSE_TO_TRAY)['source']);
    }

    private function settings(): ApplicationSettingService
    {
        return app(ApplicationSettingService::class);
    }
}
