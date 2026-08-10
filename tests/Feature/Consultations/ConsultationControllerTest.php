<?php

namespace Tests\Feature\Consultations;

use App\Enums\RoleName;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\Document;
use App\Models\Patient;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ConsultationControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function userWithRole(RoleName $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }

    public function test_workspace_loads_for_consultation_with_soft_deleted_patient(): void
    {
        $user = $this->userWithRole(RoleName::DOCTOR);
        $user->givePermissionTo('consultations.view');

        $patient = Patient::factory()->create();
        $appointment = Appointment::factory()->for($patient)->create([
            'created_by' => $user->id,
        ]);

        $consultation = Consultation::query()->create([
            'patient_id' => $patient->id,
            'appointment_id' => $appointment->id,
            'consulted_at' => now(),
            'status' => 'in_progress',
            'created_by' => $user->id,
        ]);

        $patient->delete();

        $this->actingAs($user)
            ->get(route('app.consultations.show', $consultation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('consultations/Workspace')
                ->where('consultation.id', $consultation->id)
                ->where('patient.id', $patient->id)
                ->where('patient.full_name', $patient->full_name)
                ->where('history', []),
            );
    }

    public function test_soft_deleted_patient_can_be_saved_from_the_workspace(): void
    {
        $user = $this->userWithRole(RoleName::DOCTOR);
        $user->givePermissionTo('consultations.update');

        $patient = Patient::factory()->create();
        $appointment = Appointment::factory()->for($patient)->create([
            'created_by' => $user->id,
        ]);
        $consultation = Consultation::query()->create([
            'patient_id' => $patient->id,
            'appointment_id' => $appointment->id,
            'consulted_at' => now(),
            'status' => 'in_progress',
            'created_by' => $user->id,
        ]);

        $patient->delete();

        $this->actingAs($user)
            ->put(route('app.consultations.patient.update', $consultation), [
                'first_name' => 'Updated',
                'last_name' => $patient->last_name,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('patients', [
            'id' => $patient->id,
            'first_name' => 'Updated',
        ]);
    }

    public function test_saved_clinical_letter_html_is_sanitized_and_audited(): void
    {
        $user = $this->userWithRole(RoleName::DOCTOR);
        $user->givePermissionTo('consultations.update');
        $patient = Patient::factory()->create();
        $consultation = Consultation::query()->create([
            'patient_id' => $patient->getKey(),
            'consulted_at' => now(),
            'status' => 'in_progress',
            'created_by' => $user->getKey(),
        ]);

        $this->actingAs($user)
            ->post(route('app.consultations.documents.store', $consultation), [
                'category' => 'courrier',
                'title' => 'Courrier sécurisé',
                'content' => '<p onclick="steal()">Texte <strong>autorisé</strong>'
                    .'<img src="https://tracker.test/pixel.png">'
                    .'<a href="javascript:steal()">lien</a><script>steal()</script></p>',
            ])
            ->assertRedirect();

        $document = Document::query()->sole();
        $this->assertSame($user->getKey(), $document->created_by);
        $this->assertStringContainsString('<strong>autorisé</strong>', (string) $document->content);
        $this->assertStringNotContainsString('onclick', (string) $document->content);
        $this->assertStringNotContainsString('tracker.test', (string) $document->content);
        $this->assertStringNotContainsString('javascript:', (string) $document->content);
        $this->assertStringNotContainsString('<script', (string) $document->content);

        $audit = AuditLog::query()->where('action', 'clinical_document.created')->sole();
        /** @var array<string, mixed> $metadata */
        $metadata = $audit->getAttribute('metadata');
        $this->assertTrue($metadata['content_sanitized']);
        $this->assertTrue($metadata['content_present']);
        $this->assertArrayNotHasKey('content', $metadata);
    }

    public function test_today_list_includes_archived_patient_name_and_consultation_status(): void
    {
        $user = $this->userWithRole(RoleName::DOCTOR);
        $user->givePermissionTo('consultations.view');

        $patient = Patient::factory()->create([
            'first_name' => 'Houdaifa',
            'last_name' => 'Yahia',
        ]);
        $startsAt = CarbonImmutable::today()->setTime(9, 0);
        $appointment = Appointment::factory()->for($patient)->create([
            'appointment_date' => $startsAt->toDateString(),
            'starts_at' => $startsAt,
            'created_by' => $user->id,
        ]);
        Consultation::query()->create([
            'patient_id' => $patient->id,
            'appointment_id' => $appointment->id,
            'consulted_at' => $startsAt,
            'status' => 'completed',
            'created_by' => $user->id,
        ]);

        $patient->delete();

        $this->actingAs($user)
            ->get(route('app.consultations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('appointments.0.patient_name', 'Houdaifa Yahia')
                ->where('appointments.0.consultation_status', 'completed'),
            );
    }

    public function test_workspace_exposes_prior_debt_and_the_current_payment_ledger(): void
    {
        $user = $this->userWithRole(RoleName::ADMINISTRATOR);
        $patient = Patient::factory()->create();
        $older = Consultation::query()->create([
            'patient_id' => $patient->getKey(),
            'consulted_at' => now()->subMonth(),
            'status' => 'completed',
            'payment_amount_minor' => 150000,
            'payment_service' => 'Ancienne consultation',
            'is_paid' => false,
            'created_by' => $user->getKey(),
        ]);
        $current = Consultation::query()->create([
            'patient_id' => $patient->getKey(),
            'consulted_at' => now(),
            'status' => 'in_progress',
            'payment_amount_minor' => 100000,
            'payment_service' => 'Consultation du jour',
            'is_paid' => false,
            'created_by' => $user->getKey(),
        ]);

        $this->actingAs($user);
        $older->payments()->create([
            'patient_id' => $patient->getKey(),
            'amount_minor' => 50000,
            'method' => 'Espèces',
            'received_at' => now()->subWeeks(2),
            'received_by' => $user->getKey(),
        ]);

        $this->get(route('app.consultations.show', $current))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('consultations/Workspace')
                ->where('patientDebt.total', 1000)
                ->has('patientDebt.consultations', 1)
                ->where('patientDebt.consultations.0.id', $older->getKey())
                ->where('patientDebt.consultations.0.paid', 500)
                ->where('patientDebt.consultations.0.outstanding', 1000)
                ->where('consultation.payment_paid', 0)
                ->where('consultation.payment_outstanding', 1000)
                ->where('consultation.payment_status', 'unpaid')
                ->where('canCollectPayment', true)
            );
    }

    public function test_completing_a_consultation_requires_the_dedicated_permission(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('consultations.update');
        $patient = Patient::factory()->create();
        $consultation = Consultation::query()->create([
            'patient_id' => $patient->getKey(),
            'consulted_at' => now(),
            'status' => 'in_progress',
            'created_by' => $user->getKey(),
        ]);

        $this->actingAs($user)
            ->put(route('app.consultations.update', $consultation), [
                'complete' => true,
            ])
            ->assertForbidden();

        $this->assertSame('in_progress', $consultation->fresh()->status);
    }
}
