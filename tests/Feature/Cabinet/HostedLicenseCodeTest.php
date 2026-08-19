<?php

namespace Tests\Feature\Cabinet;

use App\Enums\CabinetStatus;
use App\Enums\LicensePlan;
use App\Filament\Resources\Cabinets\Pages\ListCabinets;
use App\Mail\CabinetActivatedMail;
use App\Mail\CabinetLicenseCodeIssuedMail;
use App\Models\AuditLog;
use App\Models\Cabinet;
use App\Models\HostedLicenseGrant;
use App\Models\User;
use App\Services\CabinetFulfillmentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;
use Tests\TestCase;

class HostedLicenseCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_owner_can_always_sign_out_to_the_login_page(): void
    {
        [$owner] = $this->pendingCabinet('pending-sign-out@example.com');

        $this->actingAs($owner)
            ->post(route('cabinet.sign-out'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_issuing_a_code_does_not_activate_the_cabinet_and_never_stores_plaintext(): void
    {
        Mail::fake();
        [$owner, $cabinet] = $this->pendingCabinet('secure-code@example.com');
        $platform = User::factory()->create(['is_platform_admin' => true]);

        $this->actingAs($platform);
        $issued = app(CabinetFulfillmentService::class)
            ->issueLicenseCode($cabinet, LicensePlan::LIFETIME);

        $cabinet->refresh();
        $grant = $issued->grant->fresh();

        $this->assertSame(CabinetStatus::PENDING, $cabinet->status);
        $this->assertNull($cabinet->license_id);
        $this->assertDatabaseCount('licenses', 0);
        $this->assertTrue($grant->isOutstanding());
        $this->assertSame(LicensePlan::LIFETIME, $grant->plan);
        $this->assertSame(64, strlen($grant->code_hash));
        $this->assertStringNotContainsString(
            $issued->code,
            json_encode($grant->getAttributes(), JSON_THROW_ON_ERROR),
        );

        $audit = AuditLog::query()->where('action', 'cabinet.license_code_issued')->firstOrFail();
        $this->assertStringNotContainsString(
            $issued->code,
            json_encode($audit->metadata, JSON_THROW_ON_ERROR),
        );

        Mail::assertSent(CabinetLicenseCodeIssuedMail::class, function ($mail) use ($owner, $issued): bool {
            return $mail->hasTo($owner->email) && $mail->licenseCode === $issued->code;
        });
    }

    public function test_owner_redeems_a_trial_code_once_and_the_seven_days_start_at_redemption(): void
    {
        Mail::fake();
        $issuedAt = CarbonImmutable::parse('2026-08-08 09:00:00', config('app.timezone'));
        $redeemedAt = $issuedAt->addDays(2)->addHours(3);
        $this->travelTo($issuedAt);

        [$owner, $cabinet] = $this->pendingCabinet('trial-code@example.com');
        $platform = User::factory()->create(['is_platform_admin' => true]);
        $this->actingAs($platform);
        $issued = app(CabinetFulfillmentService::class)
            ->issueLicenseCode($cabinet, LicensePlan::TRIAL);

        $this->travelTo($redeemedAt);
        $this->actingAs($owner)
            ->post(route('cabinet.license.redeem'), ['license_code' => strtolower($issued->code)])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasNoErrors();

        $cabinet->refresh();
        $this->assertSame(CabinetStatus::ACTIVE, $cabinet->status);
        $this->assertTrue($cabinet->activated_at?->equalTo($redeemedAt) === true);
        $this->assertSame(LicensePlan::TRIAL, $cabinet->license?->plan);
        $this->assertTrue($cabinet->license?->expires_at?->equalTo($redeemedAt->addDays(7)) === true);
        $this->assertDatabaseCount('licenses', 1);
        $this->assertDatabaseHas('hosted_license_grants', [
            'id' => $issued->grant->getKey(),
            'redeemed_by_user_id' => $owner->getKey(),
        ]);
        Mail::assertQueued(CabinetActivatedMail::class);

        $this->actingAs($owner)
            ->from(route('cabinet.pending'))
            ->post(route('cabinet.license.redeem'), ['license_code' => $issued->code])
            ->assertRedirect(route('cabinet.pending'))
            ->assertSessionHasErrors('license_code');

        $this->assertDatabaseCount('licenses', 1);
    }

    public function test_a_code_is_bound_to_its_cabinet_and_returns_a_generic_error_elsewhere(): void
    {
        Mail::fake();
        [, $firstCabinet] = $this->pendingCabinet('first-owner@example.com');
        [$secondOwner, $secondCabinet] = $this->pendingCabinet('second-owner@example.com');
        $platform = User::factory()->create(['is_platform_admin' => true]);

        $this->actingAs($platform);
        $issued = app(CabinetFulfillmentService::class)
            ->issueLicenseCode($firstCabinet, LicensePlan::LIFETIME);

        $response = $this->actingAs($secondOwner)
            ->from(route('cabinet.pending'))
            ->post(route('cabinet.license.redeem'), ['license_code' => $issued->code]);

        $response->assertRedirect(route('cabinet.pending'))
            ->assertSessionHasErrors([
                'license_code' => 'Ce code de licence est invalide ou n’est plus disponible.',
            ]);
        $this->assertSame(CabinetStatus::PENDING, $secondCabinet->fresh()->status);
        $this->assertTrue($issued->grant->fresh()->isOutstanding());
    }

    public function test_a_staff_member_cannot_redeem_the_cabinet_code(): void
    {
        Mail::fake();
        [$owner, $cabinet] = $this->pendingCabinet('staff-guard-owner@example.com');
        $staff = User::factory()->create([
            'cabinet_id' => $cabinet->getKey(),
            'approved_at' => now(),
        ]);
        $platform = User::factory()->create(['is_platform_admin' => true]);

        $this->actingAs($platform);
        $issued = app(CabinetFulfillmentService::class)
            ->issueLicenseCode($cabinet, LicensePlan::LIFETIME);

        $this->actingAs($staff)
            ->post(route('cabinet.license.redeem'), ['license_code' => $issued->code])
            ->assertForbidden();

        $this->assertSame($owner->getKey(), $cabinet->owner_user_id);
        $this->assertSame(CabinetStatus::PENDING, $cabinet->fresh()->status);
        $this->assertTrue($issued->grant->fresh()->isOutstanding());
    }

    public function test_regeneration_revokes_the_previous_code_before_issuing_another(): void
    {
        Mail::fake();
        [$owner, $cabinet] = $this->pendingCabinet('regenerate@example.com');
        $platform = User::factory()->create(['is_platform_admin' => true]);
        $service = app(CabinetFulfillmentService::class);

        $this->actingAs($platform);
        $first = $service->issueLicenseCode($cabinet, LicensePlan::TRIAL);
        $second = $service->issueLicenseCode($cabinet, LicensePlan::LIFETIME);

        $this->assertNotSame($first->code, $second->code);
        $this->assertNotNull($first->grant->fresh()->revoked_at);
        $this->assertTrue($second->grant->fresh()->isOutstanding());
        $this->assertSame(1, HostedLicenseGrant::query()->outstanding()->count());

        $this->actingAs($owner)
            ->from(route('cabinet.pending'))
            ->post(route('cabinet.license.redeem'), ['license_code' => $first->code])
            ->assertSessionHasErrors('license_code');

        $this->post(route('cabinet.license.redeem'), ['license_code' => $second->code])
            ->assertRedirect(route('dashboard'));

        $this->assertSame(LicensePlan::LIFETIME, $cabinet->fresh()->license?->plan);
    }

    public function test_expired_trial_can_be_upgraded_by_code_without_creating_a_second_license(): void
    {
        Mail::fake();
        $activatedAt = CarbonImmutable::parse('2026-08-08 09:00:00', config('app.timezone'));
        $this->travelTo($activatedAt);
        [$owner, $cabinet] = $this->pendingCabinet('code-upgrade@example.com');
        $platform = User::factory()->create(['is_platform_admin' => true]);
        $service = app(CabinetFulfillmentService::class);

        $this->actingAs($platform);
        $service->activate($cabinet, LicensePlan::TRIAL);
        $cabinet->refresh();
        $licenseId = $cabinet->license_id;

        $this->travelTo($activatedAt->addDays(8));
        $issued = $service->issueLicenseCode($cabinet, LicensePlan::LIFETIME);

        $this->actingAs($owner)
            ->post(route('cabinet.license.redeem'), ['license_code' => $issued->code])
            ->assertRedirect(route('dashboard'));

        $cabinet->refresh();
        $this->assertSame($licenseId, $cabinet->license_id);
        $this->assertTrue($cabinet->activated_at?->equalTo($activatedAt) === true);
        $this->assertSame(LicensePlan::LIFETIME, $cabinet->license?->plan);
        $this->assertNull($cabinet->license?->expires_at);
        $this->assertDatabaseCount('licenses', 1);
    }

    public function test_pending_page_exposes_only_safe_grant_metadata_to_the_owner(): void
    {
        Mail::fake();
        [$owner, $cabinet] = $this->pendingCabinet('pending-props@example.com');
        $platform = User::factory()->create(['is_platform_admin' => true]);

        $this->actingAs($owner)
            ->get(route('cabinet.pending'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can_redeem_license', true)
                ->where('pending_license_grant', null));

        $this->actingAs($platform);
        $issued = app(CabinetFulfillmentService::class)
            ->issueLicenseCode($cabinet, LicensePlan::TRIAL);

        $this->actingAs($owner)
            ->get(route('cabinet.pending'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/PendingActivation')
                ->where('can_redeem_license', true)
                ->where('pending_license_grant.plan', 'trial')
                ->where('pending_license_grant.code_suffix', $issued->grant->code_suffix)
                ->missing('pending_license_grant.code')
                ->missing('pending_license_grant.code_hash'));
    }

    public function test_active_trial_owner_can_open_and_redeem_an_upgrade_code_immediately(): void
    {
        Mail::fake();
        [$owner, $cabinet] = $this->pendingCabinet('active-upgrade@example.com');
        $platform = User::factory()->create(['is_platform_admin' => true]);
        $service = app(CabinetFulfillmentService::class);

        $this->actingAs($platform);
        $service->activate($cabinet, LicensePlan::TRIAL);
        $issued = $service->issueLicenseCode($cabinet->fresh(), LicensePlan::LIFETIME);

        $this->actingAs($owner)
            ->get(route('cabinet.pending'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/PendingActivation')
                ->where('cabinet.access_status', 'upgrade')
                ->where('can_redeem_license', true)
                ->where('pending_license_grant.plan', 'lifetime'));

        $this->post(route('cabinet.license.redeem'), [
            'license_code' => $issued->code,
        ])->assertRedirect(route('dashboard'));

        $this->assertSame(LicensePlan::LIFETIME, $cabinet->fresh()->license?->plan);
        $this->assertNull($cabinet->fresh()->license?->expires_at);
    }

    public function test_filament_default_action_issues_a_code_but_keeps_the_cabinet_pending(): void
    {
        Mail::fake();
        [, $cabinet] = $this->pendingCabinet('filament-code@example.com');
        $platform = User::factory()->create(['is_platform_admin' => true]);
        $this->actingAs($platform);

        Livewire::test(ListCabinets::class)
            ->callTableAction('issueLicenseCode', $cabinet, [
                'plan' => LicensePlan::TRIAL->value,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(CabinetStatus::PENDING, $cabinet->fresh()->status);
        $this->assertNull($cabinet->fresh()->license_id);
        $this->assertDatabaseHas('hosted_license_grants', [
            'cabinet_id' => $cabinet->getKey(),
            'plan' => LicensePlan::TRIAL->value,
            'redeemed_at' => null,
            'revoked_at' => null,
        ]);
        Mail::assertSent(CabinetLicenseCodeIssuedMail::class);
    }

    /** @return array{User, Cabinet} */
    private function pendingCabinet(string $email): array
    {
        $owner = User::factory()->create([
            'email' => $email,
            'approved_at' => now(),
        ]);
        $cabinet = Cabinet::query()->create([
            'name' => 'Cabinet '.$owner->getKey(),
            'status' => CabinetStatus::PENDING,
            'owner_user_id' => $owner->getKey(),
        ]);
        $owner->forceFill(['cabinet_id' => $cabinet->getKey()])->save();

        return [$owner, $cabinet];
    }
}
