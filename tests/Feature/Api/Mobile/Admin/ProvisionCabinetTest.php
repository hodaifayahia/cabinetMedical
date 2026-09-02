<?php

namespace Tests\Feature\Api\Mobile\Admin;

use App\Enums\CabinetStatus;
use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\Cabinet;
use App\Models\DoctorSchedule;
use App\Models\User;
use App\Models\Wilaya;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MobileTestHelpers;
use Tests\TestCase;

/**
 * The headline admin feature: a superadmin creates a clinic and its doctor
 * owner from the phone. The result must be indistinguishable from a
 * self-registered cabinet — same role, same profile, same default schedule —
 * and the owner must actually be able to sign in afterwards.
 */
class ProvisionCabinetTest extends TestCase
{
    use MobileTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        Wilaya::factory()->create(['code' => 16, 'name_fr' => 'Alger', 'name_ar' => 'الجزائر']);
    }

    public function test_it_provisions_a_clinic_with_its_doctor_owner(): void
    {
        Sanctum::actingAs($this->makePlatformAdmin(), ['mobile']);

        $response = $this->postJson('/api/v1/admin/cabinets', [
            'cabinet_name' => 'عيادة النور',
            'specialization' => 'Cardiologie',
            'wilaya_code' => 16,
            'doctor_name' => 'Dr Yacine Haddad',
            'email' => 'y.haddad@clinic.dz',
            'phone' => '0550112233',
            'password' => 'mot-de-passe-solide-2026',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'عيادة النور')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.wilaya.code', 16)
            ->assertJsonPath('data.wilaya.name_ar', 'الجزائر')
            ->assertJsonPath('data.is_listed', false)
            ->assertJsonPath('data.owner.email', 'y.haddad@clinic.dz')
            ->assertJsonPath('data.owner.name', 'Dr Yacine Haddad')
            ->assertJsonPath('data.owner.phone', '0550112233')
            ->assertJsonPath('data.doctor.specialty.code', 'cardiology')
            // The admin supplied the password, so nothing is echoed back.
            ->assertJsonPath('temporary_password', null);

        $cabinetId = (int) $response->json('data.id');

        $this->assertDatabaseHas('cabinets', [
            'id' => $cabinetId,
            'status' => CabinetStatus::PENDING->value,
            'wilaya_code' => 16,
            'specialization' => 'Cardiologie',
        ]);

        /** @var User $owner */
        $owner = User::query()->where('email', 'y.haddad@clinic.dz')->firstOrFail();
        $this->assertSame($cabinetId, $owner->cabinet_id);
        $this->assertTrue($owner->hasRole(RoleName::DOCTOR->value));
        $this->assertNotNull($owner->approved_at);
        $this->assertSame($owner->getKey(), Cabinet::query()->findOrFail($cabinetId)->owner_user_id);

        $this->assertDatabaseHas('doctor_profiles', [
            'cabinet_id' => $cabinetId,
            'user_id' => $owner->getKey(),
            'specialty_code' => 'cardiology',
            'phone' => '0550112233',
        ]);
        $this->assertDatabaseHas('cabinet_settings', ['cabinet_id' => $cabinetId]);
        $this->assertSame(5, DoctorSchedule::withoutCabinetScope()
            ->where('cabinet_id', $cabinetId)
            ->count());

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.cabinet_provisioned',
            'subject_id' => (string) $cabinetId,
        ]);
    }

    public function test_the_new_owner_can_sign_in_and_is_seen_as_a_doctor(): void
    {
        Sanctum::actingAs($this->makePlatformAdmin(), ['mobile']);

        $this->postJson('/api/v1/admin/cabinets', [
            'cabinet_name' => 'Cabinet El Wiam',
            'specialization' => 'Pédiatrie',
            'wilaya_code' => 16,
            'doctor_name' => 'Dr Nadia Cherif',
            'email' => 'n.cherif@clinic.dz',
            'phone' => '0661234567',
            'password' => 'mot-de-passe-solide-2026',
            'activate' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.license.status', 'active');

        $this->postJson('/api/v1/auth/login', [
            'identifier' => 'n.cherif@clinic.dz',
            'password' => 'mot-de-passe-solide-2026',
        ])
            ->assertOk()
            ->assertJsonPath('role', 'doctor');
    }

    public function test_a_generated_password_is_returned_once_and_actually_authenticates(): void
    {
        Sanctum::actingAs($this->makePlatformAdmin(), ['mobile']);

        $response = $this->postJson('/api/v1/admin/cabinets', [
            'cabinet_name' => 'Cabinet Ibn Sina',
            'specialization' => 'Dermatologie',
            'wilaya_code' => 16,
            'doctor_name' => 'Dr Amine Belkacem',
            'email' => 'a.belkacem@clinic.dz',
            'phone' => '0770112233',
            'activate' => true,
        ])->assertCreated();

        $temporaryPassword = $response->json('temporary_password');
        $this->assertIsString($temporaryPassword);
        $this->assertGreaterThanOrEqual(12, strlen($temporaryPassword));

        $this->postJson('/api/v1/auth/login', [
            'identifier' => 'a.belkacem@clinic.dz',
            'password' => $temporaryPassword,
        ])
            ->assertOk()
            ->assertJsonPath('role', 'doctor');

        // It is a one-time reveal: no read endpoint ever repeats it.
        $cabinetId = (int) $response->json('data.id');
        $detail = $this->getJson('/api/v1/admin/cabinets/'.$cabinetId)->assertOk();

        $this->assertStringNotContainsString($temporaryPassword, $detail->getContent() ?: '');
        $this->assertArrayNotHasKey('temporary_password', $detail->json());
        $this->assertStringNotContainsString('password', $detail->getContent() ?: '');
    }

    public function test_a_listed_clinic_appears_in_public_discovery(): void
    {
        Sanctum::actingAs($this->makePlatformAdmin(), ['mobile']);

        $response = $this->postJson('/api/v1/admin/cabinets', [
            'cabinet_name' => 'Cabinet Es Salam',
            'specialization' => 'Cardiologie',
            'wilaya_code' => 16,
            'doctor_name' => 'Dr Sofiane Meziane',
            'email' => 's.meziane@clinic.dz',
            'phone' => '0551112233',
            'password' => 'mot-de-passe-solide-2026',
            'activate' => true,
            'is_listed' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.is_listed', true);

        $cabinetId = (int) $response->json('data.id');

        $this->getJson('/api/v1/doctors')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.clinic.id', $cabinetId)
            ->assertJsonPath('data.0.name', 'Dr Sofiane Meziane');
    }

    public function test_a_duplicate_email_is_rejected(): void
    {
        User::factory()->create(['email' => 'taken@clinic.dz']);

        Sanctum::actingAs($this->makePlatformAdmin(), ['mobile']);

        $this->postJson('/api/v1/admin/cabinets', [
            'cabinet_name' => 'Cabinet Doublon',
            'specialization' => 'Cardiologie',
            'wilaya_code' => 16,
            'doctor_name' => 'Dr Doublon',
            'email' => 'taken@clinic.dz',
            'phone' => '0550112233',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('cabinets', 0);
    }

    public function test_a_non_algerian_phone_number_is_rejected(): void
    {
        Sanctum::actingAs($this->makePlatformAdmin(), ['mobile']);

        $this->postJson('/api/v1/admin/cabinets', [
            'cabinet_name' => 'Cabinet Téléphone',
            'specialization' => 'Cardiologie',
            'wilaya_code' => 16,
            'doctor_name' => 'Dr Téléphone',
            'email' => 'phone@clinic.dz',
            'phone' => '+33123456789',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');

        $this->assertDatabaseCount('cabinets', 0);
    }

    public function test_a_short_password_is_rejected(): void
    {
        Sanctum::actingAs($this->makePlatformAdmin(), ['mobile']);

        $this->postJson('/api/v1/admin/cabinets', [
            'cabinet_name' => 'Cabinet Faible',
            'specialization' => 'Cardiologie',
            'wilaya_code' => 16,
            'doctor_name' => 'Dr Faible',
            'email' => 'weak@clinic.dz',
            'phone' => '0550112233',
            'password' => 'court',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->assertDatabaseCount('cabinets', 0);
    }

    public function test_an_unknown_wilaya_is_rejected(): void
    {
        Sanctum::actingAs($this->makePlatformAdmin(), ['mobile']);

        $this->postJson('/api/v1/admin/cabinets', [
            'cabinet_name' => 'Cabinet Inconnu',
            'specialization' => 'Cardiologie',
            'wilaya_code' => 58,
            'doctor_name' => 'Dr Inconnu',
            'email' => 'unknown@clinic.dz',
            'phone' => '0550112233',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('wilaya_code');
    }

    public function test_privilege_escalation_fields_are_prohibited_and_nothing_is_created(): void
    {
        Sanctum::actingAs($this->makePlatformAdmin(), ['mobile']);

        $payload = [
            'cabinet_name' => 'Cabinet Escalade',
            'specialization' => 'Cardiologie',
            'wilaya_code' => 16,
            'doctor_name' => 'Dr Escalade',
            'email' => 'escalate@clinic.dz',
            'phone' => '0550112233',
            'password' => 'mot-de-passe-solide-2026',
        ];

        $this->postJson('/api/v1/admin/cabinets', [...$payload, 'is_platform_admin' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('is_platform_admin');

        // Even the falsey form is refused: the field may not appear at all.
        $this->postJson('/api/v1/admin/cabinets', [...$payload, 'is_platform_admin' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors('is_platform_admin');

        foreach (['role' => 'Doctor', 'roles' => ['Doctor'], 'cabinet_id' => 1, 'approved_at' => '2026-01-01'] as $field => $value) {
            $this->postJson('/api/v1/admin/cabinets', [...$payload, $field => $value])
                ->assertStatus(422)
                ->assertJsonValidationErrors($field);
        }

        $this->assertDatabaseCount('cabinets', 0);
        $this->assertDatabaseMissing('users', ['email' => 'escalate@clinic.dz']);
        $this->assertSame(1, User::query()->where('is_platform_admin', true)->count());
    }

    /**
     * Regression: Laravel's `prohibited` rule passes for null, '' and [], so
     * `{"is_platform_admin": null}` used to be accepted with a 201. The barrier
     * has to fail on the field being present at all, whatever it carries —
     * especially since ConvertEmptyStringsToNull turns "" into null upstream.
     */
    public function test_an_empty_valued_escalation_field_is_still_rejected(): void
    {
        Sanctum::actingAs($this->makePlatformAdmin(), ['mobile']);

        $payload = [
            'cabinet_name' => 'Cabinet Escalade Vide',
            'specialization' => 'Cardiologie',
            'wilaya_code' => 16,
            'doctor_name' => 'Dr Escalade Vide',
            'email' => 'empty-escalate@clinic.dz',
            'phone' => '0550112233',
            'password' => 'MotDePasse-Solide-2026!',
        ];

        foreach ([
            ['is_platform_admin' => null],
            ['is_platform_admin' => ''],
            ['role' => ''],
            ['roles' => []],
            ['cabinet_id' => null],
            ['approved_at' => null],
        ] as $escalation) {
            $this->postJson('/api/v1/admin/cabinets', [...$payload, ...$escalation])
                ->assertStatus(422)
                ->assertJsonValidationErrors(array_key_first($escalation));
        }

        $this->assertDatabaseCount('cabinets', 0);
        $this->assertDatabaseMissing('users', ['email' => 'empty-escalate@clinic.dz']);
        $this->assertSame(1, User::query()->where('is_platform_admin', true)->count());
    }

    /**
     * Regression: `min:12` alone let an admin mint a clinic super-administrator
     * behind an all-lowercase secret that web self-registration would refuse.
     * The optional password now carries the platform's own strength policy.
     */
    public function test_an_admin_supplied_password_must_clear_the_platform_policy(): void
    {
        Password::defaults(static fn (): Password => Password::min(12)
            ->mixedCase()
            ->letters()
            ->numbers()
            ->symbols());

        Sanctum::actingAs($this->makePlatformAdmin(), ['mobile']);

        $this->postJson('/api/v1/admin/cabinets', [
            'cabinet_name' => 'Cabinet Politique',
            'specialization' => 'Cardiologie',
            'wilaya_code' => 16,
            'doctor_name' => 'Dr Politique',
            'email' => 'policy@clinic.dz',
            'phone' => '0550112233',
            'password' => 'aaaaaaaaaaaa',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->assertDatabaseCount('cabinets', 0);
        $this->assertDatabaseMissing('users', ['email' => 'policy@clinic.dz']);
    }

    /**
     * Regression: the metadata key `password_generated` was swallowed by
     * AuditLog's redactor (any key containing "password" becomes the literal
     * string [redacted]), so the audit trail could never say whether the
     * platform had generated the owner's first credential.
     */
    public function test_the_audit_trail_records_where_the_first_password_came_from(): void
    {
        Sanctum::actingAs($this->makePlatformAdmin(), ['mobile']);

        $generated = $this->postJson('/api/v1/admin/cabinets', [
            'cabinet_name' => 'Cabinet Généré',
            'specialization' => 'Cardiologie',
            'wilaya_code' => 16,
            'doctor_name' => 'Dr Généré',
            'email' => 'generated@clinic.dz',
            'phone' => '0550112233',
        ])->assertCreated();

        $supplied = $this->postJson('/api/v1/admin/cabinets', [
            'cabinet_name' => 'Cabinet Fourni',
            'specialization' => 'Cardiologie',
            'wilaya_code' => 16,
            'doctor_name' => 'Dr Fourni',
            'email' => 'supplied@clinic.dz',
            'phone' => '0660112233',
            'password' => 'MotDePasse-Solide-2026!',
        ])->assertCreated();

        foreach ([
            (int) $generated->json('data.id') => 'generated',
            (int) $supplied->json('data.id') => 'supplied',
        ] as $cabinetId => $expected) {
            /** @var AuditLog $entry */
            $entry = AuditLog::query()
                ->where('action', 'admin.cabinet_provisioned')
                ->where('subject_id', (string) $cabinetId)
                ->firstOrFail();

            $this->assertSame($expected, $entry->metadata['credential_source'] ?? null);
        }
    }
}
