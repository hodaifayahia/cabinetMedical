<?php

namespace Tests\Feature\Cabinet;

use App\Enums\CabinetStatus;
use App\Enums\LicensePlan;
use App\Filament\Resources\Cabinets\Pages\ListCabinets;
use App\Filament\Resources\Licenses\LicenseResource as FilamentLicenseResource;
use App\Mail\CabinetActivatedMail;
use App\Mail\CabinetLicenseCodeIssuedMail;
use App\Mail\CabinetLicenseUpdatedMail;
use App\Models\Cabinet;
use App\Models\License;
use App\Models\User;
use App\Services\Cabinet\CabinetAccessService;
use App\Services\CabinetFulfillmentService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

class CabinetActivationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_activation_issues_a_perpetual_license_and_notifies_owner(): void
    {
        Mail::fake();

        $owner = User::factory()->create(['email' => 'owner@example.com']);
        $cabinet = Cabinet::query()->create([
            'name' => 'Cabinet Pending',
            'status' => CabinetStatus::PENDING,
            'owner_user_id' => $owner->getKey(),
        ]);
        $owner->forceFill(['cabinet_id' => $cabinet->getKey()])->save();

        $service = app(CabinetFulfillmentService::class);
        $service->activate($cabinet->fresh());

        $cabinet->refresh();
        $this->assertSame(CabinetStatus::ACTIVE, $cabinet->status);
        $this->assertNotNull($cabinet->activated_at);
        $this->assertNotNull($cabinet->license_id);

        $license = License::query()->findOrFail($cabinet->license_id);
        $this->assertSame('active', $license->status);
        $this->assertSame(LicensePlan::LIFETIME, $license->plan);
        $this->assertNull($license->expires_at, 'A hosted license is perpetual.');

        // CabinetActivatedMail implements ShouldQueue, so under Mail::fake()
        // it is recorded as queued rather than sent.
        Mail::assertQueued(CabinetActivatedMail::class);

        $this->assertDatabaseHas('audit_logs', ['action' => 'cabinet.activated']);
    }

    public function test_trial_activation_expires_at_the_exact_seven_day_boundary(): void
    {
        Mail::fake();
        $startsAt = CarbonImmutable::parse('2026-08-08 10:00:00', config('app.timezone'));
        $this->travelTo($startsAt);

        $owner = User::factory()->create([
            'email' => 'trial-owner@example.com',
            'approved_at' => now(),
        ]);
        $cabinet = Cabinet::query()->create([
            'name' => 'Cabinet Trial',
            'status' => CabinetStatus::PENDING,
            'owner_user_id' => $owner->getKey(),
        ]);
        $owner->forceFill(['cabinet_id' => $cabinet->getKey()])->save();

        app(CabinetFulfillmentService::class)->activate($cabinet, LicensePlan::TRIAL);

        $cabinet->refresh();
        $license = $cabinet->license;
        $this->assertNotNull($license);
        $this->assertSame(LicensePlan::TRIAL, $license->plan);
        $this->assertTrue($license->expires_at?->equalTo($startsAt->addDays(7)) === true);

        $this->travelTo($startsAt->addDays(7)->subSecond());
        $this->assertTrue(app(CabinetAccessService::class)->isEligible($owner->fresh()));

        $this->travelTo($startsAt->addDays(7));
        $owner = $owner->fresh();
        $this->assertSame(
            CabinetAccessService::REASON_LICENSE_EXPIRED,
            app(CabinetAccessService::class)->denialReason($owner),
        );

        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertRedirect(route('cabinet.pending'));

        $this->get(route('cabinet.pending'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/PendingActivation')
                ->where('cabinet.access_status', 'expired')
                ->where('cabinet.access_reason', 'license_expired')
                ->where('cabinet.license.plan', 'trial')
                ->where('cabinet.license.status', 'expired'));
    }

    public function test_an_expired_trial_can_be_renewed_without_a_second_activation_or_license(): void
    {
        Mail::fake();
        $startsAt = CarbonImmutable::parse('2026-08-08 10:00:00', config('app.timezone'));
        $this->travelTo($startsAt);

        $owner = User::factory()->create([
            'email' => 'renew-owner@example.com',
            'approved_at' => now(),
        ]);
        $cabinet = Cabinet::query()->create([
            'name' => 'Cabinet Renew',
            'status' => CabinetStatus::PENDING,
            'owner_user_id' => $owner->getKey(),
        ]);
        $owner->forceFill(['cabinet_id' => $cabinet->getKey()])->save();

        $service = app(CabinetFulfillmentService::class);
        $service->activate($cabinet, LicensePlan::TRIAL);
        $cabinet = $cabinet->fresh();
        $licenseId = $cabinet->license_id;
        $activatedAt = $cabinet->activated_at;

        $renewedAt = $startsAt->addDays(8);
        $this->travelTo($renewedAt);
        $this->assertSame(
            CabinetAccessService::REASON_LICENSE_EXPIRED,
            app(CabinetAccessService::class)->denialReason($owner->fresh()),
        );

        $service->renewTrial($cabinet, LicensePlan::TRIAL);
        $cabinet->refresh();
        $license = $cabinet->license;

        $this->assertSame($licenseId, $cabinet->license_id);
        $this->assertTrue($cabinet->activated_at?->equalTo($activatedAt) === true);
        $this->assertDatabaseCount('licenses', 1);
        $this->assertSame(LicensePlan::TRIAL, $license?->plan);
        $this->assertTrue($license?->expires_at?->equalTo($renewedAt->addDays(7)) === true);
        $this->assertTrue(app(CabinetAccessService::class)->isEligible($owner->fresh()));
        $this->assertDatabaseHas('audit_logs', ['action' => 'cabinet.license_renewed']);
        Mail::assertQueued(CabinetLicenseUpdatedMail::class);
    }

    public function test_a_trial_can_be_upgraded_to_lifetime_without_changing_its_license_id(): void
    {
        Mail::fake();

        $owner = User::factory()->create(['email' => 'upgrade-owner@example.com']);
        $cabinet = Cabinet::query()->create([
            'name' => 'Cabinet Upgrade',
            'status' => CabinetStatus::PENDING,
            'owner_user_id' => $owner->getKey(),
        ]);
        $owner->forceFill(['cabinet_id' => $cabinet->getKey()])->save();

        $service = app(CabinetFulfillmentService::class);
        $service->activate($cabinet, LicensePlan::TRIAL);
        $cabinet = $cabinet->fresh();
        $licenseId = $cabinet->license_id;

        $service->renewTrial($cabinet, LicensePlan::LIFETIME);
        $cabinet->refresh();

        $this->assertSame($licenseId, $cabinet->license_id);
        $this->assertDatabaseCount('licenses', 1);
        $this->assertSame(LicensePlan::LIFETIME, $cabinet->license?->plan);
        $this->assertNull($cabinet->license?->expires_at);
    }

    public function test_suspend_and_reactivate_transitions(): void
    {
        $cabinet = Cabinet::query()->create([
            'name' => 'Cabinet',
            'status' => CabinetStatus::ACTIVE,
            'activated_at' => now(),
        ]);

        $service = app(CabinetFulfillmentService::class);

        $service->suspend($cabinet);
        $this->assertSame(CabinetStatus::SUSPENDED, $cabinet->fresh()->status);

        $service->reactivate($cabinet->fresh());
        $this->assertSame(CabinetStatus::ACTIVE, $cabinet->fresh()->status);
    }

    public function test_suspend_and_reactivate_keep_the_linked_plan_and_original_trial_expiry(): void
    {
        $startsAt = CarbonImmutable::parse('2026-08-08 10:00:00', config('app.timezone'));
        $this->travelTo($startsAt);

        $cabinet = Cabinet::query()->create([
            'name' => 'Cabinet Suspended Trial',
            'status' => CabinetStatus::PENDING,
        ]);
        $service = app(CabinetFulfillmentService::class);
        $service->activate($cabinet, LicensePlan::TRIAL);
        $cabinet = $cabinet->fresh();
        $expiresAt = $cabinet->license?->expires_at;

        $this->travelTo($startsAt->addDays(2));
        $service->suspend($cabinet);
        $cabinet->refresh();
        $this->assertSame(CabinetStatus::SUSPENDED, $cabinet->status);
        $this->assertSame('suspended', $cabinet->license?->status);

        $this->travelTo($startsAt->addDays(3));
        $service->reactivate($cabinet);
        $cabinet->refresh();
        $this->assertSame(CabinetStatus::ACTIVE, $cabinet->status);
        $this->assertSame('active', $cabinet->license?->status);
        $this->assertSame(LicensePlan::TRIAL, $cabinet->license?->plan);
        $this->assertTrue($cabinet->license?->expires_at?->equalTo($expiresAt) === true);
    }

    public function test_reactivation_does_not_revive_an_expired_trial_but_renewal_does(): void
    {
        Mail::fake();
        $startsAt = CarbonImmutable::parse('2026-08-08 10:00:00', config('app.timezone'));
        $this->travelTo($startsAt);

        $owner = User::factory()->create([
            'email' => 'expired-suspended@example.com',
            'approved_at' => now(),
        ]);
        $cabinet = Cabinet::query()->create([
            'name' => 'Cabinet Expired Suspended',
            'status' => CabinetStatus::PENDING,
            'owner_user_id' => $owner->getKey(),
        ]);
        $owner->forceFill(['cabinet_id' => $cabinet->getKey()])->save();

        $service = app(CabinetFulfillmentService::class);
        $service->activate($cabinet, LicensePlan::TRIAL);
        $this->travelTo($startsAt->addDays(8));

        $service->suspend($cabinet->fresh());
        $service->reactivate($cabinet->fresh());
        $this->assertSame('expired', $cabinet->fresh()->license?->status);
        $this->assertSame(
            CabinetAccessService::REASON_LICENSE_EXPIRED,
            app(CabinetAccessService::class)->denialReason($owner->fresh()),
        );

        $service->renewTrial($cabinet->fresh(), LicensePlan::TRIAL);
        $this->assertTrue(app(CabinetAccessService::class)->isEligible($owner->fresh()));
    }

    public function test_hosted_entitlements_are_excluded_from_the_legacy_license_resource(): void
    {
        $cabinet = Cabinet::query()->create([
            'name' => 'Cabinet Hosted',
            'status' => CabinetStatus::PENDING,
        ]);
        app(CabinetFulfillmentService::class)->activate($cabinet, LicensePlan::LIFETIME);
        $hostedLicenseId = $cabinet->fresh()->license_id;

        $legacy = License::query()->create([
            'license_id' => 'LEGACY-LOCAL-001',
            'product' => 'Drclick',
            'edition' => 'professional',
            'plan' => null,
            'status' => 'active',
            'issued_at' => now(),
        ]);

        $visibleIds = FilamentLicenseResource::getEloquentQuery()->pluck('id');

        $this->assertTrue($visibleIds->contains($legacy->getKey()));
        $this->assertFalse($visibleIds->contains($hostedLicenseId));
    }

    public function test_platform_admin_issues_codes_and_owner_activates_then_upgrades_from_the_cabinets_table(): void
    {
        Mail::fake();
        $platform = User::factory()->create(['is_platform_admin' => true]);
        $owner = User::factory()->create(['email' => 'filament-plan@example.com']);
        $cabinet = Cabinet::query()->create([
            'name' => 'Cabinet Filament Plan',
            'status' => CabinetStatus::PENDING,
            'owner_user_id' => $owner->getKey(),
        ]);
        $owner->forceFill(['cabinet_id' => $cabinet->getKey()])->save();
        $this->actingAs($platform);

        Livewire::test(ListCabinets::class)
            ->callTableAction('issueLicenseCode', $cabinet, [
                'plan' => LicensePlan::TRIAL->value,
            ])
            ->assertHasNoTableActionErrors();

        $codes = [];
        Mail::assertSent(CabinetLicenseCodeIssuedMail::class, function (CabinetLicenseCodeIssuedMail $mail) use (&$codes): bool {
            $codes[] = $mail->licenseCode;

            return true;
        });

        $cabinet->refresh();
        $this->assertSame(CabinetStatus::PENDING, $cabinet->status);
        $this->assertNull($cabinet->license_id);

        $this->actingAs($owner)
            ->post(route('cabinet.license.redeem'), ['license_code' => $codes[0]])
            ->assertRedirect(route('dashboard'));

        $cabinet->refresh();
        $this->assertSame(LicensePlan::TRIAL, $cabinet->license?->plan);

        $this->actingAs($platform);
        Livewire::test(ListCabinets::class)
            ->callTableAction('issueLicenseCode', $cabinet, [
                'plan' => LicensePlan::LIFETIME->value,
            ])
            ->assertHasNoTableActionErrors();

        $codes = [];
        Mail::assertSent(CabinetLicenseCodeIssuedMail::class, function (CabinetLicenseCodeIssuedMail $mail) use (&$codes): bool {
            $codes[] = $mail->licenseCode;

            return true;
        });

        $this->actingAs($owner)
            ->post(route('cabinet.license.redeem'), ['license_code' => $codes[1]])
            ->assertRedirect(route('dashboard'));

        $cabinet->refresh();
        $this->assertSame(LicensePlan::LIFETIME, $cabinet->license?->plan);
        $this->assertNull($cabinet->license?->expires_at);
    }

    public function test_activation_is_idempotent_and_attributes_the_audit_to_the_platform_actor(): void
    {
        Mail::fake();

        $platform = User::factory()->create(['is_platform_admin' => true]);
        $owner = User::factory()->create(['email' => 'idempotent-owner@example.com']);
        $cabinet = Cabinet::query()->create([
            'name' => 'Cabinet Idempotent',
            'status' => CabinetStatus::PENDING,
            'owner_user_id' => $owner->getKey(),
        ]);
        $owner->forceFill(['cabinet_id' => $cabinet->getKey()])->save();
        $this->actingAs($platform);

        $service = app(CabinetFulfillmentService::class);
        $service->activate($cabinet);
        $service->activate($cabinet);

        $this->assertDatabaseCount('licenses', 1);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'cabinet.activated',
            'user_id' => $platform->getKey(),
            'cabinet_id' => $cabinet->getKey(),
        ]);
        $this->assertDatabaseCount('audit_logs', 1);
        Mail::assertQueued(CabinetActivatedMail::class, 1);
    }

    public function test_suspended_cabinet_cannot_be_activated_as_a_new_fulfillment(): void
    {
        $cabinet = Cabinet::query()->create([
            'name' => 'Cabinet Suspended',
            'status' => CabinetStatus::SUSPENDED,
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Only a pending cabinet may be activated.');

        app(CabinetFulfillmentService::class)->activate($cabinet);
    }

    public function test_only_platform_admins_reach_the_filament_cabinets_page(): void
    {
        $regular = User::factory()->create(['is_platform_admin' => false]);
        $this->actingAs($regular)
            ->get('/admin/cabinets')
            ->assertForbidden();

        $platform = User::factory()->create(['is_platform_admin' => true]);
        $this->actingAs($platform)
            ->get('/admin/cabinets')
            ->assertSuccessful();
    }
}
