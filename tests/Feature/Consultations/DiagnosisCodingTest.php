<?php

namespace Tests\Feature\Consultations;

use App\Enums\RoleName;
use App\Models\Consultation;
use App\Models\ConsultationDiagnosis;
use App\Models\Patient;
use App\Models\PatientAlert;
use App\Models\User;
use App\Services\Clinical\Cim10Catalog;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DiagnosisCodingTest extends TestCase
{
    use RefreshDatabase;

    private User $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->doctor = User::factory()->create();
        $this->doctor->assignRole(RoleName::DOCTOR->value);
        $this->actingAs($this->doctor);
    }

    public function test_catalogue_search_matches_codes_and_accent_insensitive_words(): void
    {
        $catalog = app(Cim10Catalog::class);

        $this->assertSame('J03.9', $catalog->search('J03')[0]['code']);
        $this->assertSame('E11.9', $catalog->search('diabete type 2')[0]['code']);
        $this->assertContains('I10', array_column($catalog->search('hypertension'), 'code'));
        $this->assertSame([], $catalog->search(''));
        $this->assertNull($catalog->find('ZZZ'));
        $this->assertSame('Hypertension essentielle (primitive)', $catalog->find('i10')['label'] ?? null);

        $codes = array_column(
            (array) $this->getJson(route('app.cim10.search', ['q' => 'angine']))->assertOk()->json('results'),
            'code',
        );
        // Both the throat and the chest meaning of « angine ».
        $this->assertContains('J03.9', $codes);
        $this->assertContains('I20.9', $codes);
    }

    public function test_consultation_diagnoses_are_coded_replaced_and_shown(): void
    {
        $consultation = $this->consultation(Patient::factory()->create(), '2026-06-10 09:00:00');

        $this->put(route('app.consultations.diagnoses.sync', $consultation), ['codes' => ['J03.9', 'R50.9']])
            ->assertSessionHasNoErrors();
        $this->put(route('app.consultations.diagnoses.sync', $consultation), ['codes' => ['J03.9']])
            ->assertSessionHasNoErrors();

        $this->assertSame(['J03.9'], ConsultationDiagnosis::query()->pluck('code')->all());

        $this->put(route('app.consultations.diagnoses.sync', $consultation), ['codes' => ['XX9']])
            ->assertSessionHasErrors('codes');

        $this->get(route('app.consultations.show', $consultation))
            ->assertInertia(fn (Assert $page) => $page
                ->where('diagnosisCodes.0.code', 'J03.9')
                ->where('diagnosisCodes.0.label', 'Amygdalite (angine) aiguë, sans précision')
            );
        $this->assertDatabaseHas('audit_logs', ['action' => 'consultation.diagnoses_coded']);
    }

    public function test_background_searches_do_not_hijack_the_redirect_after_saving(): void
    {
        $consultation = $this->consultation(Patient::factory()->create(), '2026-06-10 09:00:00');

        // The page, then the picker's AJAX search, then saving the codes.
        $this->get(route('app.consultations.show', $consultation))->assertOk();
        $this->get(route('app.cim10.search', ['q' => 'angine']), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();
        $this->get(route('app.search', ['q' => 'amina']), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();

        $this->put(route('app.consultations.diagnoses.sync', $consultation), ['codes' => ['J03.9']])
            ->assertRedirect(route('app.consultations.show', $consultation));
    }

    public function test_statistics_show_top_diagnoses_demographics_and_chronic_conditions(): void
    {
        $woman = Patient::factory()->create(['gender' => 'female', 'date_of_birth' => '1980-01-01', 'city' => 'Blida']);
        $child = Patient::factory()->create(['gender' => 'male', 'date_of_birth' => '2022-05-01', 'city' => 'blida']);

        foreach ([[$woman, ['I10', 'E11.9']], [$woman, ['I10']], [$child, ['J03.9']]] as [$patient, $codes]) {
            $consultation = $this->consultation($patient, '2026-06-02 09:00:00');
            $this->put(route('app.consultations.diagnoses.sync', $consultation), ['codes' => $codes]);
        }
        $this->consultation($child, '2026-06-03 09:00:00');
        $this->consultation($child, '2025-06-03 09:00:00');

        PatientAlert::query()->create(['patient_id' => $woman->getKey(), 'type' => 'condition', 'label' => 'Diabète type 2']);
        PatientAlert::query()->create(['patient_id' => $child->getKey(), 'type' => 'condition', 'label' => 'diabète  type 2']);

        $this->get(route('app.statistics.index', ['year' => 2026]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('statistics/Index')
                ->where('summary.consultations', 4)
                ->where('summary.patients', 2)
                ->where('summary.coded_share', 75)
                ->where('topDiagnoses.0.code', 'I10')
                ->where('topDiagnoses.0.value', 2)
                ->where('topDiagnoses.0.patients', 1)
                ->where('chapters.0.label', 'Appareil circulatoire')
                ->where('monthly.5.value', 4)
                ->where('demographics.sexes.0.value', 1)
                ->where('demographics.ages.0.value', 1)
                ->where('demographics.ages.3.value', 0)
                ->where('demographics.ages.4.value', 1)
                ->where('demographics.cities.0.value', 2)
                ->where('chronic.0.value', 2)
            );

        $assistant = User::factory()->create();
        $assistant->assignRole(RoleName::ASSISTANT->value);
        $this->actingAs($assistant)->get(route('app.statistics.index'))->assertForbidden();
    }

    private function consultation(Patient $patient, string $at): Consultation
    {
        return Consultation::query()->create([
            'patient_id' => $patient->getKey(),
            'consulted_at' => $at,
            'status' => 'completed',
            'created_by' => $this->doctor->getKey(),
        ]);
    }
}
