<?php

namespace Tests\Feature\Security;

use App\Enums\RoleName;
use App\Models\Consultation;
use App\Models\Document;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NestedResourceIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->administrator = User::factory()->create();
        $this->administrator->assignRole(RoleName::ADMINISTRATOR->value);
    }

    public function test_encounter_routes_reject_an_encounter_from_a_different_patient_parent(): void
    {
        $routePatient = Patient::factory()->create();
        $ownerPatient = Patient::factory()->create();
        $draft = Encounter::factory()->for($ownerPatient)->create();
        $signed = Encounter::factory()->for($ownerPatient)->signed()->create();

        $this->actingAs($this->administrator)
            ->get(route('app.patients.encounters.show', [$routePatient, $draft]))
            ->assertNotFound();

        $this->get(route('app.patients.encounters.edit', [$routePatient, $draft]))
            ->assertNotFound();

        $this->put(route('app.patients.encounters.update', [$routePatient, $draft]), [
            'lock_version' => $draft->lock_version,
            'reason_for_visit' => 'Must not be saved',
        ])->assertNotFound();

        $this->post(route('app.patients.encounters.sign', [$routePatient, $draft]))
            ->assertNotFound();

        $this->get(route('app.patients.encounters.create-amendment', [$routePatient, $signed]))
            ->assertNotFound();

        $this->post(route('app.patients.encounters.store-amendment', [$routePatient, $signed]), [
            'amendment_reason' => 'Must not be created',
        ])->assertNotFound();

        $this->assertDatabaseMissing('encounter_notes', [
            'encounter_id' => $draft->getKey(),
            'content_text' => 'Must not be saved',
        ]);
        $this->assertNull($draft->fresh()->signed_at);
        $this->assertDatabaseMissing('encounters', [
            'amends_encounter_id' => $signed->getKey(),
            'amendment_reason' => 'Must not be created',
        ]);
    }

    public function test_consultation_child_routes_reject_resources_owned_by_a_sibling_consultation(): void
    {
        Storage::fake('local');

        $patient = Patient::factory()->create();
        $routeConsultation = $this->createConsultation($patient);
        $ownerConsultation = $this->createConsultation($patient);
        $prescription = Prescription::query()->create([
            'patient_id' => $patient->getKey(),
            'consultation_id' => $ownerConsultation->getKey(),
            'prescribed_at' => now(),
            'items' => [['medication' => 'Paracetamol']],
            'created_by' => $this->administrator->getKey(),
        ]);
        $legacyDocument = Document::query()->create([
            'patient_id' => $patient->getKey(),
            'consultation_id' => $ownerConsultation->getKey(),
            'category' => 'courrier',
            'title' => 'Sibling document',
            'content' => '<p>Protected</p>',
            'created_by' => $this->administrator->getKey(),
        ]);
        $uploadedPath = 'patient-documents/protected.pdf';
        Storage::put($uploadedPath, 'protected');
        $uploadedDocument = Document::query()->create([
            'patient_id' => $patient->getKey(),
            'consultation_id' => $ownerConsultation->getKey(),
            'category' => 'uploaded',
            'title' => 'Protected upload',
            'file_path' => $uploadedPath,
            'original_filename' => 'protected.pdf',
            'mime_type' => 'application/pdf',
            'created_by' => $this->administrator->getKey(),
        ]);

        $this->actingAs($this->administrator)
            ->post(route('app.consultations.prescriptions.word-document', [
                'consultation' => $routeConsultation,
                'prescription' => $prescription,
            ]), [
                'source' => 'built_in',
                'paper_size' => 'A5',
            ])
            ->assertNotFound();

        $this->post(route('app.consultations.word-documents.convert', [
            'consultation' => $routeConsultation,
            'document' => $legacyDocument,
        ]), ['paper_size' => 'A4'])
            ->assertNotFound();

        $this->delete(route('app.consultations.uploaded-documents.destroy', [
            'consultation' => $routeConsultation,
            'document' => $uploadedDocument,
        ]))->assertNotFound();

        $this->assertNull($prescription->fresh()->document_id);
        $this->assertNotNull($legacyDocument->fresh());
        $this->assertNotNull($uploadedDocument->fresh());
        Storage::assertExists($uploadedPath);
    }

    private function createConsultation(Patient $patient): Consultation
    {
        return Consultation::query()->create([
            'patient_id' => $patient->getKey(),
            'consulted_at' => now(),
            'status' => 'in_progress',
            'created_by' => $this->administrator->getKey(),
        ]);
    }
}
