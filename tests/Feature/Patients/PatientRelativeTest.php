<?php

namespace Tests\Feature\Patients;

use App\Enums\PatientRelation;
use App\Models\Cabinet;
use App\Models\Consultation;
use App\Models\ConsultationDiagnosis;
use App\Models\Patient;
use App\Models\PatientAlert;
use App\Models\PatientRelative;
use App\Models\User;
use App\Services\Clinical\FamilyMedicalHistory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\BuildsCabinets;
use Tests\TestCase;

/**
 * Family links between dossiers (« Ali est le frère de Sara ») and what the
 * relatives' dossiers report on the patient's own dossier.
 */
class PatientRelativeTest extends TestCase
{
    use BuildsCabinets, RefreshDatabase;

    private Cabinet $cabinet;

    private User $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        [$this->cabinet, $this->doctor] = $this->activeCabinetWithOwner('famille@example.com');
        $this->actingAs($this->doctor);
    }

    private function patient(string $first, string $last, ?string $gender, array $attributes = []): Patient
    {
        return Patient::factory()->create([
            'first_name' => $first,
            'last_name' => $last,
            'gender' => $gender,
            'allergies' => null,
            'antecedents_medical' => null,
            'antecedents_surgical' => null,
            'antecedents_family' => null,
            ...$attributes,
        ]);
    }

    private function relationBetween(Patient $patient, Patient $relative): ?string
    {
        return PatientRelative::query()
            ->where('patient_id', $patient->getKey())
            ->where('relative_patient_id', $relative->getKey())
            ->first()
            ?->relation
            ->value;
    }

    public function test_linking_a_brother_also_records_the_sister_on_his_side(): void
    {
        $sara = $this->patient('Sara', 'Benali', 'female');
        $ali = $this->patient('Ali', 'Benali', 'male');

        $this->post(route('app.patients.relatives.store', $sara), [
            'relative_id' => $ali->getKey(),
            'relation' => 'brother',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('brother', $this->relationBetween($sara, $ali));
        $this->assertSame('sister', $this->relationBetween($ali, $sara));
        $this->assertDatabaseCount('patient_relatives', 2);
        $this->assertSame(
            $this->cabinet->getKey(),
            PatientRelative::query()->firstOrFail()->cabinet_id,
        );
    }

    public function test_the_reverse_relation_follows_the_patients_sex_or_stays_neutral(): void
    {
        $son = $this->patient('Yacine', 'Kaci', 'male');
        $father = $this->patient('Omar', 'Kaci', 'male');
        $grandmother = $this->patient('Zohra', 'Kaci', 'female');
        $unknown = $this->patient('Nour', 'Kaci', null);

        $this->post(route('app.patients.relatives.store', $son), ['relative_id' => $father->getKey(), 'relation' => 'father'])
            ->assertSessionHasNoErrors();
        $this->post(route('app.patients.relatives.store', $son), ['relative_id' => $grandmother->getKey(), 'relation' => 'grandmother'])
            ->assertSessionHasNoErrors();
        $this->post(route('app.patients.relatives.store', $unknown), ['relative_id' => $father->getKey(), 'relation' => 'uncle'])
            ->assertSessionHasNoErrors();

        $this->assertSame('son', $this->relationBetween($father, $son));
        $this->assertSame('grandson', $this->relationBetween($grandmother, $son));
        // Nour's sex is unknown: Omar sees Nour as a nephew or niece.
        $this->assertSame('nephew_niece', $this->relationBetween($father, $unknown));
    }

    public function test_relinking_changes_the_relation_without_duplicating_the_link(): void
    {
        $sara = $this->patient('Sara', 'Benali', 'female');
        $ali = $this->patient('Ali', 'Benali', 'male');

        $this->post(route('app.patients.relatives.store', $sara), ['relative_id' => $ali->getKey(), 'relation' => 'brother']);
        $this->post(route('app.patients.relatives.store', $sara), ['relative_id' => $ali->getKey(), 'relation' => 'husband'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('patient_relatives', 2);
        $this->assertSame('husband', $this->relationBetween($sara, $ali));
        $this->assertSame('wife', $this->relationBetween($ali, $sara));
    }

    public function test_removing_a_link_removes_both_directions(): void
    {
        $sara = $this->patient('Sara', 'Benali', 'female');
        $ali = $this->patient('Ali', 'Benali', 'male');

        $this->post(route('app.patients.relatives.store', $sara), ['relative_id' => $ali->getKey(), 'relation' => 'brother']);
        $link = PatientRelative::query()->where('patient_id', $ali->getKey())->firstOrFail();

        $this->delete(route('app.patients.relatives.destroy', [$ali, $link]))->assertRedirect();

        $this->assertDatabaseCount('patient_relatives', 0);
    }

    public function test_a_link_is_removed_only_through_its_own_patient(): void
    {
        $sara = $this->patient('Sara', 'Benali', 'female');
        $ali = $this->patient('Ali', 'Benali', 'male');
        $other = $this->patient('Autre', 'Patient', 'male');

        $this->post(route('app.patients.relatives.store', $sara), ['relative_id' => $ali->getKey(), 'relation' => 'brother']);
        $link = PatientRelative::query()->where('patient_id', $sara->getKey())->firstOrFail();

        $this->delete(route('app.patients.relatives.destroy', [$other, $link]))->assertNotFound();
        $this->assertDatabaseCount('patient_relatives', 2);
    }

    public function test_a_patient_cannot_be_their_own_relative(): void
    {
        $sara = $this->patient('Sara', 'Benali', 'female');

        $this->post(route('app.patients.relatives.store', $sara), ['relative_id' => $sara->getKey(), 'relation' => 'sister'])
            ->assertSessionHasErrors('relative_id');
        $this->post(route('app.patients.relatives.store', $sara), ['relative_id' => $sara->getKey() + 999, 'relation' => 'invalid'])
            ->assertSessionHasErrors('relation');

        $this->assertDatabaseCount('patient_relatives', 0);
    }

    public function test_dossiers_of_another_cabinet_can_be_neither_found_nor_linked(): void
    {
        $sara = $this->patient('Sara', 'Benali', 'female');

        [, $otherOwner] = $this->activeCabinetWithOwner('autre@example.com');
        $this->actingAs($otherOwner);
        $stranger = $this->patient('Ali', 'Benali', 'male');
        $strangerSister = $this->patient('Lina', 'Benali', 'female');
        $this->post(route('app.patients.relatives.store', $stranger), ['relative_id' => $strangerSister->getKey(), 'relation' => 'sister']);
        $foreignLink = PatientRelative::query()->where('patient_id', $stranger->getKey())->firstOrFail();

        $this->actingAs($this->doctor);

        $this->getJson(route('app.patients.relatives.search', ['patient' => $sara, 'q' => 'Benali']))
            ->assertOk()
            ->assertJsonCount(0, 'patients');

        $this->post(route('app.patients.relatives.store', $sara), ['relative_id' => $stranger->getKey(), 'relation' => 'brother'])
            ->assertSessionHasErrors('relative_id');

        // Another cabinet's dossier and link are not reachable at all.
        $this->post(route('app.patients.relatives.store', $stranger), ['relative_id' => $sara->getKey(), 'relation' => 'brother'])
            ->assertNotFound();
        $this->delete(route('app.patients.relatives.destroy', [$stranger, $foreignLink]))->assertNotFound();

        $this->assertSame(0, PatientRelative::withoutCabinetScope()->where('patient_id', $sara->getKey())->count());
        $this->assertSame(2, PatientRelative::withoutCabinetScope()->count());
    }

    public function test_linking_requires_the_permission_to_edit_patients(): void
    {
        $sara = $this->patient('Sara', 'Benali', 'female');
        $ali = $this->patient('Ali', 'Benali', 'male');

        $reader = User::factory()->create(['cabinet_id' => $this->cabinet->getKey(), 'approved_at' => now()]);
        $reader->givePermissionTo('patients.view');

        $this->actingAs($reader)
            ->post(route('app.patients.relatives.store', $sara), ['relative_id' => $ali->getKey(), 'relation' => 'brother'])
            ->assertForbidden();
        $this->actingAs($reader)
            ->getJson(route('app.patients.relatives.search', ['patient' => $sara, 'q' => 'Ali']))
            ->assertForbidden();

        $this->assertDatabaseCount('patient_relatives', 0);
    }

    public function test_search_finds_dossiers_by_name_phone_or_number_and_flags_linked_ones(): void
    {
        $sara = $this->patient('Sara', 'Benali', 'female');
        $ali = $this->patient('Ali', 'Benali', 'male', ['phone' => '0555 12 34 56']);
        $lina = $this->patient('Lina', 'Benali', 'female');

        $this->post(route('app.patients.relatives.store', $sara), ['relative_id' => $ali->getKey(), 'relation' => 'brother']);

        $response = $this->getJson(route('app.patients.relatives.search', ['patient' => $sara, 'q' => 'benali']))
            ->assertOk();
        $found = collect($response->json('patients'))->keyBy('id');

        $this->assertFalse($found->has($sara->getKey()), 'The patient is never offered as their own relative.');
        $this->assertTrue($found[$ali->getKey()]['linked']);
        $this->assertFalse($found[$lina->getKey()]['linked']);

        $this->getJson(route('app.patients.relatives.search', ['patient' => $sara, 'q' => '0555123456']))
            ->assertOk()
            ->assertJsonPath('patients.0.id', $ali->getKey());
        $this->getJson(route('app.patients.relatives.search', ['patient' => $sara, 'q' => $lina->patient_number]))
            ->assertOk()
            ->assertJsonPath('patients.0.id', $lina->getKey());
    }

    public function test_family_medical_history_reports_each_relatives_problems(): void
    {
        $sara = $this->patient('Sara', 'Benali', 'female');
        $ahmed = $this->patient('Ahmed', 'Benali', 'male', [
            'date_of_birth' => now()->subYears(45)->toDateString(),
            'antecedents_medical' => 'Diabète type 2',
            'allergies' => 'RAS',
            'antecedents_surgical' => 'Néant',
            'antecedents_family' => 'Père décédé d’un infarctus',
        ]);
        $cousin = $this->patient('Karim', 'Benali', 'male', ['antecedents_family' => 'Ne doit pas apparaître']);
        $healthy = $this->patient('Lina', 'Benali', 'female');

        PatientAlert::query()->create(['patient_id' => $ahmed->getKey(), 'type' => 'allergy', 'label' => 'Pénicilline', 'severity' => 'severe', 'is_active' => true]);
        PatientAlert::query()->create(['patient_id' => $ahmed->getKey(), 'type' => 'condition', 'label' => 'HTA', 'is_active' => true]);
        PatientAlert::query()->create(['patient_id' => $ahmed->getKey(), 'type' => 'condition', 'label' => 'Ancienne', 'is_active' => false]);
        PatientAlert::query()->create(['patient_id' => $ahmed->getKey(), 'type' => 'treatment', 'label' => 'Metformine', 'is_active' => true]);
        $visit = Consultation::query()->create(['patient_id' => $cousin->getKey(), 'consulted_at' => now()->subMonth(), 'status' => 'completed', 'diagnostic' => '<p>Asthme</p>']);
        ConsultationDiagnosis::query()->create(['consultation_id' => $visit->getKey(), 'patient_id' => $cousin->getKey(), 'code' => 'J45', 'label' => 'Asthme']);

        $this->post(route('app.patients.relatives.store', $sara), ['relative_id' => $ahmed->getKey(), 'relation' => 'brother']);
        $this->post(route('app.patients.relatives.store', $sara), ['relative_id' => $cousin->getKey(), 'relation' => 'cousin']);
        $this->post(route('app.patients.relatives.store', $sara), ['relative_id' => $healthy->getKey(), 'relation' => 'sister']);

        $relatives = collect(app(FamilyMedicalHistory::class)->forPatient($sara))->keyBy('patient_id');

        $brother = $relatives[$ahmed->getKey()];
        $this->assertSame('brother', $brother['relation']);
        $this->assertSame('Frère', $brother['relation_label']);
        $this->assertSame('Ahmed B.', $brother['short_name']);
        $this->assertTrue($brother['first_degree']);
        $this->assertTrue($brother['has_alert']);
        $this->assertSame('link', $brother['source']);
        $this->assertNotNull($brother['link_id']);
        $this->assertSame(
            ['HTA', 'Diabète type 2', 'Allergie : Pénicilline · sévère (anaphylaxie)', 'Ses ATCD familiaux : Père décédé d’un infarctus'],
            array_column($brother['items'], 'display'),
        );
        $this->assertStringContainsString('Diabète type 2', (string) $brother['summary']);
        $this->assertStringNotContainsString('Metformine', (string) $brother['summary']);
        $this->assertStringNotContainsString('Ancienne', (string) $brother['summary']);
        $this->assertStringNotContainsString('RAS', (string) $brother['summary']);

        $cousinRow = $relatives[$cousin->getKey()];
        $this->assertFalse($cousinRow['first_degree']);
        $this->assertSame(['Diagnostic : Asthme (J45)'], array_column($cousinRow['items'], 'display'));

        $this->assertSame([], $relatives[$healthy->getKey()]['items']);
        $this->assertNull($relatives[$healthy->getKey()]['summary']);

        // Closest relatives first.
        $this->assertSame([$ahmed->getKey(), $healthy->getKey(), $cousin->getKey()], $relatives->keys()->all());
    }

    public function test_dossiers_of_the_same_mobile_family_account_count_as_relatives(): void
    {
        $group = (string) Str::uuid7();
        $owner = $this->patient('Amine', 'Haddad', 'male', ['antecedents_medical' => 'Asthme']);
        $owner->forceFill(['family_group_public_id' => $group, 'family_relation' => null])->saveQuietly();
        $son = $this->patient('Yanis', 'Haddad', 'male');
        $son->forceFill(['family_group_public_id' => $group, 'family_relation' => 'son'])->saveQuietly();
        $daughter = $this->patient('Ines', 'Haddad', 'female', ['allergies' => 'Arachide']);
        $daughter->forceFill(['family_group_public_id' => $group, 'family_relation' => 'daughter'])->saveQuietly();
        $outsider = $this->patient('Autre', 'Haddad', 'male', ['antecedents_medical' => 'Diabète']);
        $outsider->forceFill(['family_group_public_id' => (string) Str::uuid7()])->saveQuietly();

        $relatives = collect(app(FamilyMedicalHistory::class)->forPatient($son))->keyBy('patient_id');

        $this->assertCount(2, $relatives);
        $this->assertSame('father', $relatives[$owner->getKey()]['relation']);
        $this->assertSame('mobile', $relatives[$owner->getKey()]['source']);
        $this->assertNull($relatives[$owner->getKey()]['link_id']);
        $this->assertSame('sister', $relatives[$daughter->getKey()]['relation']);
        $this->assertSame(['Allergie : Arachide'], array_column($relatives[$daughter->getKey()]['items'], 'display'));

        $fromOwner = collect(app(FamilyMedicalHistory::class)->forPatient($owner))->keyBy('patient_id');
        $this->assertSame('son', $fromOwner[$son->getKey()]['relation']);
    }

    public function test_the_dossier_shows_relatives_and_their_problems(): void
    {
        $sara = $this->patient('Sara', 'Benali', 'female', ['antecedents_family' => 'Mère hypertendue']);
        $ali = $this->patient('Ali', 'Benali', 'male', ['antecedents_medical' => 'Diabète type 2']);

        $this->post(route('app.patients.relatives.store', $sara), ['relative_id' => $ali->getKey(), 'relation' => 'brother']);

        $this->get(route('app.patients.show', $sara))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('patients/Show')
                ->where('patient.antecedents_family', 'Mère hypertendue')
                ->where('canEditRelatives', true)
                ->has('relationOptions')
                ->has('relatives', 1)
                ->where('relatives.0.full_name', 'Ali Benali')
                ->where('relatives.0.relation_label', 'Frère')
                ->where('relatives.0.summary', 'Diabète type 2'));

        $this->assertContains(
            ['value' => 'brother', 'label' => 'Frère'],
            PatientRelation::options(),
        );
    }

    public function test_merging_dossiers_keeps_the_family_links(): void
    {
        $sara = $this->patient('Sara', 'Benali', 'female', ['date_of_birth' => '1990-01-01']);
        $saraDuplicate = $this->patient('Sara', 'Benali', 'female', ['date_of_birth' => '1990-01-01']);
        $ali = $this->patient('Ali', 'Benali', 'male');
        $lina = $this->patient('Lina', 'Benali', 'female');

        // Ali is linked to both dossiers of Sara, Lina only to the duplicate.
        $this->post(route('app.patients.relatives.store', $sara), ['relative_id' => $ali->getKey(), 'relation' => 'brother']);
        $this->post(route('app.patients.relatives.store', $saraDuplicate), ['relative_id' => $ali->getKey(), 'relation' => 'brother']);
        $this->post(route('app.patients.relatives.store', $saraDuplicate), ['relative_id' => $lina->getKey(), 'relation' => 'sister']);
        $this->post(route('app.patients.relatives.store', $sara), ['relative_id' => $saraDuplicate->getKey(), 'relation' => 'sister']);

        $this->post(route('app.patients.merge.store', $sara), ['duplicate_id' => $saraDuplicate->getKey()])
            ->assertRedirect(route('app.patients.show', $sara));

        $this->assertSame('brother', $this->relationBetween($sara, $ali));
        $this->assertSame('sister', $this->relationBetween($ali, $sara));
        $this->assertSame('sister', $this->relationBetween($sara, $lina));
        $this->assertSame('sister', $this->relationBetween($lina, $sara));
        $this->assertSame(0, PatientRelative::query()
            ->where('patient_id', $saraDuplicate->getKey())
            ->orWhere('relative_patient_id', $saraDuplicate->getKey())
            ->count());
        $this->assertNull($this->relationBetween($sara, $sara));
        $this->assertDatabaseCount('patient_relatives', 4);
    }
}
