<?php

namespace Tests\Feature\Configuration;

use App\Configuration\ApplicationSettingRegistry;
use App\Enums\CabinetStatus;
use App\Enums\LicensePlan;
use App\Enums\RoleName;
use App\Models\ApplicationSetting;
use App\Models\BackupRecord;
use App\Models\Cabinet;
use App\Models\CabinetSetting;
use App\Models\Patient;
use App\Models\UploadedDocument;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\CabinetFulfillmentService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InstallationMaintenanceTenantBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Mail::fake();
    }

    public function test_cabinet_owner_sees_tenant_uploads_but_no_global_installation_data(): void
    {
        [, $ownerA] = $this->createActiveCabinet('Cabinet Alpha', LicensePlan::TRIAL);
        [, $ownerB] = $this->createActiveCabinet('Cabinet Beta', LicensePlan::LIFETIME);

        ApplicationSetting::putValue(
            ApplicationSettingRegistry::UPLOAD_SESSION_TTL_MINUTES,
            29,
            type: 'integer',
            group: 'uploads',
        );
        BackupRecord::query()->create([
            'filename' => 'whole-installation-secret.msbackup',
            'application_version' => 'test',
            'status' => 'completed',
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
            'created_by' => $ownerB->getKey(),
        ]);

        $this->actingAs($ownerB);
        $this->createPendingUpload($ownerB, 'Beta', 'beta-secret.pdf');

        $this->actingAs($ownerA);
        $this->createPendingUpload($ownerA, 'Alpha', 'alpha-visible.pdf');

        $this->get(route('app.configuration.connectivity-backup.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('configuration/ConnectivityAndBackup')
                ->where('settings.uploads.session_ttl_minutes', 15)
                ->where('runtime.backups.last_filename', null)
                ->has('backupHistory', 0)
                ->where('permissions.manage_settings', false)
                ->where('permissions.manage_backups', false)
                ->where('permissions.manage_restore', false)
                ->where('permissions.manage_drive', false)
                ->where('permissions.manage_license', false)
                ->where('permissions.view_diagnostics', false)
                ->where('permissions.manage_upload_sessions', true)
                ->has('activeUploadSessions', 1)
                ->where('activeUploadSessions.0.patient_name', 'Alpha Patient')
                ->has('pendingUploads', 1)
                ->where('pendingUploads.0.name', 'alpha-visible.pdf')
                ->has('patients', 1)
                ->where('hostedEntitlement.plan', LicensePlan::TRIAL->value)
                ->where('license.state', 'not_activated'));
    }

    public function test_cabinet_owner_cannot_read_or_mutate_whole_installation_maintenance(): void
    {
        [, $owner] = $this->createActiveCabinet('Cabinet Alpha', LicensePlan::TRIAL);
        [, $otherOwner] = $this->createActiveCabinet('Cabinet Beta', LicensePlan::LIFETIME);
        $record = BackupRecord::query()->create([
            'filename' => 'whole-installation-secret.msbackup',
            'application_version' => 'test',
            'status' => 'completed',
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
            'created_by' => $otherOwner->getKey(),
        ]);
        ApplicationSetting::putValue(
            ApplicationSettingRegistry::UPLOAD_SESSION_TTL_MINUTES,
            29,
            type: 'integer',
            group: 'uploads',
        );
        config(['medismart.backups.legacy_restore_enabled' => true]);

        $this->actingAs($owner)->withSession([
            'auth.password_confirmed_at' => time(),
        ]);

        $this->get(route('app.configuration.backup.local'))->assertForbidden();
        $this->post(route('app.configuration.backup.local.encrypted'))->assertForbidden();
        $this->post(route('app.configuration.backup.restore'))->assertForbidden();
        $this->postJson(route('app.configuration.backup.restore.prepare'))->assertForbidden();
        $this->postJson(route('app.configuration.backup.google.prepare'))->assertForbidden();
        $this->getJson(route('app.configuration.backup.google.files'))->assertForbidden();
        $this->post(route('app.configuration.backup.google.files.download', ['fileId' => 'remote-id']))
            ->assertForbidden();
        $this->delete(route('app.configuration.backup.google.files.destroy', ['fileId' => 'remote-id']))
            ->assertForbidden();
        $this->delete(route('app.configuration.backup.google.disconnect'))->assertForbidden();
        $this->post(route('app.configuration.backup.google.test'))->assertForbidden();
        $this->post(route('app.configuration.backup.drive.store'))->assertForbidden();
        $this->delete(route('app.configuration.backup.drive.cancel', ['backupRecordId' => $record->getKey()]))
            ->assertForbidden();
        $this->put(route('app.configuration.connectivity-backup.update'))->assertForbidden();
        $this->get(route('app.configuration.connectivity-backup.confirm-sensitive-actions'))->assertForbidden();
        $this->post(route('app.configuration.connectivity-backup.upload-sessions.store'))->assertForbidden();
        $this->post(route('app.configuration.connectivity-backup.upload-sessions.test', [
            'uploadSession' => '00000000-0000-4000-8000-000000000000',
        ]))->assertForbidden();
        $this->post(route('app.configuration.updates.prepare-install'))->assertForbidden();
        $this->post(route('app.configuration.license.activate'))->assertForbidden();
        $this->post(route('app.configuration.license.refresh'))->assertForbidden();
        $this->delete(route('app.configuration.license.destroy'))->assertForbidden();

        $this->assertDatabaseCount('backup_records', 1);
        $this->assertDatabaseCount('google_drive_oauth_attempts', 0);
        $this->assertSame(
            29,
            ApplicationSetting::valueFor(ApplicationSettingRegistry::UPLOAD_SESSION_TTL_MINUTES),
        );
    }

    /** @return array{Cabinet, User} */
    private function createActiveCabinet(string $name, LicensePlan $plan): array
    {
        $cabinet = Cabinet::query()->create([
            'name' => $name,
            'status' => CabinetStatus::PENDING,
        ]);
        $settings = CabinetSetting::current($cabinet);
        $owner = User::factory()->create([
            'name' => $name.' Owner',
            'cabinet_id' => $cabinet->getKey(),
            'cabinet_setting_id' => $settings->getKey(),
            'approved_at' => now(),
        ]);
        $owner->assignRole(RoleName::ADMINISTRATOR->value);
        $cabinet->forceFill(['owner_user_id' => $owner->getKey()])->save();

        return [
            app(CabinetFulfillmentService::class)->activate($cabinet, $plan),
            $owner,
        ];
    }

    private function createPendingUpload(User $owner, string $cabinetName, string $filename): void
    {
        $patient = Patient::factory()->create([
            'first_name' => $cabinetName,
            'last_name' => 'Patient',
            'created_by' => $owner->getKey(),
        ]);
        $session = UploadSession::query()->create([
            'public_selector' => str_repeat(strtolower($cabinetName[0]), 22),
            'public_token_hash' => hash('sha256', $cabinetName.'-token'),
            'mode' => 'local',
            'purpose' => 'medical_document',
            'patient_id' => $patient->getKey(),
            'created_by' => $owner->getKey(),
            'expires_at' => now()->addMinutes(10),
            'maximum_files' => 5,
            'maximum_individual_bytes' => 5_000_000,
            'maximum_total_bytes' => 10_000_000,
            'allowed_mime_types' => ['application/pdf'],
            'status' => UploadSession::STATUS_PENDING,
        ]);
        UploadedDocument::query()->create([
            'upload_session_id' => $session->getKey(),
            'patient_id' => $patient->getKey(),
            'original_name' => $filename,
            'stored_name' => $filename,
            'disk' => 'local',
            'path' => 'uploads/'.$filename,
            'mime_type' => 'application/pdf',
            'size' => 123,
            'sha256' => hash('sha256', $filename),
            'status' => UploadedDocument::STATUS_PENDING_REVIEW,
            'uploaded_at' => now(),
        ]);
    }
}
