<?php

namespace Tests\Feature\Cabinet;

use App\Enums\CabinetStatus;
use App\Enums\LicensePlan;
use App\Enums\RoleName;
use App\Models\Cabinet;
use App\Models\License;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Sign-in is not working" is usually a successful sign-in followed by a gate.
 * This command exists to say which gate, so the answer does not require a
 * database console.
 */
class DiagnoseCabinetAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function cabinet(CabinetStatus $status = CabinetStatus::ACTIVE): Cabinet
    {
        return Cabinet::query()->create([
            'name' => 'Cabinet du Dr Houdaifa',
            'status' => $status,
            'activated_at' => $status === CabinetStatus::ACTIVE ? now() : null,
        ]);
    }

    private function member(Cabinet $cabinet, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'cabinet_id' => $cabinet->getKey(),
            'approved_at' => now(),
        ], $attributes));
        $user->assignRole(RoleName::DOCTOR->value);
        $cabinet->forceFill(['owner_user_id' => $user->getKey()])->save();

        return $user->refresh();
    }

    private function expiredTrial(Cabinet $cabinet): void
    {
        $license = License::query()->create([
            'license_id' => 'CAB-'.$cabinet->getKey().'-EXPIRED',
            'product' => (string) config('medismart.licensing.product'),
            'edition' => 'hosted',
            'plan' => LicensePlan::TRIAL,
            'customer_id' => (string) $cabinet->getKey(),
            'status' => 'active',
            'issued_at' => CarbonImmutable::now()->subDays(30),
            'expires_at' => CarbonImmutable::now()->subDays(15),
            'last_verified_at' => CarbonImmutable::now()->subDays(30),
        ]);
        $cabinet->forceFill(['license_id' => $license->getKey()])->save();
    }

    public function test_a_healthy_account_is_reported_as_able_to_enter(): void
    {
        $cabinet = $this->cabinet();
        $user = $this->member($cabinet);

        $this->artisan('cabinet:diagnose', ['email' => $user->email])
            ->expectsOutputToContain('CAN ENTER')
            ->assertExitCode(0);
    }

    public function test_an_expired_trial_is_named_as_the_reason_rather_than_bad_credentials(): void
    {
        // The real report that prompted this command: everyone locked out at
        // once, days after the trial quietly ran out.
        $cabinet = $this->cabinet();
        $user = $this->member($cabinet);
        $this->expiredTrial($cabinet);

        $this->artisan('cabinet:diagnose', ['email' => $user->email])
            ->expectsOutputToContain('license_expired')
            ->assertExitCode(0);
    }

    public function test_a_pending_cabinet_is_named(): void
    {
        $cabinet = $this->cabinet(CabinetStatus::PENDING);
        $user = $this->member($cabinet);

        $this->artisan('cabinet:diagnose', ['email' => $user->email])
            ->expectsOutputToContain('cabinet_pending')
            ->assertExitCode(0);
    }

    public function test_an_unverified_address_is_reported_even_when_the_cabinet_is_fine(): void
    {
        // This one hides behind a healthy cabinet: the licence is valid, the
        // member is approved, and the `verified` middleware still refuses.
        $cabinet = $this->cabinet();
        $user = $this->member($cabinet, ['email_verified_at' => null]);

        $this->artisan('cabinet:diagnose', ['email' => $user->email])
            ->expectsOutputToContain('email_not_verified')
            ->assertExitCode(0);
    }

    public function test_a_member_with_no_role_is_reported(): void
    {
        $cabinet = $this->cabinet();
        $owner = $this->member($cabinet);
        $roleless = User::factory()->create([
            'cabinet_id' => $cabinet->getKey(),
            'approved_at' => now(),
        ]);

        $this->artisan('cabinet:diagnose', ['email' => $roleless->email])
            ->expectsOutputToContain('no_role_assigned')
            ->assertExitCode(0);

        $this->assertNotSame($owner->getKey(), $roleless->getKey());
    }

    public function test_a_member_awaiting_approval_is_reported(): void
    {
        $cabinet = $this->cabinet();
        $this->member($cabinet);
        $pending = User::factory()->create([
            'cabinet_id' => $cabinet->getKey(),
            'approved_at' => null,
        ]);

        $this->artisan('cabinet:diagnose', ['email' => $pending->email])
            ->expectsOutputToContain('awaiting_approval')
            ->assertExitCode(0);
    }

    public function test_an_address_with_no_account_says_registration_never_completed(): void
    {
        // The original bug in this project: a registration that rolled back
        // left the owner certain they had an account and sure the password was
        // right. Saying "no account exists" is the whole answer.
        $this->artisan('cabinet:diagnose', ['email' => 'ghost@example.com'])
            ->expectsOutputToContain('No account exists')
            ->assertExitCode(1);
    }

    public function test_the_lookup_is_case_insensitive(): void
    {
        $cabinet = $this->cabinet();
        $this->member($cabinet, ['email' => 'owner@example.com']);

        $this->artisan('cabinet:diagnose', ['email' => '  Owner@Example.COM  '])
            ->expectsOutputToContain('CAN ENTER')
            ->assertExitCode(0);
    }

    public function test_the_command_changes_nothing(): void
    {
        $cabinet = $this->cabinet(CabinetStatus::PENDING);
        $user = $this->member($cabinet, ['email_verified_at' => null]);

        $this->artisan('cabinet:diagnose')->assertExitCode(0);

        // Safe to run on a production server mid-incident.
        $this->assertNull($user->refresh()->email_verified_at);
        $this->assertTrue($cabinet->refresh()->isPending());
    }
}
