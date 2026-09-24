<?php

namespace Tests\Feature\Patients;

use App\Enums\RoleName;
use App\Models\Consultation;
use App\Models\Patient;
use App\Models\PatientAlert;
use App\Models\Prescription;
use App\Models\User;
use App\Services\Clinical\PatientSafety;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PatientSafetyTest extends TestCase
{
    use RefreshDatabase;

    private User $doctor;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->doctor = User::factory()->create();
        $this->doctor->assignRole(RoleName::DOCTOR->value);
        $this->actingAs($this->doctor);
        $this->patient = Patient::factory()->create(['allergies' => null]);

        Storage::fake('local');
        config()->set('onlyoffice.url', 'http://onlyoffice.test');
        config()->set('onlyoffice.internal_url', 'http://onlyoffice.test');
        config()->set('onlyoffice.app_url', 'http://app.test');
        config()->set('onlyoffice.jwt_secret', 'test-secret');
    }

    public function test_allergy_matching_understands_drug_classes_brands_and_free_text(): void
    {
        $safety = app(PatientSafety::class);

        $this->assertSame([], $safety->allergenTerms('RAS'));
        $this->assertSame([], $safety->allergenTerms('Aucune allergie connue'));
        $this->assertSame(['penicilline', 'ains', 'urticaire'], $safety->allergenTerms('Pénicillines, AINS (urticaire)'));

        $this->alert('allergy', 'Pénicilline', 'severe');
        $this->patient->update(['allergies' => 'AINS']);

        $conflicts = $safety->allergyConflicts($this->patient, [
            'Augmentin 1 g',
            'Paracétamol 1 g',
            'Voltarène 50 mg',
            'Oméprazole 20 mg',
        ]);

        $this->assertCount(2, $conflicts);
        $this->assertSame('Augmentin 1 g', $conflicts[0]['medication']);
        $this->assertSame('Pénicilline', $conflicts[0]['allergy']);
        $this->assertStringContainsString('augmentin', $conflicts[0]['reason']);
        $this->assertSame('Voltarène 50 mg', $conflicts[1]['medication']);

        // A retired allergy no longer blocks.
        PatientAlert::query()->update(['is_active' => false]);
        $this->patient->update(['allergies' => null]);
        $this->assertSame([], $safety->allergyConflicts($this->patient, ['Amoxicilline 1 g']));
    }

    public function test_safety_list_is_managed_and_shown_on_the_patient_and_the_consultation(): void
    {
        $this->post(route('app.patients.alerts.store', $this->patient), [
            'type' => 'allergy',
            'label' => 'Pénicilline',
            'severity' => 'severe',
            'details' => 'Œdème de Quincke',
        ])->assertSessionHasNoErrors();
        $this->post(route('app.patients.alerts.store', $this->patient), [
            'type' => 'condition',
            'label' => 'Diabète type 2',
            'severity' => 'severe',
            'since' => '2019-03-01',
        ])->assertSessionHasNoErrors();
        $this->post(route('app.patients.alerts.store', $this->patient), [
            'type' => 'treatment',
            'label' => 'Metformine 850 mg',
        ])->assertSessionHasNoErrors();

        // Severity only applies to allergies.
        $this->assertNull(PatientAlert::query()->where('label', 'Diabète type 2')->value('severity'));

        $this->get(route('app.patients.show', $this->patient))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('canEditSafety', true)
                ->where('safety.allergies.0.label', 'Pénicilline')
                ->where('safety.allergies.0.severity_label', 'Sévère (anaphylaxie)')
                ->where('safety.conditions.0.label', 'Diabète type 2')
                ->where('safety.treatments.0.label', 'Metformine 850 mg')
            );

        $treatment = PatientAlert::query()->where('type', 'treatment')->firstOrFail();
        $this->patch(route('app.patient-alerts.deactivate', $treatment))->assertSessionHasNoErrors();
        $condition = PatientAlert::query()->where('type', 'condition')->firstOrFail();
        $this->delete(route('app.patient-alerts.destroy', $condition))->assertSessionHasNoErrors();

        $consultation = $this->consultation();
        $this->get(route('app.consultations.show', $consultation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('safety.allergies', 1)
                ->has('safety.treatments', 0)
                ->has('safety.conditions', 0)
            );

        $this->assertDatabaseHas('patient_alerts', ['id' => $treatment->getKey(), 'is_active' => false]);
        $this->assertDatabaseMissing('patient_alerts', ['id' => $condition->getKey()]);
        foreach (['patient.alert_added', 'patient.alert_deactivated', 'patient.alert_deleted'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => $action]);
        }
    }

    public function test_prescribing_an_allergen_is_refused_until_the_doctor_confirms(): void
    {
        $this->alert('allergy', 'Pénicilline', 'severe');
        $consultation = $this->consultation();
        $payload = [
            'prescribed_at' => now()->toDateString(),
            'paper_size' => 'A5',
            'source' => 'built_in',
            'template_key' => 'ordonnance',
            'items' => [
                ['medication' => 'Amoxicilline 1 g', 'dosage' => '1 cp x 2/j'],
                ['medication' => 'Paracétamol 1 g'],
            ],
        ];

        $this->post(route('app.consultations.prescriptions.store', $consultation), $payload)
            ->assertSessionHasErrors(['items' => 'Allergie : Amoxicilline 1 g — patient allergique à « Pénicilline » (classe « penicilline » (amoxicilline)). Cochez « Prescrire malgré l’allergie » pour confirmer.']);
        $this->assertSame(0, Prescription::query()->count());

        $this->post(route('app.consultations.prescriptions.store', $consultation), [...$payload, 'allergy_override' => true])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, Prescription::query()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'prescription.allergy_override']);

        // Nothing to check: saves normally.
        $this->post(route('app.consultations.prescriptions.store', $consultation), [
            ...$payload,
            'items' => [['medication' => 'Paracétamol 1 g']],
        ])->assertSessionHasNoErrors();
        $this->assertSame(2, Prescription::query()->count());
    }

    public function test_users_without_patient_update_cannot_change_the_safety_list(): void
    {
        $viewer = User::factory()->create();
        $alert = $this->alert('allergy', 'Iode');

        $this->actingAs($viewer)
            ->post(route('app.patients.alerts.store', $this->patient), ['type' => 'allergy', 'label' => 'Latex'])
            ->assertForbidden();
        $this->actingAs($viewer)
            ->delete(route('app.patient-alerts.destroy', $alert))
            ->assertForbidden();
    }

    private function alert(string $type, string $label, ?string $severity = null): PatientAlert
    {
        return PatientAlert::query()->create([
            'patient_id' => $this->patient->getKey(),
            'type' => $type,
            'label' => $label,
            'severity' => $severity,
            'created_by' => $this->doctor->getKey(),
        ]);
    }

    private function consultation(): Consultation
    {
        return Consultation::query()->create([
            'patient_id' => $this->patient->getKey(),
            'consulted_at' => now(),
            'status' => 'in_progress',
            'created_by' => $this->doctor->getKey(),
        ]);
    }
}
