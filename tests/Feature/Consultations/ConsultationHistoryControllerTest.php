<?php

namespace Tests\Feature\Consultations;

use App\Enums\RoleName;
use App\Models\Cabinet;
use App\Models\Consultation;
use App\Models\Document;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ConsultationHistoryControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function doctor(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleName::DOCTOR->value);

        return $user;
    }

    public function test_history_lists_patient_consultations_ordered_by_date_desc(): void
    {
        $doctor = $this->doctor();
        $patient = Patient::factory()->create();

        $older = Consultation::query()->create([
            'patient_id' => $patient->id,
            'consulted_at' => CarbonImmutable::parse('2026-01-10 09:00:00'),
            'status' => 'completed',
            'motif' => 'Ancienne visite',
            'created_by' => $doctor->id,
        ]);
        $newer = Consultation::query()->create([
            'patient_id' => $patient->id,
            'consulted_at' => CarbonImmutable::parse('2026-03-15 14:00:00'),
            'status' => 'completed',
            'motif' => 'Visite récente',
            'created_by' => $doctor->id,
        ]);

        $this->actingAs($doctor)
            ->get(route('app.consultations.history', $patient))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('consultations/History')
                ->where('patient.id', $patient->id)
                ->has('consultations', 2)
                ->where('consultations.0.id', $newer->id)
                ->where('consultations.1.id', $older->id),
            );
    }

    public function test_history_only_returns_consultations_from_the_current_cabinet(): void
    {
        $ownCabinet = Cabinet::query()->create(['name' => 'Cabinet A', 'status' => 'active']);
        $otherCabinet = Cabinet::query()->create(['name' => 'Cabinet B', 'status' => 'active']);

        $doctor = $this->doctor();
        // Attach the doctor to an active cabinet and mark them approved so the
        // cabinet-status gate lets the request through to the controller.
        $doctor->forceFill([
            'cabinet_id' => $ownCabinet->id,
            'approved_at' => now(),
        ])->save();

        // Patient + consultation inside the acting doctor's cabinet.
        $patient = Patient::factory()->create(['cabinet_id' => $ownCabinet->id]);
        $mine = Consultation::query()->create([
            'cabinet_id' => $ownCabinet->id,
            'patient_id' => $patient->id,
            'consulted_at' => CarbonImmutable::parse('2026-02-01 09:00:00'),
            'status' => 'completed',
            'created_by' => $doctor->id,
        ]);

        // A consultation for the SAME patient id but flagged to another
        // cabinet must never leak through the tenant scope.
        Consultation::query()->create([
            'cabinet_id' => $otherCabinet->id,
            'patient_id' => $patient->id,
            'consulted_at' => CarbonImmutable::parse('2026-02-02 09:00:00'),
            'status' => 'completed',
            'created_by' => $doctor->id,
        ]);

        $this->actingAs($doctor)
            ->get(route('app.consultations.history', $patient))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('consultations', 1)
                ->where('consultations.0.id', $mine->id),
            );
    }

    public function test_detail_includes_clinical_fields_prescriptions_documents_and_pricing(): void
    {
        $doctor = $this->doctor();
        $patient = Patient::factory()->create();

        $consultation = Consultation::query()->create([
            'patient_id' => $patient->id,
            'consulted_at' => CarbonImmutable::parse('2026-04-01 09:00:00'),
            'status' => 'completed',
            'motif' => 'Douleurs abdominales',
            'diagnostic' => 'Gastrite',
            'traitement' => 'IPP 8 jours',
            'weight_kg' => 72.5,
            'blood_pressure' => '12/8',
            'payment_amount_minor' => 250000,
            'payment_method' => 'Espèces',
            'payment_service' => 'Consultation générale',
            'is_paid' => true,
            'created_by' => $doctor->id,
        ]);

        $ordonnance = Document::query()->create([
            'patient_id' => $patient->id,
            'consultation_id' => $consultation->id,
            'category' => 'ordonnance',
            'title' => 'Ordonnance du 01/04/2026',
            'created_by' => $doctor->id,
        ]);
        $prescription = Prescription::query()->create([
            'patient_id' => $patient->id,
            'consultation_id' => $consultation->id,
            'document_id' => $ordonnance->id,
            'prescribed_at' => CarbonImmutable::parse('2026-04-01 09:10:00'),
            'items' => [
                ['medication' => 'Oméprazole 20mg', 'dosage' => '1 le matin', 'duration' => '8 jours'],
            ],
            'notes' => 'À jeun',
            'created_by' => $doctor->id,
        ]);

        $certificat = Document::query()->create([
            'patient_id' => $patient->id,
            'consultation_id' => $consultation->id,
            'category' => 'certificat',
            'title' => 'Certificat médical',
            'created_by' => $doctor->id,
        ]);

        $this->actingAs($doctor)
            ->get(route('app.consultations.history.show', $consultation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('consultations/HistoryDetail')
                ->where('consultation.id', $consultation->id)
                ->where('consultation.motif', 'Douleurs abdominales')
                ->where('consultation.diagnostic', 'Gastrite')
                ->where('consultation.payment_amount', 2500)
                ->where('consultation.is_paid', true)
                ->where('consultation.payment_method', 'Espèces')
                ->has('prescriptions', 1)
                ->where('prescriptions.0.id', $prescription->id)
                ->where('prescriptions.0.document_id', $ordonnance->id)
                ->has('prescriptions.0.download_url')
                ->has('documents', 2),
            );
    }

    public function test_history_requires_consultations_view_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RoleName::CASHIER->value);

        $patient = Patient::factory()->create();

        $this->actingAs($user)
            ->get(route('app.consultations.history', $patient))
            ->assertForbidden();
    }
}
