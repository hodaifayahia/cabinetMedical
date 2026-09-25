<?php

namespace Tests\Feature\Api\Mobile\Admin;

use App\Filament\Resources\MedicalSpecialties\Pages\CreateMedicalSpecialty;
use App\Filament\Resources\MedicalSpecialties\Pages\ListMedicalSpecialties;
use App\Models\DoctorProfile;
use App\Models\MedicalSpecialty;
use App\Support\MedicalSpecialtyCatalog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\MobileTestHelpers;
use Tests\TestCase;

/**
 * The specialty catalogue is admin-managed: the patient app's specialty filter
 * offers only the specialties an admin has switched on, admins can add new
 * ones, and switching one off never hides a doctor.
 */
class AdminSpecialtyTest extends TestCase
{
    use MobileTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    #[Test]
    public function the_built_in_catalogue_is_seeded_active_so_nothing_changes_for_patients(): void
    {
        $codes = collect($this->getJson('/api/v1/specialties')->assertOk()->json('data'))->pluck('code');

        $this->assertCount(count(MedicalSpecialtyCatalog::BUILT_IN_LABELS), $codes);
        $this->assertSame(array_keys(MedicalSpecialtyCatalog::BUILT_IN_LABELS), $codes->all());

        $this->getJson('/api/v1/specialties')
            ->assertJsonFragment(['code' => 'pediatrics', 'label_fr' => 'Pédiatrie', 'label_ar' => 'طب الأطفال']);
    }

    #[Test]
    public function only_a_platform_admin_may_read_or_change_the_catalogue(): void
    {
        $pediatrics = MedicalSpecialty::query()->where('code', 'pediatrics')->firstOrFail();

        $this->getJson('/api/v1/admin/specialties')->assertUnauthorized();

        foreach ([$this->makePatientUser(), $this->makeListedClinic()['doctorUser']] as $user) {
            $this->actingAs($user)->getJson('/api/v1/admin/specialties')
                ->assertForbidden()
                ->assertJsonPath('reason', 'platform_admin_required');

            $this->actingAs($user)
                ->postJson('/api/v1/admin/specialties', ['label_fr' => 'Orthopédie', 'label_ar' => 'جراحة العظام'])
                ->assertForbidden();

            $this->actingAs($user)
                ->patchJson("/api/v1/admin/specialties/{$pediatrics->id}", ['is_active' => false])
                ->assertForbidden();
        }

        $this->assertTrue($pediatrics->fresh()->is_active);
    }

    #[Test]
    public function the_admin_list_reports_how_many_listed_doctors_each_specialty_has(): void
    {
        $clinic = $this->makeListedClinic();
        $this->giveSpecialty($clinic['doctor'], 'Pédiatrie', 'pediatrics');

        $rows = collect(
            $this->actingAs($this->makePlatformAdmin())->getJson('/api/v1/admin/specialties')->assertOk()->json('data'),
        )->keyBy('code');

        $this->assertSame(1, $rows['pediatrics']['doctors']);
        $this->assertSame(0, $rows['cardiology']['doctors']);
        $this->assertTrue($rows['pediatrics']['is_active']);
    }

    #[Test]
    public function a_doctor_stored_with_a_french_derived_code_is_found_and_counted_under_its_specialty(): void
    {
        // Profiles created before the catalogue owned the codes carry a slug
        // of the French label ("pediatrie") instead of "pediatrics".
        $legacy = $this->makeListedClinic();
        $this->giveSpecialty($legacy['doctor'], 'Pédiatrie', 'pediatrie');
        $current = $this->makeListedClinic();
        $this->giveSpecialty($current['doctor'], 'Pédiatrie', 'pediatrics');

        $this->getJson('/api/v1/doctors?specialty=pediatrics')->assertOk()->assertJsonCount(2, 'data');

        $rows = collect(
            $this->actingAs($this->makePlatformAdmin())->getJson('/api/v1/admin/specialties')->json('data'),
        )->keyBy('code');

        $this->assertSame(2, $rows['pediatrics']['doctors']);
    }

    #[Test]
    public function a_new_doctor_profile_gets_the_catalogue_code_for_a_french_label(): void
    {
        $clinic = $this->makeListedClinic();

        $profile = DoctorProfile::factory()
            ->create(['cabinet_id' => $clinic['cabinet']->getKey(), 'specialty' => 'Pédiatrie']);

        $this->assertSame('pediatrics', $profile->specialty_code);
    }

    #[Test]
    public function switching_a_specialty_off_removes_it_from_the_filter_but_keeps_its_doctors_findable(): void
    {
        $clinic = $this->makeListedClinic();
        $this->giveSpecialty($clinic['doctor'], 'Pédiatrie', 'pediatrics');
        $pediatrics = MedicalSpecialty::query()->where('code', 'pediatrics')->firstOrFail();

        $this->actingAs($this->makePlatformAdmin())
            ->patchJson("/api/v1/admin/specialties/{$pediatrics->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.code', 'pediatrics');

        $codes = collect($this->getJson('/api/v1/specialties')->json('data'))->pluck('code');
        $this->assertNotContains('pediatrics', $codes);

        // The doctor is still in discovery, still labelled with the specialty.
        $this->getJson('/api/v1/doctors')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.specialty.label_ar', 'طب الأطفال');
        $this->getJson('/api/v1/doctors?specialty=pediatrics')->assertJsonCount(1, 'data');

        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.specialty_updated']);

        // ...and switching it back on restores the filter entry.
        $this->actingAs($this->makePlatformAdmin())
            ->patchJson("/api/v1/admin/specialties/{$pediatrics->id}", ['is_active' => true])
            ->assertOk();

        $this->assertContains('pediatrics', collect($this->getJson('/api/v1/specialties')->json('data'))->pluck('code'));
    }

    #[Test]
    public function an_added_specialty_reaches_the_filter_the_clinic_suggestions_and_the_doctor_cards(): void
    {
        $this->actingAs($this->makePlatformAdmin())
            ->postJson('/api/v1/admin/specialties', ['label_fr' => '  Orthopédie ', 'label_ar' => 'جراحة العظام'])
            ->assertCreated()
            ->assertJsonPath('data.code', 'orthopedie')
            ->assertJsonPath('data.label_fr', 'Orthopédie')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.doctors', 0);

        $this->getJson('/api/v1/specialties')
            ->assertJsonFragment(['code' => 'orthopedie', 'label_fr' => 'Orthopédie', 'label_ar' => 'جراحة العظام']);

        $catalog = app(MedicalSpecialtyCatalog::class);
        $this->assertContains('Orthopédie', $catalog->labels());
        // A clinic that names the specialty — in any casing — gets its code.
        $this->assertSame('orthopedie', $catalog->codeFor('ORTHOPÉDIE'));

        $clinic = $this->makeListedClinic();
        $this->giveSpecialty($clinic['doctor'], 'Orthopédie', 'orthopedie');

        $this->getJson('/api/v1/doctors?specialty=orthopedie')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.specialty.label_ar', 'جراحة العظام');
    }

    #[Test]
    public function an_added_specialty_can_start_switched_off(): void
    {
        $this->actingAs($this->makePlatformAdmin())
            ->postJson('/api/v1/admin/specialties', [
                'label_fr' => 'Médecine du sport',
                'label_ar' => 'الطب الرياضي',
                'is_active' => false,
            ])
            ->assertCreated()
            ->assertJsonPath('data.is_active', false);

        $this->assertNotContains(
            'medecine_du_sport',
            collect($this->getJson('/api/v1/specialties')->json('data'))->pluck('code'),
        );
    }

    #[Test]
    public function a_specialty_the_catalogue_already_has_is_refused(): void
    {
        $admin = $this->makePlatformAdmin();

        foreach (['Pédiatrie', 'PÉDIATRIE', 'pediatrics', 'Cardiology'] as $duplicate) {
            $this->actingAs($admin)
                ->postJson('/api/v1/admin/specialties', ['label_fr' => $duplicate, 'label_ar' => 'اختبار'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('label_fr');
        }

        $this->actingAs($admin)
            ->postJson('/api/v1/admin/specialties', ['label_fr' => 'Orthopédie'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('label_ar');

        $this->assertSame(count(MedicalSpecialtyCatalog::BUILT_IN_LABELS), MedicalSpecialty::query()->count());
    }

    #[Test]
    public function labels_can_be_corrected_without_changing_the_code(): void
    {
        $clinic = $this->makeListedClinic();
        $this->giveSpecialty($clinic['doctor'], 'ORL', 'otorhinolaryngology');
        $orl = MedicalSpecialty::query()->where('code', 'otorhinolaryngology')->firstOrFail();
        $admin = $this->makePlatformAdmin();

        $this->actingAs($admin)
            ->patchJson("/api/v1/admin/specialties/{$orl->id}", [
                'label_fr' => 'Oto-rhino-laryngologie',
                'label_ar' => 'طب الأنف والأذن والحنجرة',
            ])
            ->assertOk()
            ->assertJsonPath('data.code', 'otorhinolaryngology')
            ->assertJsonPath('data.label_fr', 'Oto-rhino-laryngologie');

        $this->getJson('/api/v1/doctors')
            ->assertJsonPath('data.0.specialty.code', 'otorhinolaryngology')
            ->assertJsonPath('data.0.specialty.label_fr', 'Oto-rhino-laryngologie')
            ->assertJsonPath('data.0.specialty.label_ar', 'طب الأنف والأذن والحنجرة');

        // The original wording still resolves to the same code.
        $this->assertSame('otorhinolaryngology', app(MedicalSpecialtyCatalog::class)->codeFor('ORL'));

        // A name another specialty already uses is refused.
        $this->actingAs($admin)
            ->patchJson("/api/v1/admin/specialties/{$orl->id}", ['label_fr' => 'cardiologie'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('label_fr');
    }

    #[Test]
    public function the_web_back_office_adds_and_switches_specialties(): void
    {
        $this->actingAs($this->makePlatformAdmin());

        Livewire::test(CreateMedicalSpecialty::class)
            ->fillForm(['label_fr' => 'Orthopédie', 'label_ar' => 'جراحة العظام', 'is_active' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('medical_specialties', ['code' => 'orthopedie', 'label_ar' => 'جراحة العظام']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.specialty_created']);

        Livewire::test(CreateMedicalSpecialty::class)
            ->fillForm(['label_fr' => 'orthopédie', 'label_ar' => 'جراحة العظام'])
            ->call('create')
            ->assertHasFormErrors(['label_fr']);

        $cardiology = MedicalSpecialty::query()->where('code', 'cardiology')->firstOrFail();

        Livewire::test(ListMedicalSpecialties::class)
            ->assertCanSeeTableRecords([$cardiology])
            ->call('updateTableColumnState', 'is_active', (string) $cardiology->getKey(), false);

        $this->assertFalse($cardiology->fresh()->is_active);
        $this->assertNotContains('cardiology', collect($this->getJson('/api/v1/specialties')->json('data'))->pluck('code'));
    }

    /**
     * A doctor profile locks its specialty once set (corrections go through an
     * audited admin flow), so the fixture writes it straight to the table.
     */
    private function giveSpecialty(DoctorProfile $doctor, string $label, string $code): void
    {
        DB::table('doctor_profiles')
            ->where('id', $doctor->getKey())
            ->update(['specialty' => $label, 'specialty_code' => $code]);
    }
}
