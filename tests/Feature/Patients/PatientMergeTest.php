<?php

namespace Tests\Feature\Patients;

use App\Enums\RoleName;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Cabinet;
use App\Models\Consultation;
use App\Models\Patient;
use App\Models\PatientAlert;
use App\Models\Prescription;
use App\Models\User;
use App\Services\Sync\PatientResolver;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Api\Concerns\BuildsCabinets;
use Tests\TestCase;

/**
 * Merging two dossiers of the same person: everything moves to the kept
 * dossier, the duplicate is archived with a pointer, and sync follows it.
 */
class PatientMergeTest extends TestCase
{
    use BuildsCabinets, RefreshDatabase;

    private Cabinet $cabinet;

    private User $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        [$this->cabinet, $this->doctor] = $this->activeCabinetWithOwner('merge@example.com');
        $this->actingAs($this->doctor);
    }

    /**
     * @return array{0: Patient, 1: Patient}
     */
    private function pair(): array
    {
        $kept = Patient::factory()->create([
            'first_name' => 'Amina',
            'last_name' => 'Benali',
            'date_of_birth' => '1990-04-12',
            'phone' => '0551223344',
            'city' => null,
            'allergies' => 'Pénicilline',
        ]);
        $duplicate = Patient::factory()->create([
            'first_name' => 'BENALI',
            'last_name' => 'Âmina',
            'date_of_birth' => '1990-04-12',
            'phone' => '0660000000',
            'city' => 'Oran',
            'allergies' => 'Aspirine',
        ]);

        return [$kept, $duplicate];
    }

    public function test_merging_moves_every_record_and_archives_the_duplicate(): void
    {
        [$kept, $duplicate] = $this->pair();

        $visit = Consultation::query()->create(['patient_id' => $duplicate->getKey(), 'consulted_at' => now(), 'status' => 'completed', 'motif' => 'Toux']);
        Prescription::query()->create(['patient_id' => $duplicate->getKey(), 'consultation_id' => $visit->getKey(), 'prescribed_at' => now(), 'items' => []]);
        PatientAlert::query()->create(['patient_id' => $duplicate->getKey(), 'type' => 'allergy', 'label' => 'Aspirine', 'is_active' => true]);
        $appointment = Appointment::factory()->create(['patient_id' => $duplicate->getKey()]);
        $versionBefore = (int) $appointment->sync_version;

        $this->post(route('app.patients.merge.store', $kept), [
            'duplicate_id' => $duplicate->getKey(),
            'choices' => ['phone' => 'duplicate'],
        ])->assertRedirect(route('app.patients.show', $kept));

        $this->assertSame(1, Consultation::query()->where('patient_id', $kept->getKey())->count());
        $this->assertSame(1, Prescription::query()->where('patient_id', $kept->getKey())->count());
        $this->assertSame(1, PatientAlert::query()->where('patient_id', $kept->getKey())->count());

        // The appointment moved through the model, so sync publishes the change.
        $appointment->refresh();
        $this->assertSame($kept->getKey(), $appointment->patient_id);
        $this->assertGreaterThan($versionBefore, (int) $appointment->sync_version);

        $kept->refresh();
        $this->assertSame('0660000000', $kept->phone, 'The doctor chose the duplicate’s phone.');
        $this->assertSame('Oran', $kept->city, 'A blank field is filled from the duplicate.');
        $this->assertSame('Amina', $kept->first_name, 'Identity stays the kept dossier’s by default.');
        $this->assertStringContainsString('Pénicilline', (string) $kept->allergies);
        $this->assertStringContainsString('Aspirine', (string) $kept->allergies, 'Clinical text is never dropped.');

        $archived = Patient::withTrashed()->findOrFail($duplicate->getKey());
        $this->assertTrue($archived->trashed());
        $this->assertSame($kept->getKey(), $archived->merged_into_id);
        $this->assertTrue(AuditLog::query()->where('action', 'patient.merged')->exists());
    }

    public function test_sync_follows_a_merged_duplicate_to_the_kept_dossier(): void
    {
        [$kept, $duplicate] = $this->pair();

        $this->post(route('app.patients.merge.store', $kept), ['duplicate_id' => $duplicate->getKey()])->assertRedirect();

        $resolved = app(PatientResolver::class)->resolve((int) $this->cabinet->getKey(), [
            'public_id' => $duplicate->public_id,
            'first_name' => 'Amina',
            'last_name' => 'Benali',
        ]);

        $this->assertSame($kept->getKey(), $resolved?->getKey());
        $this->assertTrue(Patient::withTrashed()->findOrFail($duplicate->getKey())->trashed(), 'The duplicate stays archived.');
    }

    public function test_the_finder_groups_swapped_and_accented_names_and_the_preview_compares_them(): void
    {
        [$kept, $duplicate] = $this->pair();
        Patient::factory()->create(['first_name' => 'Karim', 'last_name' => 'Haddad']);
        Consultation::query()->create(['patient_id' => $duplicate->getKey(), 'consulted_at' => now(), 'status' => 'completed']);

        $groups = $this->getJson(route('app.patients.duplicates'))->assertOk()->json('groups');
        $this->assertCount(1, $groups);
        $this->assertEqualsCanonicalizing([$kept->getKey(), $duplicate->getKey()], array_column($groups[0]['patients'], 'id'));

        $this->getJson(route('app.patients.merge.candidates', $kept))
            ->assertOk()
            ->assertJsonPath('candidates.0.id', $duplicate->getKey());

        $preview = $this->getJson(route('app.patients.merge.preview', [$kept, $duplicate]))->assertOk();
        $phone = collect($preview->json('fields'))->firstWhere('field', 'phone');
        $this->assertTrue($phone['conflict']);
        $this->assertSame('consultations', $preview->json('moves.0.table'));
        $this->assertSame(1, $preview->json('moves.0.count'));
    }

    public function test_another_cabinets_dossier_cannot_be_merged(): void
    {
        [$kept] = $this->pair();
        [, $otherOwner] = $this->activeCabinetWithOwner('other@example.com');
        $this->actingAs($otherOwner);
        $foreign = Patient::factory()->create();
        $this->actingAs($this->doctor);

        $this->post(route('app.patients.merge.store', $kept), ['duplicate_id' => $foreign->getKey()])->assertNotFound();
        $this->assertFalse(Patient::withoutCabinetScope()->withTrashed()->findOrFail($foreign->getKey())->trashed());
    }

    public function test_an_assistant_cannot_merge(): void
    {
        [$kept, $duplicate] = $this->pair();
        $assistant = User::factory()->create(['cabinet_id' => $this->cabinet->getKey(), 'approved_at' => now()]);
        $assistant->assignRole(RoleName::ASSISTANT->value);

        $this->actingAs($assistant)
            ->post(route('app.patients.merge.store', $kept), ['duplicate_id' => $duplicate->getKey()])
            ->assertForbidden();
    }

    public function test_the_dossier_and_list_show_activity_and_merge_history(): void
    {
        [$kept, $duplicate] = $this->pair();
        Consultation::query()->create(['patient_id' => $kept->getKey(), 'consulted_at' => now(), 'status' => 'completed', 'motif' => '<p>Fièvre</p>']);
        $this->post(route('app.patients.merge.store', $kept), ['duplicate_id' => $duplicate->getKey()]);

        $this->get(route('app.patients.show', $kept))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('overview.counts.consultations', 1)
                ->where('overview.recent_consultations.0.motif', 'Fièvre')
                ->where('overview.merged.0.patient_number', $duplicate->patient_number)
                ->where('canMerge', true));

        $this->get(route('app.patients.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('stats.total', 1)
                ->where('stats.duplicates', 0)
                ->where('patients.data.0.visits_count', 1));
    }
}
