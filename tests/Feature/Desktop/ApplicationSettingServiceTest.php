<?php

namespace Tests\Feature\Desktop;

use App\Configuration\ApplicationSettingRegistry;
use App\Models\ApplicationSetting;
use App\Models\User;
use App\Services\ApplicationSettingService;
use App\Services\NetworkService;
use App\Services\QrUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\TestCase;

class ApplicationSettingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_registry_is_allowlisted_but_the_existing_model_storage_api_remains_compatible(): void
    {
        $registry = app(ApplicationSettingRegistry::class);

        $this->assertTrue($registry->has(ApplicationSettingRegistry::UPLOAD_DEFAULT_MODE));
        $this->assertTrue($registry->has(ApplicationSettingRegistry::CONNECTIVITY_SELECTED_ADAPTER_ID));
        $this->assertTrue($registry->has(ApplicationSettingRegistry::BACKUP_RETENTION_DAILY));
        $this->assertTrue($registry->has(ApplicationSettingRegistry::UPDATE_AUTO_CHECK));
        $this->assertTrue($registry->has(ApplicationSettingRegistry::SECURITY_IDLE_LOCK_MINUTES));
        $this->assertFalse($registry->has('unregistered.setting'));

        ApplicationSetting::putValue('legacy.custom_setting', 'preserved');
        $this->assertSame('preserved', ApplicationSetting::valueFor('legacy.custom_setting'));

        $this->expectException(InvalidArgumentException::class);
        app(ApplicationSettingService::class)->get('legacy.custom_setting');
    }

    public function test_service_persists_typed_overrides_and_removes_values_equal_to_code_defaults(): void
    {
        $settings = app(ApplicationSettingService::class);
        $key = ApplicationSettingRegistry::BACKUP_RETENTION_DAILY;

        $this->assertSame(7, $settings->get($key));
        $this->assertSame('default', $settings->describe($key)['source']);

        $this->assertSame(14, $settings->set($key, 14));
        $stored = ApplicationSetting::query()->where('key', $key)->firstOrFail();
        $this->assertSame('integer', $stored->type);
        $this->assertSame('backups', $stored->group);
        $this->assertSame('14', $stored->plain_value);
        $this->assertSame('override', $settings->describe($key)['source']);

        $this->assertSame(7, $settings->set($key, 7));
        $this->assertDatabaseMissing('application_settings', ['key' => $key]);
    }

    public function test_registry_permissions_match_the_granular_configuration_boundaries(): void
    {
        foreach (app(ApplicationSettingRegistry::class)->all() as $definition) {
            if (in_array($definition->group, ['uploads', 'connectivity', 'updates'], true)) {
                $this->assertSame(
                    'configuration.connectivity.manage',
                    $definition->permission,
                    "Unexpected permission for {$definition->key}.",
                );
            }

            if ($definition->group === 'backups') {
                $this->assertSame(
                    'configuration.backups.manage',
                    $definition->permission,
                    "Unexpected permission for {$definition->key}.",
                );
            }
        }
    }

    public function test_upload_overrides_cannot_exceed_release_caps_and_tampered_rows_fail_safe(): void
    {
        config([
            'medismart.uploads.maximum_files' => 3,
            'medismart.uploads.maximum_individual_bytes' => 600,
            'medismart.uploads.maximum_total_bytes' => 1000,
        ]);

        $settings = app(ApplicationSettingService::class);

        try {
            $settings->set(ApplicationSettingRegistry::UPLOAD_MAXIMUM_FILES, 4);
            $this->fail('The immutable upload cap should reject the override.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('value', $exception->errors());
        }

        DB::table('application_settings')->insert([
            'key' => ApplicationSettingRegistry::UPLOAD_MAXIMUM_FILES,
            'plain_value' => '50',
            'encrypted_value' => null,
            'type' => 'integer',
            'group' => 'uploads',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(3, $settings->get(ApplicationSettingRegistry::UPLOAD_MAXIMUM_FILES));
        $this->assertSame(
            'default',
            $settings->describe(ApplicationSettingRegistry::UPLOAD_MAXIMUM_FILES)['source'],
        );
    }

    public function test_internal_secrets_are_encrypted_and_are_never_returned_in_metadata(): void
    {
        $settings = app(ApplicationSettingService::class);
        $key = ApplicationSettingRegistry::DESKTOP_MACHINE_SEED;
        $secret = str_repeat('a', 64);

        $this->assertSame($secret, $settings->setInternal($key, $secret));

        $stored = DB::table('application_settings')->where('key', $key)->first();
        $this->assertNotNull($stored);
        $this->assertNull($stored->plain_value);
        $this->assertNotSame($secret, $stored->encrypted_value);

        $description = $settings->describe($key);
        $this->assertTrue($description['sensitive']);
        $this->assertTrue($description['configured']);
        $this->assertNull($description['default']);
        $this->assertNull($description['value']);

        $this->expectException(InvalidArgumentException::class);
        $settings->set($key, str_repeat('b', 64));
    }

    public function test_upload_sessions_use_registered_defaults_without_relaxing_hard_caps(): void
    {
        $this->freezeTime();
        config([
            'medismart.uploads.maximum_files' => 10,
            'medismart.uploads.maximum_individual_bytes' => 2000,
            'medismart.uploads.maximum_total_bytes' => 5000,
        ]);

        app(ApplicationSettingService::class)->setMany([
            ApplicationSettingRegistry::UPLOAD_SESSION_TTL_MINUTES => 5,
            ApplicationSettingRegistry::UPLOAD_MAXIMUM_FILES => 2,
            ApplicationSettingRegistry::UPLOAD_MAXIMUM_INDIVIDUAL_BYTES => 500,
            ApplicationSettingRegistry::UPLOAD_MAXIMUM_TOTAL_BYTES => 1000,
        ]);

        $session = app(QrUploadService::class)
            ->create('local', User::factory()->create())['session'];

        $this->assertSame(
            now()->addMinutes(5)->format('Y-m-d H:i:s'),
            $session->expires_at->format('Y-m-d H:i:s'),
        );
        $this->assertSame(2, $session->maximum_files);
        $this->assertSame(500, $session->maximum_individual_bytes);
        $this->assertSame(1000, $session->maximum_total_bytes);
    }

    public function test_connectivity_settings_accept_only_private_ipv4_and_store_adapter_identity(): void
    {
        $settings = app(ApplicationSettingService::class);

        try {
            $settings->set(ApplicationSettingRegistry::CONNECTIVITY_MANUAL_IPV4, '203.0.113.15');
            $this->fail('A public address must not become a LAN listener preference.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('value', $exception->errors());
        }

        $settings->setMany([
            ApplicationSettingRegistry::CONNECTIVITY_MANUAL_IPV4 => '192.168.50.25',
            ApplicationSettingRegistry::CONNECTIVITY_SELECTED_ADAPTER_ID => '{ADAPTER-GUID}',
        ]);

        $network = app(NetworkService::class);
        $this->assertSame('192.168.50.25', $network->preferredIpv4());
        $this->assertSame('{ADAPTER-GUID}', $network->selectedAdapterId());
    }
}
