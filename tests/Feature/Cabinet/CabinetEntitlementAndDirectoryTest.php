<?php

namespace Tests\Feature\Cabinet;

use App\Enums\CabinetStatus;
use App\Enums\LicensePlan;
use App\Models\Cabinet;
use App\Models\CabinetPublicProfile;
use App\Models\License;
use App\Models\User;
use App\Services\Cabinet\CabinetDirectoryListing;
use App\Services\Cabinet\CabinetEntitlementService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\ActivatesSignedLicense;
use Tests\TestCase;

class CabinetEntitlementAndDirectoryTest extends TestCase
{
    use ActivatesSignedLicense;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-01T10:00:00Z'));
    }

    protected function tearDown(): void
    {
        $this->cleanUpSignedLicenseFeatures();

        parent::tearDown();
    }

    public function test_no_hosted_entitlement_exists_for_guests_admins_or_unscoped_users(): void
    {
        $service = $this->entitlements();

        $this->assertNull($service->hostedEntitlement(null));
        $this->assertNull($service->hostedEntitlement(User::factory()->create()));
        $this->assertNull($service->hostedEntitlement(User::factory()->create(['is_platform_admin' => true])));
    }

    public function test_a_platform_admin_attached_to_a_hosted_cabinet_still_has_no_hosted_entitlement(): void
    {
        $cabinet = $this->cabinet($this->hostedLicense());
        $admin = User::factory()->create(['is_platform_admin' => true, 'cabinet_id' => $cabinet->getKey()]);

        $this->assertNull($this->entitlements()->hostedEntitlement($admin));
    }

    public function test_a_cabinet_member_resolves_the_cabinets_hosted_plan(): void
    {
        $license = $this->hostedLicense();
        $member = $this->member($this->cabinet($license));

        $this->assertTrue($license->is($this->entitlements()->hostedEntitlement($member)));
    }

    public function test_a_machine_licence_row_is_not_a_hosted_plan(): void
    {
        $machine = License::query()->create([
            'license_id' => 'machine-'.Str::random(6),
            'product' => 'medismart-desktop',
            'edition' => 'professional',
            'status' => 'active',
            'issued_at' => now(),
        ]);

        $this->assertNull($this->entitlements()->hostedEntitlement($this->member($this->cabinet($machine))));
    }

    public function test_a_hosted_plan_enables_only_saas_safe_features(): void
    {
        $member = $this->member($this->cabinet($this->hostedLicense()));

        $this->assertTrue($this->entitlements()->featureEnabled($member, 'custom_branding'));
        $this->assertTrue($this->entitlements()->featureEnabled($member, 'multi_user'));
        $this->assertFalse($this->entitlements()->featureEnabled($member, 'remote_upload'));
        $this->assertFalse($this->entitlements()->featureEnabled($member, 'google_drive_backup'));
        $this->assertFalse($this->entitlements()->featureEnabled($member, 'automatic_updates'));
    }

    public function test_a_hosted_plan_grants_nothing_once_expired_or_while_the_cabinet_is_inactive(): void
    {
        $license = $this->hostedLicense(LicensePlan::TRIAL, now()->addDay());
        $cabinet = $this->cabinet($license);
        $member = $this->member($cabinet);

        $this->assertTrue($this->entitlements()->featureEnabled($member, 'multi_user'));

        $this->travel(2)->days();
        $this->assertFalse($this->entitlements()->featureEnabled($member->fresh(), 'multi_user'));

        $license->forceFill(['expires_at' => null])->save();
        $cabinet->forceFill(['status' => CabinetStatus::SUSPENDED])->save();
        $this->assertFalse($this->entitlements()->featureEnabled($member->fresh(), 'multi_user'));
    }

    public function test_a_hosted_plan_ignores_the_machine_certificate(): void
    {
        $this->activateSignedLicenseFeatures(['remote_upload' => true]);
        $member = $this->member($this->cabinet($this->hostedLicense()));

        $this->assertFalse($this->entitlements()->featureEnabled($member, 'remote_upload'));
    }

    public function test_without_a_hosted_plan_the_signed_certificate_decides(): void
    {
        $this->assertFalse($this->entitlements()->featureEnabled(null, 'remote_upload'));

        $this->activateSignedLicenseFeatures(['remote_upload' => true, 'multi_user' => false]);

        $this->assertTrue($this->entitlements()->featureEnabled(null, 'remote_upload'));
        $this->assertTrue($this->entitlements()->featureEnabled(User::factory()->create(), 'remote_upload'));
        $this->assertFalse($this->entitlements()->featureEnabled(null, 'multi_user'));
    }

    public function test_remaining_days_round_up_and_never_go_negative(): void
    {
        $service = $this->entitlements();

        $this->assertNull($service->remainingDays(null));
        $this->assertNull($service->remainingDays($this->hostedLicense()));
        $this->assertSame(2, $service->remainingDays($this->hostedLicense(LicensePlan::TRIAL, now()->addHours(36))));
        $this->assertSame(7, $service->remainingDays($this->hostedLicense(LicensePlan::TRIAL, now()->addDays(7))));
        $this->assertSame(1, $service->remainingDays($this->hostedLicense(LicensePlan::TRIAL, now()->addSecond())));
        $this->assertSame(0, $service->remainingDays($this->hostedLicense(LicensePlan::TRIAL, now()->subDay())));
    }

    public function test_listing_a_cabinet_creates_its_public_profile_once(): void
    {
        $cabinet = $this->cabinet();
        $directory = app(CabinetDirectoryListing::class);

        $this->assertFalse($directory->isListed($cabinet));

        $profile = $directory->setListed($cabinet, true);

        $this->assertTrue($profile->exists);
        $this->assertSame($cabinet->getKey(), (int) $profile->cabinet_id);
        $this->assertTrue($directory->isListed($cabinet));

        $hidden = $directory->setListed($cabinet, false);

        $this->assertTrue($hidden->is($profile));
        $this->assertFalse($directory->isListed($cabinet));
        $this->assertSame(1, CabinetPublicProfile::withoutCabinetScope()->where('cabinet_id', $cabinet->getKey())->count());
    }

    public function test_listing_one_cabinet_never_lists_another(): void
    {
        $listed = $this->cabinet();
        $other = $this->cabinet();

        app(CabinetDirectoryListing::class)->setListed($listed, true);

        $this->assertFalse(app(CabinetDirectoryListing::class)->isListed($other));
    }

    private function entitlements(): CabinetEntitlementService
    {
        return app(CabinetEntitlementService::class);
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

    private function member(Cabinet $cabinet): User
    {
        return User::factory()->create(['cabinet_id' => $cabinet->getKey(), 'approved_at' => now()]);
    }

    private function hostedLicense(LicensePlan $plan = LicensePlan::LIFETIME, ?CarbonImmutable $expiresAt = null): License
    {
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
}
