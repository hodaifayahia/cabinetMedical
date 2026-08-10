<?php

namespace Tests\Feature\Configuration;

use App\Enums\CabinetStatus;
use App\Enums\LicensePlan;
use App\Enums\RoleName;
use App\Models\Cabinet;
use App\Models\CabinetSetting;
use App\Models\DoctorProfile;
use App\Models\User;
use App\Services\CabinetFulfillmentService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ActivatesSignedLicense;
use Tests\TestCase;

class ClinicIdentityControllerTest extends TestCase
{
    use ActivatesSignedLicense;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function tearDown(): void
    {
        $this->cleanUpSignedLicenseFeatures();

        parent::tearDown();
    }

    public function test_administrator_can_view_clinic_document_settings(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RoleName::ADMINISTRATOR->value);
        DoctorProfile::factory()->for($user)->create([
            'specialty' => 'General Medicine',
        ]);

        $this->actingAs($user)
            ->get(route('app.configuration.identity.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('configuration/ClinicIdentity')
                ->where('identity.doctor_name', $user->name)
                ->where('identity.specialty', 'Médecine générale')
                ->where('identity.logo_url', '/brand/drclick-mark.png')
                ->where('identity.has_custom_logo', false)
                ->where('customBrandingCapability.available', true)
                ->where('customBrandingCapability.reason', null)
                ->where('permissions.can_correct_specialty', false)
                ->where('permissions.sensitive_actions_confirmed', false),
            );
    }

    public function test_owner_can_correct_a_locked_specialty_after_password_confirmation(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole(RoleName::SUPER_ADMINISTRATOR->value);
        $doctor = DoctorProfile::factory()->for($owner)->create([
            'specialty' => 'General Medicine',
            'specialty_code' => 'general_medicine',
        ]);

        $route = route('app.configuration.identity.specialty.correct');

        $this->actingAs($owner)
            ->patch($route, [
                'specialty' => 'Pédiatrie',
                'confirmation' => true,
            ])
            ->assertRedirect(route('password.confirm'));

        $this->actingAs($owner)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->patch($route, [
                'specialty' => 'Pédiatrie',
                'confirmation' => true,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $doctor->refresh();
        $this->assertSame('Pédiatrie', $doctor->specialty);
        $this->assertSame('pediatrics', $doctor->specialty_code);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'doctor.specialty_corrected',
            'subject_id' => (string) $doctor->getKey(),
            'user_id' => $owner->getKey(),
        ]);
    }

    public function test_specialty_correction_requires_owner_role_and_explicit_confirmation(): void
    {
        $administrator = User::factory()->create();
        $administrator->assignRole(RoleName::ADMINISTRATOR->value);
        DoctorProfile::factory()->for($administrator)->create();

        $this->actingAs($administrator)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->patch(route('app.configuration.identity.specialty.correct'), [
                'specialty' => 'Cardiologie',
                'confirmation' => true,
            ])
            ->assertForbidden();

        $owner = User::factory()->create();
        $owner->assignRole(RoleName::SUPER_ADMINISTRATOR->value);
        $this->flushSession();

        $this->actingAs($owner)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->patch(route('app.configuration.identity.specialty.correct'), [
                'specialty' => 'Cardiologie',
            ])
            ->assertSessionHasErrors('confirmation');
    }

    public function test_administrator_can_update_identity_and_logo_as_a_common_feature(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $user->assignRole(RoleName::ADMINISTRATOR->value);
        $doctor = DoctorProfile::factory()->for($user)->create();

        $this->actingAs($user)
            ->post(route('app.configuration.identity.update'), [
                'doctor_name' => 'Dr Test Doctor',
                'professional_identifier' => 'ORD-100',
                'clinic_name' => 'Test Medical Clinic',
                'phone' => '0555000000',
                'email' => 'clinic@example.test',
                'city' => 'Ghardaia',
                'address' => 'Centre Ville',
                'footer_line' => 'First floor',
                'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $cabinet = CabinetSetting::current()->refresh();

        $this->assertSame('Test Medical Clinic', $cabinet->name);
        $this->assertSame('Ghardaia', $cabinet->city);
        $this->assertSame('First floor', $cabinet->prescription_footer);
        $this->assertSame('Dr Test Doctor', $user->refresh()->name);
        $this->assertSame('ORD-100', $doctor->refresh()->professional_identifier);
        $this->assertNotNull($cabinet->logo_path);
        Storage::disk('public')->assertExists($cabinet->logo_path);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'settings.clinic_identity_updated',
            'subject_id' => (string) $cabinet->getKey(),
            'user_id' => $user->getKey(),
        ]);
    }

    public function test_hosted_cabinet_uses_its_plan_for_custom_branding(): void
    {
        Storage::fake('public');
        Mail::fake();

        $cabinet = Cabinet::query()->create([
            'name' => 'Cabinet Hosted',
            'status' => CabinetStatus::PENDING,
        ]);
        $settings = CabinetSetting::current($cabinet);
        $owner = User::factory()->create([
            'cabinet_id' => $cabinet->getKey(),
            'cabinet_setting_id' => $settings->getKey(),
            'approved_at' => now(),
        ]);
        $owner->assignRole(RoleName::ADMINISTRATOR->value);
        $cabinet->forceFill(['owner_user_id' => $owner->getKey()])->save();
        app(CabinetFulfillmentService::class)->activate($cabinet, LicensePlan::TRIAL);

        $this->actingAs($owner);
        DoctorProfile::factory()->for($owner)->create();

        $this->get(route('app.configuration.identity.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('customBrandingCapability.available', true)
                ->where('customBrandingCapability.reason', null));

        $this->post(route('app.configuration.identity.update'), [
            'clinic_name' => 'Cabinet Hosted personnalis\u00e9',
            'footer_line' => 'Soins et confiance',
            'logo' => UploadedFile::fake()->image('hosted-logo.png', 200, 200),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $settings->refresh();
        $this->assertSame('Cabinet Hosted personnalis\u00e9', $settings->name);
        $this->assertSame('Soins et confiance', $settings->prescription_footer);
        $this->assertNotNull($settings->logo_path);
        Storage::disk('public')->assertExists($settings->logo_path);
    }

    public function test_identity_update_rejects_a_malformed_phone_number(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RoleName::ADMINISTRATOR->value);
        DoctorProfile::factory()->for($user)->create();

        $this->actingAs($user)
            ->post(route('app.configuration.identity.update'), [
                'doctor_name' => 'Dr Test Doctor',
                'professional_identifier' => 'ORD-100',
                'clinic_name' => 'Test Medical Clinic',
                'phone' => 'javascript:alert(1)',
                'email' => 'clinic@example.test',
                'city' => 'Ghardaïa',
                'address' => 'Centre Ville',
                'footer_line' => 'Premier étage',
            ])
            ->assertSessionHasErrors('phone');

        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'settings.clinic_identity_updated',
            'user_id' => $user->getKey(),
        ]);
    }

    public function test_custom_branding_is_available_without_a_license_entitlement(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('cabinet/existing-logo.png', 'existing-logo');

        $user = User::factory()->create();
        $user->assignRole(RoleName::ADMINISTRATOR->value);
        DoctorProfile::factory()->for($user)->create([
            'logo_path' => 'cabinet/existing-logo.png',
            'footer_extra_line' => 'Existing footer',
        ]);
        $cabinet = CabinetSetting::current();
        $cabinet->update([
            'name' => 'Existing Clinic',
            'logo_path' => 'cabinet/existing-logo.png',
            'prescription_footer' => 'Existing footer',
        ]);

        $this->actingAs($user)
            ->get(route('app.configuration.identity.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('identity.footer_line', 'Existing footer')
                ->where('identity.logo_url', fn ($value): bool => is_string($value) && str_contains($value, 'existing-logo.png'))
                ->where('identity.has_custom_logo', true)
                ->where('customBrandingCapability.available', true)
                ->where('customBrandingCapability.reason', null),
            );

        $this->actingAs($user)
            ->post(route('app.configuration.identity.update'), [
                'clinic_name' => 'Updated Clinic',
                'footer_line' => 'Updated footer',
                'logo' => UploadedFile::fake()->image('updated-logo.png', 200, 200),
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $cabinet->refresh();
        $this->assertSame('Updated Clinic', $cabinet->name);
        $this->assertSame('Updated footer', $cabinet->prescription_footer);
        $this->assertNotNull($cabinet->logo_path);
        $this->assertNotSame('cabinet/existing-logo.png', $cabinet->logo_path);
        $updatedLogoPath = (string) $cabinet->logo_path;
        Storage::disk('public')->assertExists($updatedLogoPath);
        Storage::disk('public')->assertMissing('cabinet/existing-logo.png');

        $this->actingAs($user)
            ->delete(route('app.configuration.identity.logo.destroy'))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertNull($cabinet->refresh()->logo_path);
        $this->assertNull(DoctorProfile::query()->active()->first()?->logo_path);
        Storage::disk('public')->assertMissing($updatedLogoPath);
    }
}
