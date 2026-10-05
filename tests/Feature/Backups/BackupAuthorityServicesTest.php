<?php

namespace Tests\Feature\Backups;

use App\Enums\CabinetStatus;
use App\Enums\LicensePlan;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\Cabinet;
use App\Models\CabinetSetting;
use App\Models\DriveBackupConnection;
use App\Models\License;
use App\Models\User;
use App\Services\Backups\DriveBackupAuthority;
use App\Services\Backups\DriveBackupEntitlement;
use App\Services\Backups\LocalBackupAuthority;
use App\Services\InstallationMaintenanceAccessService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\ActivatesSignedLicense;
use Tests\TestCase;

/**
 * Direct coverage of who may manage, control and revoke the Drive backup and
 * the local backups, and of the Drive entitlement decision.
 */
class BackupAuthorityServicesTest extends TestCase
{
    use ActivatesSignedLicense;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        config([
            'medismart.runtime.desktop_supervised' => true,
            'medismart.runtime.installation_id' => (string) Str::uuid(),
        ]);
    }

    protected function tearDown(): void
    {
        $this->cleanUpSignedLicenseFeatures();

        parent::tearDown();
    }

    public function test_any_settings_row_may_receive_archives_while_no_cabinet_exists(): void
    {
        $global = CabinetSetting::query()->create(CabinetSetting::defaults());

        $this->assertTrue($this->drive()->installationMayUploadTo($global));
    }

    public function test_a_single_cabinet_desktop_may_upload_to_its_own_or_the_global_row_only(): void
    {
        $cabinet = $this->cabinet();
        $own = CabinetSetting::current($cabinet);
        $global = CabinetSetting::query()->create(CabinetSetting::defaults());

        $this->assertTrue($this->drive()->installationMayUploadTo($own));
        $this->assertTrue($this->drive()->installationMayUploadTo($global));

        $this->cabinet();

        $this->assertFalse($this->drive()->installationMayUploadTo($own));
        $this->assertFalse($this->drive()->installationMayUploadTo($global));
    }

    public function test_the_doctor_of_the_only_cabinet_manages_and_controls_the_drive_backup(): void
    {
        $doctor = $this->member($this->cabinet(), RoleName::DOCTOR);

        $this->assertTrue($this->drive()->mayManage($doctor));
        $this->assertTrue($this->drive()->mayControl($doctor));
        $this->assertTrue($this->drive()->mayRevoke($doctor));
        $this->assertTrue($this->drive()->isDesktopClinicDoctor($doctor));
        $this->assertFalse($this->drive()->isDoctorOnSharedDesktop($doctor));
    }

    public function test_the_cabinet_owner_counts_as_its_doctor_without_the_doctor_role(): void
    {
        $cabinet = $this->cabinet();
        $owner = $this->member($cabinet, RoleName::ASSISTANT);
        $owner->givePermissionTo(PermissionName::CONFIGURATION_DRIVE_MANAGE->value);
        $cabinet->forceFill(['owner_user_id' => $owner->getKey()])->save();

        $this->assertTrue($this->drive()->mayControl($owner->fresh()));
    }

    public function test_an_assistant_with_the_drive_permission_cannot_reach_the_drive_backup(): void
    {
        $assistant = $this->member($this->cabinet(), RoleName::ASSISTANT);
        $assistant->givePermissionTo(PermissionName::CONFIGURATION_DRIVE_MANAGE->value);

        $this->assertFalse($this->drive()->mayManage($assistant->fresh()));
        $this->assertFalse($this->drive()->mayControl($assistant->fresh()));
        $this->assertFalse($this->drive()->mayRevoke($assistant->fresh()));
    }

    public function test_an_unapproved_doctor_is_not_the_desktop_doctor(): void
    {
        $doctor = $this->member($this->cabinet(), RoleName::DOCTOR, approved: false);

        $this->assertFalse($this->drive()->isDesktopClinicDoctor($doctor));
        $this->assertFalse($this->drive()->mayManage($doctor));
    }

    public function test_a_cabinet_doctor_is_outside_the_boundary_on_an_unsupervised_install(): void
    {
        $doctor = $this->member($this->cabinet(), RoleName::DOCTOR);
        config(['medismart.runtime.desktop_supervised' => false]);

        $this->assertFalse($this->drive()->mayManage($doctor));
        $this->assertFalse($this->local()->mayManage($doctor));
    }

    public function test_an_unscoped_maintainer_manages_but_only_a_doctor_controls(): void
    {
        $maintainer = User::factory()->create();
        $maintainer->givePermissionTo(PermissionName::CONFIGURATION_DRIVE_MANAGE->value);

        $this->assertTrue($this->drive()->mayManage($maintainer));
        $this->assertFalse($this->drive()->mayControl($maintainer));

        $maintainer->assignRole(RoleName::DOCTOR->value);

        $this->assertTrue($this->drive()->mayControl($maintainer->fresh()));
    }

    public function test_an_unscoped_account_without_the_permission_is_refused(): void
    {
        $this->assertFalse($this->drive()->mayManage(User::factory()->create()));
        $this->assertFalse($this->drive()->mayManage(null));
        $this->assertFalse($this->drive()->mayControl(null));
        $this->assertFalse($this->drive()->mayRevoke(null));
    }

    public function test_a_platform_administrator_manages_but_does_not_control_the_clinics_google_account(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);

        $this->assertTrue($this->drive()->mayManage($admin));
        $this->assertFalse($this->drive()->mayControl($admin));
    }

    public function test_a_cabinet_doctor_controls_only_their_own_cabinet_row(): void
    {
        $cabinet = $this->cabinet();
        $doctor = $this->member($cabinet, RoleName::DOCTOR);
        $global = CabinetSetting::query()->create(CabinetSetting::defaults());

        $this->assertTrue($this->drive()->mayControlCabinet($doctor, CabinetSetting::current($cabinet)));
        $this->assertFalse($this->drive()->mayControlCabinet($doctor, $global));
        $this->assertFalse($this->drive()->mayControlCabinet(null, $global));
    }

    public function test_an_unscoped_doctor_controls_any_cabinet_row(): void
    {
        $cabinet = $this->cabinet();
        $doctor = User::factory()->create(['approved_at' => now()]);
        $doctor->assignRole(RoleName::DOCTOR->value);

        $this->assertTrue($this->drive()->mayControlCabinet($doctor, CabinetSetting::current($cabinet)));
    }

    public function test_a_shared_desktop_refuses_every_drive_action_but_revocation_of_the_doctors_own_grant(): void
    {
        $cabinet = $this->cabinet();
        $doctor = $this->member($cabinet, RoleName::DOCTOR);
        $this->cabinet();

        $this->assertTrue($this->drive()->isDoctorOnSharedDesktop($doctor));
        $this->assertFalse($this->drive()->mayManage($doctor));
        $this->assertFalse($this->drive()->mayControl($doctor));
        $this->assertFalse($this->drive()->mayRevoke($doctor));

        $this->connectDrive(CabinetSetting::current($cabinet));

        $this->assertTrue($this->drive()->mayRevoke($doctor));
    }

    public function test_a_shared_desktop_doctor_cannot_revoke_another_cabinets_grant(): void
    {
        $cabinet = $this->cabinet();
        $other = $this->cabinet();
        $doctor = $this->member($cabinet, RoleName::DOCTOR);
        $this->connectDrive(CabinetSetting::current($other));

        $this->assertFalse($this->drive()->mayRevoke($doctor));
    }

    public function test_a_disconnected_grant_cannot_be_revoked_again(): void
    {
        $cabinet = $this->cabinet();
        $doctor = $this->member($cabinet, RoleName::DOCTOR);
        $this->cabinet();
        $this->connectDrive(CabinetSetting::current($cabinet), refreshToken: null);

        $this->assertFalse($this->drive()->mayRevoke($doctor));
    }

    public function test_drive_authorization_failures_carry_the_right_message(): void
    {
        $maintainer = User::factory()->create();
        $maintainer->givePermissionTo(PermissionName::CONFIGURATION_DRIVE_MANAGE->value);

        $this->assertAbortMessage(
            DriveBackupAuthority::DENIAL_MESSAGE,
            fn () => $this->drive()->authorizeControl($maintainer),
        );
        $this->assertAbortMessage(
            InstallationMaintenanceAccessService::DENIAL_MESSAGE,
            fn () => $this->drive()->authorizeControl(User::factory()->create()),
        );
        $this->assertAbortMessage(
            InstallationMaintenanceAccessService::DENIAL_MESSAGE,
            fn () => $this->drive()->authorizeManage(null),
        );
        $this->assertAbortMessage(
            DriveBackupAuthority::DENIAL_MESSAGE,
            fn () => $this->drive()->authorizeRevoke($maintainer),
        );
    }

    public function test_local_backups_follow_the_backup_permission_and_the_clinic_boundary(): void
    {
        $cabinet = $this->cabinet();
        $doctor = $this->member($cabinet, RoleName::DOCTOR);
        $assistant = $this->member($cabinet, RoleName::ASSISTANT);
        $maintainer = User::factory()->create();

        $this->assertTrue($this->local()->mayManage($doctor));
        $this->assertFalse($this->local()->mayManage($assistant));
        $this->assertFalse($this->local()->mayManage($maintainer));
        $this->assertFalse($this->local()->mayManage(null));

        $maintainer->givePermissionTo(PermissionName::CONFIGURATION_BACKUPS_MANAGE->value);
        $this->assertTrue($this->local()->mayManage($maintainer->fresh()));

        $this->cabinet();
        $this->assertFalse($this->local()->mayManage($doctor));
        $this->assertAbortMessage(
            InstallationMaintenanceAccessService::DENIAL_MESSAGE,
            fn () => $this->local()->authorizeManage($doctor),
        );
    }

    public function test_the_drive_entitlement_is_refused_without_a_licence_or_hosted_plan(): void
    {
        $this->assertFalse($this->entitlement()->granted());

        $this->cabinet();
        $this->assertFalse($this->entitlement()->granted());
    }

    public function test_a_signed_machine_licence_with_the_feature_grants_the_drive_backup_anywhere(): void
    {
        config(['medismart.runtime.desktop_supervised' => false]);
        $this->activateSignedLicenseFeatures([DriveBackupEntitlement::FEATURE => true]);

        $this->assertTrue($this->entitlement()->granted());
    }

    public function test_a_signed_licence_without_the_feature_does_not_grant_it(): void
    {
        config(['medismart.runtime.desktop_supervised' => false]);
        $this->activateSignedLicenseFeatures(['remote_upload' => true]);

        $this->assertFalse($this->entitlement()->granted());
    }

    public function test_the_single_active_hosted_cabinet_grants_the_drive_backup_on_a_supervised_desktop(): void
    {
        $this->cabinet(license: $this->hostedLicense());

        $this->assertTrue($this->entitlement()->granted());

        config(['medismart.runtime.desktop_supervised' => false]);
        $this->assertFalse($this->entitlement()->granted());
    }

    public function test_an_expired_or_suspended_hosted_plan_does_not_grant_the_drive_backup(): void
    {
        $license = $this->hostedLicense(LicensePlan::TRIAL, now()->subMinute()->toImmutable());
        $this->cabinet(license: $license);
        $this->assertFalse($this->entitlement()->granted());

        $license->forceFill(['expires_at' => null, 'status' => 'suspended'])->save();
        $this->assertFalse($this->entitlement()->granted());

        $license->forceFill(['status' => 'active'])->save();
        $this->assertTrue($this->entitlement()->granted());
    }

    public function test_an_inactive_cabinet_or_a_second_cabinet_withdraws_the_hosted_drive_entitlement(): void
    {
        $cabinet = $this->cabinet(license: $this->hostedLicense());
        $cabinet->forceFill(['status' => CabinetStatus::PENDING])->save();
        $this->assertFalse($this->entitlement()->granted());

        $cabinet->forceFill(['status' => CabinetStatus::ACTIVE])->save();
        $this->assertTrue($this->entitlement()->granted());

        $this->cabinet(license: $this->hostedLicense());
        $this->assertFalse($this->entitlement()->granted());
    }

    public function test_a_machine_licence_row_attached_to_the_cabinet_is_not_a_hosted_plan(): void
    {
        $license = License::query()->create([
            'license_id' => 'machine-'.Str::random(8),
            'product' => 'medismart-desktop',
            'edition' => 'professional',
            'status' => 'active',
            'issued_at' => now(),
        ]);
        $this->cabinet(license: $license);

        $this->assertFalse($this->entitlement()->granted());
    }

    private function drive(): DriveBackupAuthority
    {
        return app(DriveBackupAuthority::class);
    }

    private function local(): LocalBackupAuthority
    {
        return app(LocalBackupAuthority::class);
    }

    private function entitlement(): DriveBackupEntitlement
    {
        return app(DriveBackupEntitlement::class);
    }

    private function cabinet(?License $license = null): Cabinet
    {
        return Cabinet::query()->create([
            'name' => 'Cabinet '.Str::random(6),
            'status' => CabinetStatus::ACTIVE,
            'activated_at' => now(),
            'license_id' => $license?->getKey(),
        ]);
    }

    private function hostedLicense(
        LicensePlan $plan = LicensePlan::LIFETIME,
        ?CarbonImmutable $expiresAt = null,
    ): License {
        return License::query()->create([
            'license_id' => 'hosted-'.Str::random(10),
            'product' => 'medismart-hosted',
            'edition' => 'hosted',
            'plan' => $plan,
            'status' => 'active',
            'issued_at' => now(),
            'expires_at' => $expiresAt,
        ]);
    }

    private function member(Cabinet $cabinet, RoleName $role, bool $approved = true): User
    {
        $user = User::factory()->create([
            'cabinet_id' => $cabinet->getKey(),
            'approved_at' => $approved ? now() : null,
        ]);
        $user->assignRole($role->value);

        return $user;
    }

    private function connectDrive(CabinetSetting $settings, ?string $refreshToken = 'refresh-token'): DriveBackupConnection
    {
        return DriveBackupConnection::query()->create([
            'cabinet_setting_id' => $settings->getKey(),
            'email' => 'cabinet@example.test',
            'folder_name' => 'Drclick Backups',
            'folder_id' => 'drive-folder-id',
            'access_token' => 'access-token',
            'refresh_token' => $refreshToken,
            'token_expires_at' => now()->addHour(),
        ]);
    }

    private function assertAbortMessage(string $message, callable $callback): void
    {
        try {
            $callback();
            $this->fail('The action was authorized.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
            $this->assertSame($message, $exception->getMessage());
        }
    }
}
