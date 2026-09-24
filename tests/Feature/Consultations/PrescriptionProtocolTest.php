<?php

namespace Tests\Feature\Consultations;

use App\Enums\RoleName;
use App\Models\Consultation;
use App\Models\Patient;
use App\Models\PrescriptionProtocol;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PrescriptionProtocolTest extends TestCase
{
    use RefreshDatabase;

    private User $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->doctor = User::factory()->create();
        $this->doctor->assignRole(RoleName::DOCTOR->value);
        $this->actingAs($this->doctor);
    }

    public function test_doctor_saves_uses_and_deletes_protocols_shown_most_used_first(): void
    {
        $angine = [
            'name' => 'Angine adulte',
            'notes' => 'Revoir si fièvre > 3 jours',
            'items' => [
                ['medication' => 'Amoxicilline 1 g', 'dosage' => '1 cp x 2/j', 'duration' => '6 jours', 'instructions' => ''],
                ['medication' => 'Paracétamol 1 g', 'dosage' => '1 cp si fièvre', 'duration' => '', 'instructions' => 'Max 3/j'],
            ],
        ];
        $this->post(route('app.prescription-protocols.store'), $angine)->assertSessionHasNoErrors();
        $this->post(route('app.prescription-protocols.store'), [
            'name' => 'Gastro-entérite',
            'items' => [['medication' => 'SRO sachets']],
        ])->assertSessionHasNoErrors();

        // Names are unique within the cabinet.
        $this->post(route('app.prescription-protocols.store'), $angine)
            ->assertSessionHasErrors(['name' => 'Un protocole porte déjà ce nom.']);

        $gastro = PrescriptionProtocol::query()->where('name', 'Gastro-entérite')->firstOrFail();
        $this->post(route('app.prescription-protocols.used', $gastro))->assertRedirect();

        $consultation = Consultation::query()->create([
            'patient_id' => Patient::factory()->create()->getKey(),
            'consulted_at' => now(),
            'status' => 'in_progress',
            'created_by' => $this->doctor->getKey(),
        ]);

        $this->get(route('app.consultations.show', $consultation))
            ->assertInertia(fn (Assert $page) => $page
                ->has('protocols', 2)
                ->where('protocols.0.name', 'Gastro-entérite')
                ->where('protocols.0.uses', 1)
                ->where('protocols.1.items.0.medication', 'Amoxicilline 1 g')
                ->where('protocols.1.notes', 'Revoir si fièvre > 3 jours')
            );

        $this->delete(route('app.prescription-protocols.destroy', $gastro))->assertSessionHasNoErrors();
        $this->assertSame(1, PrescriptionProtocol::query()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'prescription_protocol.deleted']);
    }

    public function test_protocols_need_items_and_the_prescribing_permission(): void
    {
        $this->post(route('app.prescription-protocols.store'), ['name' => 'Vide', 'items' => []])
            ->assertSessionHasErrors('items');

        $this->actingAs(User::factory()->create())
            ->post(route('app.prescription-protocols.store'), [
                'name' => 'X',
                'items' => [['medication' => 'Y']],
            ])
            ->assertForbidden();
    }
}
