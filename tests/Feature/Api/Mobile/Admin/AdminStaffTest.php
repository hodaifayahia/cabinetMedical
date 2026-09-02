<?php

namespace Tests\Feature\Api\Mobile\Admin;

use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\Cabinet;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MobileTestHelpers;
use Tests\TestCase;

/**
 * Adding a reception account to a clinic from the admin app. The account is
 * always an Assistant, always approved, and always counts against the clinic's
 * fixed seat budget.
 */
class AdminStaffTest extends TestCase
{
    use MobileTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_a_reception_account_is_created_and_can_sign_in(): void
    {
        $clinic = $this->makeListedClinic();
        $cabinetId = (int) $clinic['cabinet']->getKey();

        Sanctum::actingAs($this->makePlatformAdmin(), ['mobile']);

        $response = $this->postJson('/api/v1/admin/cabinets/'.$cabinetId.'/staff', [
            'name' => 'Nadia Cherif',
            'email' => 'nadia@clinic.dz',
            'phone' => '0551234567',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Nadia Cherif')
            ->assertJsonPath('data.email', 'nadia@clinic.dz')
            ->assertJsonPath('data.role', 'reception')
            ->assertJsonPath('data.cabinet_id', $cabinetId)
            ->assertJsonPath('data.approved', true);

        /** @var User $reception */
        $reception = User::query()->where('email', 'nadia@clinic.dz')->firstOrFail();
        $this->assertTrue($reception->hasRole(RoleName::ASSISTANT->value));
        $this->assertFalse($reception->is_platform_admin);
        $this->assertNotNull($reception->approved_at);

        $temporaryPassword = $response->json('temporary_password');
        $this->assertIsString($temporaryPassword);

        $this->postJson('/api/v1/auth/login', [
            'identifier' => 'nadia@clinic.dz',
            'password' => $temporaryPassword,
        ])
            ->assertOk()
            ->assertJsonPath('role', 'reception');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.staff_provisioned',
            'subject_id' => (string) $reception->getKey(),
        ]);
    }

    public function test_an_admin_supplied_password_is_never_echoed_back(): void
    {
        $clinic = $this->makeListedClinic();

        Sanctum::actingAs($this->makePlatformAdmin(), ['mobile']);

        $this->postJson('/api/v1/admin/cabinets/'.$clinic['cabinet']->getKey().'/staff', [
            'name' => 'Karim Bensalah',
            'email' => 'karim@clinic.dz',
            'password' => 'mot-de-passe-solide-2026',
        ])
            ->assertCreated()
            ->assertJsonPath('temporary_password', null);

        $this->postJson('/api/v1/auth/login', [
            'identifier' => 'karim@clinic.dz',
            'password' => 'mot-de-passe-solide-2026',
        ])
            ->assertOk()
            ->assertJsonPath('role', 'reception');
    }

    public function test_the_seat_limit_is_enforced(): void
    {
        $clinic = $this->makeListedClinic();
        $cabinetId = (int) $clinic['cabinet']->getKey();

        Sanctum::actingAs($this->makePlatformAdmin(), ['mobile']);

        // The clinic already holds its owner, so MAX_SEATS - 1 seats are free.
        for ($seat = 1; $seat < Cabinet::MAX_SEATS; $seat++) {
            $this->postJson('/api/v1/admin/cabinets/'.$cabinetId.'/staff', [
                'name' => 'Réception '.$seat,
                'email' => "reception{$seat}@clinic.dz",
            ])->assertCreated();
        }

        $this->postJson('/api/v1/admin/cabinets/'.$cabinetId.'/staff', [
            'name' => 'Réception de trop',
            'email' => 'overflow@clinic.dz',
        ])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'seat_limit_reached');

        $this->assertDatabaseMissing('users', ['email' => 'overflow@clinic.dz']);
        $this->assertSame(
            Cabinet::MAX_SEATS,
            User::query()->where('cabinet_id', $cabinetId)->count(),
        );
    }

    public function test_a_duplicate_email_is_rejected(): void
    {
        $clinic = $this->makeListedClinic();

        Sanctum::actingAs($this->makePlatformAdmin(), ['mobile']);

        $this->postJson('/api/v1/admin/cabinets/'.$clinic['cabinet']->getKey().'/staff', [
            'name' => 'Doublon',
            'email' => $clinic['doctorUser']->email,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_privilege_escalation_fields_are_prohibited(): void
    {
        $clinic = $this->makeListedClinic();

        Sanctum::actingAs($this->makePlatformAdmin(), ['mobile']);

        foreach ([
            'is_platform_admin' => true,
            'role' => RoleName::DOCTOR->value,
            'roles' => [RoleName::DOCTOR->value],
            'cabinet_id' => 1,
            'approved_at' => '2026-01-01',
        ] as $field => $value) {
            $this->postJson('/api/v1/admin/cabinets/'.$clinic['cabinet']->getKey().'/staff', [
                'name' => 'Escalade',
                'email' => 'escalate@clinic.dz',
                $field => $value,
            ])
                ->assertStatus(422)
                ->assertJsonValidationErrors($field);
        }

        $this->assertDatabaseMissing('users', ['email' => 'escalate@clinic.dz']);
        $this->assertSame(1, User::query()->where('is_platform_admin', true)->count());
    }

    /**
     * Regression: `prohibited` means "absent OR EMPTY" in Laravel, so an empty
     * form of an escalation field used to sail through with a 201. The barrier
     * is `missing`, which fails on presence whatever the value is — including
     * the empty string, which ConvertEmptyStringsToNull rewrites to null before
     * validation ever sees it.
     */
    public function test_an_empty_valued_escalation_field_is_still_rejected(): void
    {
        $clinic = $this->makeListedClinic();

        Sanctum::actingAs($this->makePlatformAdmin(), ['mobile']);

        foreach ([
            ['is_platform_admin' => null],
            ['is_platform_admin' => ''],
            ['role' => ''],
            ['roles' => []],
            ['cabinet_id' => null],
            ['approved_at' => null],
        ] as $escalation) {
            $field = array_key_first($escalation);

            $this->postJson('/api/v1/admin/cabinets/'.$clinic['cabinet']->getKey().'/staff', [
                'name' => 'Escalade vide',
                'email' => 'empty-escalate@clinic.dz',
                ...$escalation,
            ])
                ->assertStatus(422)
                ->assertJsonValidationErrors($field);
        }

        $this->assertDatabaseMissing('users', ['email' => 'empty-escalate@clinic.dz']);
        $this->assertSame(1, User::query()->where('is_platform_admin', true)->count());
    }

    /**
     * Regression: users.phone is UNIQUE and mobile patients register with it,
     * so a receptionist who already holds a patient account on the same number
     * used to blow up as a 500 on the insert instead of a field-level 422.
     */
    public function test_a_phone_already_held_by_another_account_is_rejected(): void
    {
        $clinic = $this->makeListedClinic();
        $patient = $this->makePatientUser();

        Sanctum::actingAs($this->makePlatformAdmin(), ['mobile']);

        $this->postJson('/api/v1/admin/cabinets/'.$clinic['cabinet']->getKey().'/staff', [
            'name' => 'Réception Doublon',
            'email' => 'duplicate-phone@clinic.dz',
            'phone' => $patient->phone,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');

        $this->assertDatabaseMissing('users', ['email' => 'duplicate-phone@clinic.dz']);
    }

    /**
     * Regression: the audit metadata key used to be `password_generated`, which
     * AuditLog::redactSensitiveMetadata() matched on the substring "password"
     * and overwrote with the literal string [redacted] — destroying the very
     * signal an operator reads to know whether a one-time password was shown.
     */
    public function test_the_audit_trail_records_where_the_first_password_came_from(): void
    {
        $clinic = $this->makeListedClinic();
        $cabinetId = (int) $clinic['cabinet']->getKey();

        Sanctum::actingAs($this->makePlatformAdmin(), ['mobile']);

        $this->postJson('/api/v1/admin/cabinets/'.$cabinetId.'/staff', [
            'name' => 'Réception Générée',
            'email' => 'generated@clinic.dz',
        ])->assertCreated();

        $this->postJson('/api/v1/admin/cabinets/'.$cabinetId.'/staff', [
            'name' => 'Réception Fournie',
            'email' => 'supplied@clinic.dz',
            'password' => 'MotDePasse-Solide-2026!',
        ])->assertCreated();

        foreach (['generated@clinic.dz' => 'generated', 'supplied@clinic.dz' => 'supplied'] as $email => $expected) {
            /** @var User $member */
            $member = User::query()->where('email', $email)->firstOrFail();

            /** @var AuditLog $entry */
            $entry = AuditLog::query()
                ->where('action', 'admin.staff_provisioned')
                ->where('subject_id', (string) $member->getKey())
                ->firstOrFail();

            $this->assertSame($expected, $entry->metadata['credential_source'] ?? null);
        }
    }

    /**
     * Regression: an admin-supplied password reached the database through
     * `min:12` alone, so the platform's most privileged clinic accounts could
     * be weaker than what web self-registration accepts in production.
     */
    public function test_an_admin_supplied_password_must_clear_the_platform_policy(): void
    {
        Password::defaults(static fn (): Password => Password::min(12)
            ->mixedCase()
            ->letters()
            ->numbers()
            ->symbols());

        $clinic = $this->makeListedClinic();

        Sanctum::actingAs($this->makePlatformAdmin(), ['mobile']);

        $this->postJson('/api/v1/admin/cabinets/'.$clinic['cabinet']->getKey().'/staff', [
            'name' => 'Réception Faible',
            'email' => 'weak-policy@clinic.dz',
            'password' => 'aaaaaaaaaaaa',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'weak-policy@clinic.dz']);
    }
}
