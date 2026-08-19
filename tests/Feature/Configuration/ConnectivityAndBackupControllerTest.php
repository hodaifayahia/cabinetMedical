<?php

namespace Tests\Feature\Configuration;

use App\Configuration\ApplicationSettingRegistry;
use App\Enums\CabinetStatus;
use App\Enums\LicensePlan;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\ApplicationSetting;
use App\Models\BackupRecord;
use App\Models\Cabinet;
use App\Models\CabinetSetting;
use App\Models\DriveBackupConnection;
use App\Models\Patient;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\ApplicationSettingService;
use App\Services\CabinetFulfillmentService;
use App\Services\QrUploadService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ConnectivityAndBackupControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_an_administrator_sees_real_settings_and_runtime_state(): void
    {
        $administrator = User::factory()->create();
        $administrator->assignRole(RoleName::ADMINISTRATOR->value);

        $this->actingAs($administrator)
            ->get(route('app.configuration.connectivity-backup.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('configuration/ConnectivityAndBackup')
                ->where('settings.uploads.default_mode', 'local')
                ->where('settings.uploads.session_ttl_minutes', 15)
                ->where('settings.backups.retention_daily', 7)
                ->where('settings.backups.retention_weekly', 4)
                ->where('settings.backups.retention_monthly', 12)
                ->where('settings.backups.maximum_storage_bytes', null)
                ->missing('settings.backups.encryption_enabled')
                ->missing('settings.backups.verify_after_create')
                ->missing('settings.backups.drive_auto_upload')
                ->where('runtime.updates.state', 'unavailable')
                ->where('runtime.tunnel.state', 'stopped')
                ->where('runtime.tunnel.configured', false)
                ->where('runtime.tunnel.hostname', null)
                ->where('runtime.tunnel.last_error', null)
                ->missing('runtime.tunnel.token')
                ->where('capabilities.updates.available', false)
                ->where('capabilities.encrypted_backups.available', true)
                ->where('capabilities.offline_restore.available', false)
                ->where('capabilities.google_drive.available', false)
                ->where('permissions.manage_settings', true)
                ->where('permissions.manage_backups', true)
                ->where('permissions.manage_restore', true)
                ->where('permissions.manage_drive', true)
                ->where('permissions.manage_license', true)
                ->where('permissions.view_diagnostics', true)
                ->where('permissions.sensitive_actions_confirmed', false)
                ->missing('tunnel_token')
                ->missing('health_key'));
    }

    public function test_hosted_cabinet_sees_its_plan_without_machine_license_controls(): void
    {
        $cabinet = Cabinet::query()->create([
            'name' => 'Hosted Configuration Cabinet',
            'status' => CabinetStatus::PENDING,
        ]);
        CabinetSetting::current($cabinet);
        $administrator = User::factory()->create([
            'cabinet_id' => $cabinet->getKey(),
            'approved_at' => now(),
        ]);
        $administrator->assignRole(RoleName::ADMINISTRATOR->value);
        $cabinet->forceFill(['owner_user_id' => $administrator->getKey()])->save();
        app(CabinetFulfillmentService::class)->activate($cabinet, LicensePlan::TRIAL);

        $this->actingAs($administrator)
            ->get(route('app.configuration.connectivity-backup.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('hostedEntitlement.plan', 'trial')
                ->where('hostedEntitlement.plan_label', 'Essai de 7 jours')
                ->where('hostedEntitlement.status', 'active')
                ->where('hostedEntitlement.remaining_days', 7)
                ->where('license.state', 'not_activated')
                ->where('licenseActivation.configured', false)
                ->where('licenseActivation.refresh_configured', false)
                ->where('licenseActivation.deactivation_configured', false)
                ->where('licenseActivation.reason', 'La licence de ce cabinet est gérée par la plateforme Drclick.')
                ->where('capabilities.remote_upload.available', false)
                ->where('capabilities.google_drive.available', false));

        $this->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('app.configuration.license.activate'), [
                'serial' => 'TEST-1234-5678-9012',
            ])
            ->assertForbidden();
    }

    public function test_sensitive_action_confirmation_returns_to_configuration(): void
    {
        $administrator = User::factory()->create();
        $administrator->assignRole(RoleName::ADMINISTRATOR->value);

        $this->actingAs($administrator)
            ->get(route('app.configuration.connectivity-backup.confirm-sensitive-actions'))
            ->assertRedirect(route('password.confirm'))
            ->assertSessionHas(
                'url.intended',
                route('app.configuration.connectivity-backup.edit'),
            );

        $this->actingAs($administrator)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('app.configuration.connectivity-backup.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('permissions.sensitive_actions_confirmed', true));
    }

    public function test_settings_are_validated_and_persisted_through_the_registry(): void
    {
        $administrator = User::factory()->create();
        $administrator->assignRole(RoleName::ADMINISTRATOR->value);

        $payload = $this->validPayload();
        $payload['uploads']['session_ttl_minutes'] = 12;
        $payload['uploads']['maximum_files'] = 4;
        $payload['connectivity']['preferred_port'] = 9123;
        $payload['backups']['retention_daily'] = 10;
        $payload['backups']['maximum_storage_bytes'] = 500 * 1024 * 1024;
        $payload['updates']['check_interval_hours'] = 48;

        $this->actingAs($administrator)
            ->put(route('app.configuration.connectivity-backup.update'), $payload)
            ->assertRedirect()
            ->assertSessionHas(
                'inertia.flash_data.toast.message',
                'Préférences enregistrées sur le serveur Drclick.',
            )
            ->assertSessionHasNoErrors();

        $settings = app(ApplicationSettingService::class);
        $this->assertSame(12, $settings->get(ApplicationSettingRegistry::UPLOAD_SESSION_TTL_MINUTES));
        $this->assertSame(4, $settings->get(ApplicationSettingRegistry::UPLOAD_MAXIMUM_FILES));
        $this->assertSame(9123, $settings->get(ApplicationSettingRegistry::CONNECTIVITY_PREFERRED_PORT));
        $this->assertSame(10, $settings->get(ApplicationSettingRegistry::BACKUP_RETENTION_DAILY));
        $this->assertSame(500 * 1024 * 1024, $settings->get(ApplicationSettingRegistry::BACKUP_MAXIMUM_STORAGE_BYTES));
        $this->assertSame(48, $settings->get(ApplicationSettingRegistry::UPDATE_CHECK_INTERVAL_HOURS));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'settings.connectivity_backup_updated',
            'user_id' => $administrator->getKey(),
        ]);
    }

    public function test_enabling_lan_requires_an_available_exact_native_adapter(): void
    {
        $administrator = User::factory()->create();
        $administrator->assignRole(RoleName::ADMINISTRATOR->value);
        $payload = $this->validPayload();
        $payload['connectivity']['lan_enabled'] = true;
        $payload['connectivity']['selected_adapter_id'] = null;

        $this->actingAs($administrator)
            ->from(route('app.configuration.connectivity-backup.edit'))
            ->put(route('app.configuration.connectivity-backup.update'), $payload)
            ->assertRedirect(route('app.configuration.connectivity-backup.edit'))
            ->assertSessionHasErrors('connectivity.selected_adapter_id');

        $this->assertFalse(app(ApplicationSettingService::class)->get(
            ApplicationSettingRegistry::CONNECTIVITY_LAN_ENABLED,
        ));
    }

    public function test_supervised_lan_preference_accepts_only_the_native_inventory_id(): void
    {
        $administrator = User::factory()->create();
        $administrator->assignRole(RoleName::ADMINISTRATOR->value);
        $adapterId = 'adapter-v1:'.str_repeat('c', 64);
        $inventoryPath = storage_path('framework/testing/controller-lan-'.bin2hex(random_bytes(8)).'.json');
        file_put_contents($inventoryPath, json_encode([
            'schema_version' => 1,
            'adapters' => [[
                'id' => $adapterId,
                'label' => 'Ethernet cabinet',
                'address' => '192.168.60.20',
                'index' => 4,
            ]],
        ], JSON_THROW_ON_ERROR));
        $localOrigin = 'http://127.0.0.1:49152';
        config([
            'medismart.runtime.desktop_supervised' => true,
            'medismart.runtime.local_url' => $localOrigin,
            'medismart.runtime.lan_adapters_file' => $inventoryPath,
        ]);
        $payload = $this->validPayload();
        $payload['connectivity']['lan_enabled'] = true;
        $payload['connectivity']['selected_adapter_id'] = $adapterId;

        try {
            $this->actingAs($administrator)
                ->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
                ->put(
                    $localOrigin.route('app.configuration.connectivity-backup.update', absolute: false),
                    $payload,
                )
                ->assertRedirect()
                ->assertSessionHasNoErrors();

            $settings = app(ApplicationSettingService::class);
            $this->assertTrue($settings->get(ApplicationSettingRegistry::CONNECTIVITY_LAN_ENABLED));
            $this->assertSame(
                $adapterId,
                $settings->get(ApplicationSettingRegistry::CONNECTIVITY_SELECTED_ADAPTER_ID),
            );
        } finally {
            @unlink($inventoryPath);
        }
    }

    public function test_unavailable_automatic_backup_cannot_be_enabled_by_a_forged_request(): void
    {
        $administrator = User::factory()->create();
        $administrator->assignRole(RoleName::ADMINISTRATOR->value);
        $payload = $this->validPayload();
        $payload['backups']['automatic_enabled'] = true;

        $this->actingAs($administrator)
            ->from(route('app.configuration.connectivity-backup.edit'))
            ->put(route('app.configuration.connectivity-backup.update'), $payload)
            ->assertRedirect(route('app.configuration.connectivity-backup.edit'))
            ->assertSessionHasErrors('backups.automatic_enabled');

        $this->assertFalse(app(ApplicationSettingService::class)->get(
            ApplicationSettingRegistry::BACKUP_AUTOMATIC_ENABLED,
        ));
    }

    public function test_retired_encryption_toggle_cannot_disable_the_enforced_policy(): void
    {
        $administrator = User::factory()->create();
        $administrator->assignRole(RoleName::ADMINISTRATOR->value);
        $payload = $this->validPayload();
        $payload['backups']['encryption_enabled'] = false;

        $this->actingAs($administrator)
            ->put(route('app.configuration.connectivity-backup.update'), $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertTrue(app(ApplicationSettingService::class)->get(
            ApplicationSettingRegistry::BACKUP_ENCRYPTION_ENABLED,
        ));
    }

    public function test_retired_automatic_drive_toggle_is_ignored_by_a_forged_request(): void
    {
        $administrator = User::factory()->create();
        $administrator->assignRole(RoleName::ADMINISTRATOR->value);

        $payload = $this->validPayload();
        $payload['backups']['drive_auto_upload'] = true;

        $this->actingAs($administrator)
            ->put(route('app.configuration.connectivity-backup.update'), $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $settings = app(ApplicationSettingService::class);
        $this->assertFalse($settings->get(ApplicationSettingRegistry::BACKUP_DRIVE_AUTO_UPLOAD));
    }

    public function test_unlicensed_automatic_update_and_retired_download_flags_fail_closed(): void
    {
        $administrator = User::factory()->create();
        $administrator->assignRole(RoleName::ADMINISTRATOR->value);
        $payload = $this->validPayload();
        $payload['updates']['auto_check'] = true;
        $payload['updates']['auto_download'] = true;

        $this->actingAs($administrator)
            ->put(route('app.configuration.connectivity-backup.update'), $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $settings = app(ApplicationSettingService::class);
        $this->assertFalse($settings->get(ApplicationSettingRegistry::UPDATE_AUTO_CHECK));
        $this->assertFalse($settings->get(ApplicationSettingRegistry::UPDATE_AUTO_DOWNLOAD));

        $this->actingAs($administrator)
            ->get(route('app.configuration.connectivity-backup.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('capabilities.automatic_updates.available', false)
                ->where('settings.updates.auto_check', false)
                ->where('settings.updates.auto_download', false));
    }

    public function test_active_sessions_remain_revocable_after_the_one_time_qr_secret_is_gone(): void
    {
        $administrator = User::factory()->create();
        $administrator->assignRole(RoleName::ADMINISTRATOR->value);
        $patient = Patient::factory()->create([
            'created_by' => $administrator->getKey(),
            'first_name' => 'Nadia',
            'last_name' => 'Benali',
        ]);
        $created = app(QrUploadService::class)->create('local', $administrator, $patient);

        $this->actingAs($administrator)
            ->get(route('app.configuration.connectivity-backup.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('activeUpload', null)
                ->has('activeUploadSessions', 1)
                ->where('activeUploadSessions.0.id', $created['session']->getKey())
                ->where('activeUploadSessions.0.patient_name', 'Nadia Benali')
                ->missing('activeUploadSessions.0.url')
                ->missing('activeUploadSessions.0.public_selector')
                ->missing('activeUploadSessions.0.public_token_hash'));

        $this->actingAs($administrator)
            ->delete(route(
                'app.configuration.connectivity-backup.upload-sessions.destroy',
                $created['session'],
            ))
            ->assertRedirect();

        $this->assertSame(
            UploadSession::STATUS_REVOKED,
            $created['session']->refresh()->status,
        );
    }

    public function test_active_upload_reachability_can_be_verified_without_persisting_the_secret(): void
    {
        $administrator = User::factory()->create();
        $administrator->assignRole(RoleName::ADMINISTRATOR->value);
        config([
            'medismart.runtime.lan_listener_status' => 'active',
            'medismart.runtime.lan_port' => 49152,
        ]);
        ApplicationSetting::putValue('network.manual_ipv4', '192.168.56.20');
        $patient = Patient::factory()->create(['created_by' => $administrator->getKey()]);
        $created = app(QrUploadService::class)->create('local', $administrator, $patient);
        $this->assertIsString($created['url']);
        $path = parse_url($created['url'], PHP_URL_PATH);
        $fragment = parse_url($created['url'], PHP_URL_FRAGMENT);
        $this->assertIsString($path);
        $this->assertIsString($fragment);
        parse_str($fragment, $fragmentData);
        $this->assertInstanceOf(
            UploadSession::class,
            app(QrUploadService::class)->findByToken(basename($path), $fragmentData['v'] ?? null),
        );
        $cookie = json_encode([
            'id' => $created['session']->getKey(),
            'mode' => $created['session']->mode,
            'url' => $created['url'],
            'issued_at' => now()->getTimestamp(),
            'reachability' => [
                'state' => 'not_tested',
                'checked_at' => null,
                'message' => null,
            ],
        ], JSON_THROW_ON_ERROR);
        $probeUrl = explode('#', $created['url'], 2)[0];
        Http::fake([
            $probeUrl => Http::response('', 200, [
                'X-MediSmart-Upload-Portal' => 'ready',
            ]),
        ]);

        $response = $this->actingAs($administrator)
            ->withCookie('medismart_active_upload', $cookie)
            ->post(route('app.configuration.connectivity-backup.upload-sessions.test', $created['session']))
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $updatedCookie = $this->responseCookieValue($response, 'medismart_active_upload');

        Http::assertSent(fn ($request): bool => $request->url() === $probeUrl);

        $this->actingAs($administrator)
            ->disableCookieEncryption()
            ->withCookie('medismart_active_upload', $updatedCookie)
            ->get(route('app.configuration.connectivity-backup.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('activeUpload.id', $created['session']->getKey())
                ->where('activeUpload.reachability.state', 'verified')
                ->where('activeUpload.reachability.message', 'Le portail de téléversement Drclick répond à cette adresse.')
                ->missing('activeUpload.public_selector')
                ->missing('activeUpload.public_token_hash'));
    }

    public function test_active_upload_reachability_fails_closed_when_the_portal_header_is_missing(): void
    {
        $administrator = User::factory()->create();
        $administrator->assignRole(RoleName::ADMINISTRATOR->value);
        config([
            'medismart.runtime.lan_listener_status' => 'active',
            'medismart.runtime.lan_port' => 49152,
        ]);
        ApplicationSetting::putValue('network.manual_ipv4', '192.168.56.20');
        $patient = Patient::factory()->create(['created_by' => $administrator->getKey()]);
        $created = app(QrUploadService::class)->create('local', $administrator, $patient);
        $this->assertIsString($created['url']);
        $path = parse_url($created['url'], PHP_URL_PATH);
        $fragment = parse_url($created['url'], PHP_URL_FRAGMENT);
        $this->assertIsString($path);
        $this->assertIsString($fragment);
        parse_str($fragment, $fragmentData);
        $this->assertInstanceOf(
            UploadSession::class,
            app(QrUploadService::class)->findByToken(basename($path), $fragmentData['v'] ?? null),
        );
        $cookie = json_encode([
            'id' => $created['session']->getKey(),
            'mode' => $created['session']->mode,
            'url' => $created['url'],
            'issued_at' => now()->getTimestamp(),
        ], JSON_THROW_ON_ERROR);
        $probeUrl = explode('#', $created['url'], 2)[0];
        Http::fake([$probeUrl => Http::response('', 200)]);

        $response = $this->actingAs($administrator)
            ->withCookie('medismart_active_upload', $cookie)
            ->post(route('app.configuration.connectivity-backup.upload-sessions.test', $created['session']))
            ->assertRedirect()
            ->assertSessionHasErrors('reachability');
        $updatedCookie = $this->responseCookieValue($response, 'medismart_active_upload');

        $this->actingAs($administrator)
            ->disableCookieEncryption()
            ->withCookie('medismart_active_upload', $updatedCookie)
            ->get(route('app.configuration.connectivity-backup.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('activeUpload.id', $created['session']->getKey())
                ->where('activeUpload.reachability.state', 'failed'));
    }

    public function test_backup_history_exposes_status_without_private_paths_or_failure_details(): void
    {
        $administrator = User::factory()->create();
        $administrator->assignRole(RoleName::ADMINISTRATOR->value);
        $record = BackupRecord::query()->create([
            'filename' => 'Drclick-Backup-test.msbackup',
            'disk' => 'local',
            'local_path' => storage_path('app/private/backups/private-name.msbackup'),
            'size' => 1234,
            'sha256' => str_repeat('a', 64),
            'schema_version' => 1,
            'application_version' => 'test',
            'status' => 'failed',
            'failure_message' => 'secret internal exception',
            'drive_upload_status' => 'provider-secret-state',
            'drive_upload_bytes' => 999999,
            'drive_upload_attempts' => 600,
            'drive_upload_failure_code' => 'sensitive-provider-detail',
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
            'created_by' => $administrator->getKey(),
        ]);

        $this->actingAs($administrator)
            ->get(route('app.configuration.connectivity-backup.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('backupHistory', 1)
                ->where('backupHistory.0.id', $record->getKey())
                ->where('backupHistory.0.filename', 'Drclick-Backup-test.msbackup')
                ->where('backupHistory.0.status', 'failed')
                ->where('backupHistory.0.sha256_hint', str_repeat('a', 12))
                ->where('backupHistory.0.drive_upload_status', null)
                ->where('backupHistory.0.drive_upload_bytes', null)
                ->where('backupHistory.0.drive_upload_progress_percent', null)
                ->where('backupHistory.0.drive_upload_attempts', 0)
                ->where('backupHistory.0.drive_cancel_available', false)
                ->missing('backupHistory.0.local_path')
                ->missing('backupHistory.0.failure_message')
                ->missing('backupHistory.0.drive_upload_failure_code')
                ->missing('backupHistory.0.drive_upload_cancel_requested_at'));
    }

    public function test_drive_upload_history_is_bounded_and_exposes_only_a_cancellable_state(): void
    {
        $administrator = User::factory()->create();
        $administrator->assignRole(RoleName::ADMINISTRATOR->value);
        $record = BackupRecord::query()->create([
            'filename' => 'Drclick-Backup-progress.msbackup',
            'disk' => 'local',
            'local_path' => storage_path('app/private/backups/private-progress.msbackup'),
            'size' => 1000,
            'sha256' => str_repeat('c', 64),
            'schema_version' => 2,
            'application_version' => 'test',
            'status' => 'completed',
            'started_at' => now(),
            'completed_at' => now(),
            'drive_upload_status' => BackupRecord::DRIVE_UPLOAD_UPLOADING,
            'drive_upload_bytes' => 9000,
            'drive_upload_attempts' => 600,
            'drive_upload_failure_code' => 'sensitive-provider-detail',
            'drive_upload_updated_at' => now(),
            'created_by' => $administrator->getKey(),
        ]);

        $this->actingAs($administrator)
            ->get(route('app.configuration.connectivity-backup.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('backupHistory', 1)
                ->where('backupHistory.0.id', $record->getKey())
                ->where('backupHistory.0.drive_upload_status', 'uploading')
                ->where('backupHistory.0.drive_upload_bytes', 1000)
                ->where('backupHistory.0.drive_upload_progress_percent', 100)
                ->where('backupHistory.0.drive_upload_attempts', 3)
                ->where('backupHistory.0.drive_cancel_available', true)
                ->missing('backupHistory.0.local_path')
                ->missing('backupHistory.0.drive_upload_failure_code')
                ->missing('backupHistory.0.drive_upload_cancel_requested_at'));
    }

    public function test_a_receptionist_cannot_view_or_change_sensitive_configuration(): void
    {
        $receptionist = User::factory()->create();
        $receptionist->assignRole(RoleName::RECEPTIONIST->value);

        $this->actingAs($receptionist)
            ->get(route('app.configuration.connectivity-backup.edit'))
            ->assertForbidden();
        $this->actingAs($receptionist)
            ->put(route('app.configuration.connectivity-backup.update'), $this->validPayload())
            ->assertForbidden();
    }

    public function test_backup_only_permission_cannot_change_connectivity_settings(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(PermissionName::CONFIGURATION_BACKUPS_MANAGE->value);
        $payload = $this->validPayload();
        $payload['connectivity']['preferred_port'] = 9123;
        $payload['backups']['retention_daily'] = 11;

        $this->actingAs($user)
            ->put(route('app.configuration.connectivity-backup.update'), $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $settings = app(ApplicationSettingService::class);
        $this->assertNull($settings->get(ApplicationSettingRegistry::CONNECTIVITY_PREFERRED_PORT));
        $this->assertSame(11, $settings->get(ApplicationSettingRegistry::BACKUP_RETENTION_DAILY));

        $this->actingAs($user)
            ->get(route('app.configuration.connectivity-backup.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('permissions.manage_settings', false)
                ->where('permissions.manage_backups', true)
                ->where('permissions.manage_drive', false)
                ->where('patients', []));

        $this->actingAs($user)
            ->get(route('app.configuration.backup.local'))
            ->assertRedirect(route('password.confirm'));
        $this->actingAs($user)
            ->get(route('app.configuration.backup.google.files'))
            ->assertForbidden();
    }

    public function test_drive_manager_can_view_and_cancel_only_bounded_upload_history(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(PermissionName::CONFIGURATION_DRIVE_MANAGE->value);
        $record = BackupRecord::query()->create([
            'filename' => 'Drclick-Backup-drive-manager.msbackup',
            'disk' => 'local',
            'local_path' => storage_path('app/private/backups/private-drive-manager.msbackup'),
            'size' => 2048,
            'sha256' => str_repeat('d', 64),
            'schema_version' => 2,
            'application_version' => 'test',
            'status' => 'completed',
            'started_at' => now(),
            'completed_at' => now(),
            'drive_upload_status' => BackupRecord::DRIVE_UPLOAD_QUEUED,
            'drive_upload_updated_at' => now(),
            'created_by' => $user->getKey(),
        ]);

        $this->actingAs($user)
            ->get(route('app.configuration.connectivity-backup.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('permissions.manage_backups', false)
                ->where('permissions.manage_drive', true)
                ->has('backupHistory', 1)
                ->where('backupHistory.0.id', $record->getKey())
                ->where('backupHistory.0.drive_upload_status', 'queued')
                ->where('backupHistory.0.drive_cancel_available', true)
                ->missing('backupHistory.0.local_path')
                ->missing('backupHistory.0.drive_upload_failure_code'));
    }

    public function test_connectivity_only_permission_cannot_change_backup_policy(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(PermissionName::CONFIGURATION_CONNECTIVITY_MANAGE->value);
        $payload = $this->validPayload();
        $payload['connectivity']['preferred_port'] = 9124;
        $payload['backups']['retention_daily'] = 13;

        $this->actingAs($user)
            ->put(route('app.configuration.connectivity-backup.update'), $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $settings = app(ApplicationSettingService::class);
        $this->assertSame(9124, $settings->get(ApplicationSettingRegistry::CONNECTIVITY_PREFERRED_PORT));
        $this->assertSame(7, $settings->get(ApplicationSettingRegistry::BACKUP_RETENTION_DAILY));

        $this->actingAs($user)
            ->get(route('app.configuration.backup.local'))
            ->assertForbidden();
    }

    public function test_diagnostics_only_permission_receives_no_patient_drive_or_backup_metadata(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(PermissionName::CONFIGURATION_DIAGNOSTICS_VIEW->value);
        Patient::factory()->create(['created_by' => $user->getKey()]);
        $cabinet = CabinetSetting::current();
        DriveBackupConnection::query()->create([
            'cabinet_setting_id' => $cabinet->getKey(),
            'email' => 'private-clinic@example.test',
            'folder_name' => 'Private folder',
            'access_token' => 'drive-access-token',
            'refresh_token' => 'drive-refresh-token',
            'token_expires_at' => now()->addHour(),
        ]);
        BackupRecord::query()->create([
            'filename' => 'Drclick-Backup-private.msbackup',
            'disk' => 'local',
            'size' => 100,
            'sha256' => str_repeat('b', 64),
            'schema_version' => 1,
            'application_version' => 'test',
            'status' => 'completed',
            'started_at' => now(),
            'completed_at' => now(),
            'created_by' => $user->getKey(),
        ]);

        $this->actingAs($user)
            ->get(route('app.configuration.connectivity-backup.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('permissions.view_diagnostics', true)
                ->where('permissions.manage_settings', false)
                ->where('permissions.manage_backups', false)
                ->where('permissions.manage_drive', false)
                ->where('patients', [])
                ->where('backupHistory', [])
                ->where('backup.google_drive_email', null)
                ->where('backup.google_drive_connected', false)
                ->where('licenseActivation.installation_id_hint', '—'));

        $this->actingAs($user)
            ->put(route('app.configuration.connectivity-backup.update'), $this->validPayload())
            ->assertForbidden();
    }

    /** @return array<string, mixed> */
    private function validPayload(): array
    {
        return [
            'uploads' => [
                'default_mode' => 'local',
                'session_ttl_minutes' => 15,
                'maximum_files' => 10,
                'maximum_individual_bytes' => 20 * 1024 * 1024,
                'maximum_total_bytes' => 100 * 1024 * 1024,
            ],
            'connectivity' => [
                'lan_enabled' => false,
                'selected_adapter_id' => null,
                'preferred_port' => null,
                'firewall_diagnostics_enabled' => true,
            ],
            'backups' => [
                'automatic_enabled' => false,
                'schedule_time' => '02:00',
                'retention_daily' => 7,
                'retention_weekly' => 4,
                'retention_monthly' => 12,
                'maximum_storage_bytes' => null,
            ],
            'updates' => [
                'auto_check' => true,
                'channel' => 'stable',
                'check_interval_hours' => 24,
                'auto_download' => false,
            ],
        ];
    }

    private function responseCookieValue($response, string $name): string
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name) {
                return $cookie->getValue();
            }
        }

        $this->fail("Expected response cookie [{$name}] was not set.");
    }
}
