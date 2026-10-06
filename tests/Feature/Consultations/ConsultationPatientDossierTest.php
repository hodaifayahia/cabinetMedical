<?php

namespace Tests\Feature\Consultations;

use App\Actions\Patients\LinkPatientRelativeAction;
use App\Enums\PatientRelation;
use App\Models\Cabinet;
use App\Models\Consultation;
use App\Models\Patient;
use App\Models\PatientAlert;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\BuildsCabinets;
use Tests\TestCase;

/**
 * The patient's medical history as edited from the consultation workspace,
 * and the relatives' problems shown to the doctor during the visit.
 */
class ConsultationPatientDossierTest extends TestCase
{
    use BuildsCabinets, RefreshDatabase;

    private Cabinet $cabinet;

    private User $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        [$this->cabinet, $this->doctor] = $this->activeCabinetWithOwner('dossier@example.com');
        $this->actingAs($this->doctor);
    }

    private function visit(Patient $patient): Consultation
    {
        return Consultation::query()->create([
            'patient_id' => $patient->getKey(),
            'consulted_at' => now(),
            'status' => 'in_progress',
            'created_by' => $this->doctor->getKey(),
        ]);
    }

    public function test_the_workspace_shows_a_brothers_chronic_disease_and_visits(): void
    {
        $sara = Patient::factory()->create(['first_name' => 'Sara', 'last_name' => 'Benali', 'gender' => 'female', 'allergies' => null]);
        $ahmed = Patient::factory()->create([
            'first_name' => 'Ahmed',
            'last_name' => 'Benali',
            'gender' => 'male',
            'allergies' => null,
            'antecedents_medical' => 'Diabète type 2',
        ]);
        PatientAlert::query()->create(['patient_id' => $ahmed->getKey(), 'type' => 'allergy', 'label' => 'Pénicilline', 'is_active' => true]);
        app(LinkPatientRelativeAction::class)->link($sara, $ahmed, PatientRelation::BROTHER, $this->doctor);

        Consultation::query()->create([
            'patient_id' => $ahmed->getKey(),
            'consulted_at' => now()->subWeek(),
            'status' => 'completed',
            'motif' => 'Polyurie',
            'diagnostic' => 'Diabète déséquilibré',
        ]);

        $this->get(route('app.consultations.show', $this->visit($sara)))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('consultations/Workspace')
                ->has('familyMedical', 1)
                ->where('familyMedical.0.relation', 'brother')
                ->where('familyMedical.0.relation_label', 'Frère')
                ->where('familyMedical.0.short_name', 'Ahmed B.')
                ->where('familyMedical.0.first_degree', true)
                ->where('familyMedical.0.has_alert', true)
                ->where('familyMedical.0.summary', 'Diabète type 2 ; Allergie : Pénicilline ; Diagnostic : Diabète déséquilibré')
                ->has('familyHistory', 1)
                ->where('familyHistory.0.patient_name', 'Ahmed Benali')
                ->where('familyHistory.0.relation', 'brother')
                ->where('familyHistory.0.motif', 'Polyurie')
                ->whereType('patient.updated_at', 'string')
                ->whereType('consultation.updated_at', 'string')
                ->where('options.maritalStatuses.1', ['value' => 'married', 'label' => 'Marié(e)']));
    }

    public function test_relatives_of_another_cabinet_never_reach_the_workspace(): void
    {
        $sara = Patient::factory()->create(['first_name' => 'Sara', 'last_name' => 'Benali']);
        $consultation = $this->visit($sara);

        [, $otherOwner] = $this->activeCabinetWithOwner('voisin@example.com');
        $this->actingAs($otherOwner);
        $stranger = Patient::factory()->create(['antecedents_medical' => 'Secret médical']);
        $strangerSibling = Patient::factory()->create();
        app(LinkPatientRelativeAction::class)->link($stranger, $strangerSibling, PatientRelation::SISTER);

        $this->actingAs($this->doctor)
            ->get(route('app.consultations.show', $consultation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('familyMedical', [])
                ->where('familyHistory', []));
    }

    public function test_long_allergy_notes_saved_in_the_patient_form_never_block_the_workspace_autosave(): void
    {
        $patient = Patient::factory()->create();
        $consultation = $this->visit($patient);
        $longAllergies = trim(str_repeat('Pénicilline : urticaire géante. ', 150));

        $this->assertGreaterThan(2000, mb_strlen($longAllergies));

        $this->put(route('app.consultations.patient.update', $consultation), [
            'first_name' => $patient->first_name,
            'last_name' => $patient->last_name,
            'allergies' => $longAllergies,
            'antecedents_medical' => 'Asthme',
            'antecedents_gyneco' => 'G2P2',
            'marital_status' => 'married',
            'smoking_status' => 'former_smoker',
            'profession' => 'Enseignante',
            'referred_by' => 'Dr Kaci',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $patient->refresh();
        $this->assertSame($longAllergies, $patient->allergies);
        $this->assertSame('G2P2', $patient->antecedents_gyneco);
        $this->assertSame('married', $patient->marital_status);
    }

    public function test_the_workspace_and_the_patient_form_share_the_same_limits(): void
    {
        $patient = Patient::factory()->create();
        $consultation = $this->visit($patient);
        $tooLong = str_repeat('a', 10001);

        $this->put(route('app.consultations.patient.update', $consultation), [
            'first_name' => $patient->first_name,
            'last_name' => $patient->last_name,
            'antecedents_family' => $tooLong,
        ])->assertSessionHasErrors('antecedents_family');

        $this->put(route('app.patients.update', $patient), [
            'first_name' => $patient->first_name,
            'last_name' => $patient->last_name,
            'antecedents_family' => $tooLong,
        ])->assertSessionHasErrors('antecedents_family');
    }
}
