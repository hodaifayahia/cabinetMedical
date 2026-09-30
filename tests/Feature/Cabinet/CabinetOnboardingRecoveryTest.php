<?php

namespace Tests\Feature\Cabinet;

use App\Enums\CabinetStatus;
use App\Enums\RoleName;
use App\Models\Cabinet;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use PDOException;
use ReflectionProperty;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Covers the "I created my cabinet and then it told me my credentials were
 * wrong" report: the ways the owner's account could disappear between the
 * registration form and the sign-in form, plus the reception desk's route to
 * an account of its own on a second LAN machine.
 */
class CabinetOnboardingRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER_PASSWORD = 'Cabinet-2026!secure';

    private const RECEPTION_PASSWORD = 'Reception-2026!secure';

    /**
     * @return array<string, mixed>
     */
    private function registrationPayload(): array
    {
        return [
            'name' => 'Dr Houdaifa',
            'cabinet_name' => 'Cabinet Houdaifa',
            'specialization' => 'Pédiatrie',
            'phone' => '+213 555 12 34 56',
            'email' => 'owner@example.com',
            'wilaya' => 16,
            'password' => self::OWNER_PASSWORD,
            'password_confirmation' => self::OWNER_PASSWORD,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function joinPayload(string $ownerEmail = 'owner@example.com'): array
    {
        return [
            'owner_email' => $ownerEmail,
            'name' => 'Réception',
            'email' => 'reception@example.com',
            'password' => self::RECEPTION_PASSWORD,
            'password_confirmation' => self::RECEPTION_PASSWORD,
        ];
    }

    private function registerOwnerAndSignOut(): void
    {
        $this->post(route('register.store'), $this->registrationPayload())
            ->assertSessionHasNoErrors();
        $this->post(route('cabinet.sign-out'));
    }

    public function test_registering_then_signing_in_works_without_any_seeded_roles(): void
    {
        // A server whose roles rows are absent, as they are when the
        // consolidation migration has not run or the table was truncated. The
        // owner role used to be resolved by name, which raises
        // RoleDoesNotExist inside the registration transaction and rolled the
        // cabinet and its owner straight back out again — leaving the owner
        // convinced the cabinet existed and unable to sign in to it.
        DB::table('roles')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertSame(0, DB::table('roles')->count());

        $this->post(route('register.store'), $this->registrationPayload())
            ->assertSessionHasNoErrors();

        $owner = User::query()->where('email', 'owner@example.com')->first();
        $this->assertNotNull($owner, 'registration rolled the owner back');
        $this->assertTrue($owner->hasRole(RoleName::ADMINISTRATOR->value));
        $this->assertTrue($owner->cabinet->isPending());

        $this->post(route('cabinet.sign-out'))->assertRedirect(route('login'));
        $this->assertGuest();

        $this->post('/login', [
            'email' => 'owner@example.com',
            'password' => self::OWNER_PASSWORD,
        ])->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($owner);
    }

    public function test_production_password_rules_are_strong_but_need_no_network(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $password = Password::defaults();

        $this->assertFalse(
            (new ReflectionProperty($password, 'uncompromised'))->getValue($password),
            'the breach-check rule reaches api.pwnedpasswords.com and cannot run offline',
        );
        $this->assertSame(12, (new ReflectionProperty($password, 'min'))->getValue($password));
        $this->assertTrue((new ReflectionProperty($password, 'mixedCase'))->getValue($password));
        $this->assertTrue((new ReflectionProperty($password, 'symbols'))->getValue($password));
    }

    public function test_the_production_password_rule_validates_with_no_outbound_request(): void
    {
        // A cabinet with no Internet access must still be able to register. The
        // breach check used to fire on every attempt with a 30 second timeout,
        // long enough to exceed a shared host's max_execution_time and abort
        // the registration transaction part-way through. preventStrayRequests
        // turns any such call into a failure instead of a silent hang.
        $this->app->detectEnvironment(fn (): string => 'production');
        Http::preventStrayRequests();

        $validator = Validator::make(
            ['password' => self::OWNER_PASSWORD],
            ['password' => ['required', 'string', Password::defaults()]],
        );

        $this->assertFalse($validator->fails(), 'the production password rule rejected a strong password');
    }

    public function test_a_registration_that_cannot_complete_says_so_instead_of_looking_successful(): void
    {
        Exceptions::fake();

        // Break provisioning part-way through, after the cabinet and owner rows
        // have been written but before the transaction commits: every statement
        // on doctor_schedules fails as if the table were missing. Dropping the
        // table is not portable, because MariaDB commits DDL implicitly, which
        // ends RefreshDatabase's transaction and keeps the rows it should undo.
        DB::connection()->beforeExecuting(function (string $query, array $bindings, Connection $connection): void {
            if (str_contains($query, 'doctor_schedules')) {
                throw new QueryException(
                    $connection->getName(),
                    $query,
                    $bindings,
                    new PDOException('Forced provisioning failure.'),
                );
            }
        });

        $this->from(route('register'))
            ->post(route('register.store'), $this->registrationPayload())
            ->assertRedirect(route('register'))
            ->assertSessionHasErrors('cabinet_name');

        // The rollback is real, so the screen must not imply otherwise.
        $this->assertNull(User::query()->where('email', 'owner@example.com')->first());
        $this->assertSame(0, Cabinet::query()->count());
        $this->assertGuest();

        // The underlying fault still reaches the logs rather than being hidden.
        Exceptions::assertReported(QueryException::class);
    }

    public function test_cabinet_registration_is_rate_limited(): void
    {
        // The `registration` limiter allows 5 attempts per 10 minutes per
        // address and IP. It was defined but never reached the route, so
        // registration accepted unlimited attempts in production.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('register.store'), $this->registrationPayload())
                ->assertStatus(302);
        }

        $this->post(route('register.store'), $this->registrationPayload())
            ->assertTooManyRequests();
    }

    public function test_reception_can_join_a_cabinet_that_is_still_awaiting_activation(): void
    {
        // The doctor registers and the cabinet stays pending until the licence
        // arrives. The reception desk on the second LAN machine has to be able
        // to request its own account in the meantime.
        $this->registerOwnerAndSignOut();

        $cabinet = Cabinet::query()->firstOrFail();
        $this->assertTrue($cabinet->isPending());

        $this->post(route('cabinet.join.store'), $this->joinPayload())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login'));

        $reception = User::query()->where('email', 'reception@example.com')->first();
        $this->assertNotNull($reception, 'reception could not join a cabinet awaiting activation');
        $this->assertSame($cabinet->getKey(), $reception->cabinet_id);
        $this->assertNull($reception->approved_at, 'reception must still await the owner approval');
    }

    public function test_joining_a_pending_cabinet_grants_no_access_before_activation(): void
    {
        $this->registerOwnerAndSignOut();
        $this->post(route('cabinet.join.store'), $this->joinPayload());

        $reception = User::query()->where('email', 'reception@example.com')->firstOrFail();

        $this->actingAs($reception)
            ->get('/dashboard')
            ->assertRedirect(route('cabinet.pending'));
    }

    public function test_a_suspended_cabinet_still_refuses_new_members(): void
    {
        $this->registerOwnerAndSignOut();

        Cabinet::query()->firstOrFail()
            ->forceFill(['status' => CabinetStatus::SUSPENDED])
            ->save();

        $this->post(route('cabinet.join.store'), $this->joinPayload())
            ->assertSessionHasErrors('owner_email');

        $this->assertNull(User::query()->where('email', 'reception@example.com')->first());
    }

    public function test_joining_matches_the_owner_address_case_insensitively(): void
    {
        $this->registerOwnerAndSignOut();

        // The owner writes their address on a note and reception types it with
        // the capital their keyboard offered. PostgreSQL and SQLite compare
        // strings with "=" case-sensitively, so this found no cabinet at all.
        $this->post(route('cabinet.join.store'), [
            ...$this->joinPayload('  Owner@Example.COM  '),
            'email' => 'Reception@Example.com',
        ])->assertSessionHasNoErrors();

        $this->assertNotNull(
            User::query()->where('email', 'reception@example.com')->first(),
            'a capitalised owner address found no cabinet',
        );
    }
}
