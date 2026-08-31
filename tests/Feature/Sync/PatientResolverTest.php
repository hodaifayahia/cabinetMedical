<?php

namespace Tests\Feature\Sync;

use App\Models\Patient;
use App\Models\User;
use App\Services\Sync\PatientResolver;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Api\Concerns\BuildsCabinets;
use Tests\TestCase;

/**
 * The rule under test: an appointment arriving from the mobile app must attach
 * to the patient this installation already holds, keeping the local primary
 * key. A patient is created only when no local record describes the same
 * person.
 */
class PatientResolverTest extends TestCase
{
    use BuildsCabinets, RefreshDatabase;

    private PatientResolver $resolver;

    private int $cabinetId;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        [$cabinet, $owner] = $this->activeCabinetWithOwner('resolver@example.com');
        $this->cabinetId = (int) $cabinet->getKey();
        $this->owner = $owner;
        $this->actingAs($owner);
        $this->resolver = app(PatientResolver::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function identity(array $overrides = []): array
    {
        return array_merge([
            'public_id' => (string) Str::uuid7(),
            'patient_number' => null,
            'first_name' => 'Amina',
            'last_name' => 'Benali',
            'date_of_birth' => '1990-04-12',
            'gender' => 'female',
            'phone' => '0551223344',
            'email' => null,
        ], $overrides);
    }

    public function test_a_shared_public_id_reuses_the_local_patient_and_its_local_id(): void
    {
        $existing = Patient::factory()->create(['first_name' => 'Amina', 'last_name' => 'Benali']);

        $resolved = $this->resolver->resolve(
            $this->cabinetId,
            $this->identity(['public_id' => $existing->public_id]),
        );

        $this->assertSame($existing->getKey(), $resolved->getKey());
        $this->assertSame(1, Patient::query()->count());
    }

    public function test_the_dossier_number_matches_when_the_public_id_is_unknown(): void
    {
        $existing = Patient::factory()->create(['patient_number' => 'P-000123']);
        $remotePublicId = (string) Str::uuid7();

        $resolved = $this->resolver->resolve($this->cabinetId, $this->identity([
            'public_id' => $remotePublicId,
            'patient_number' => 'P-000123',
        ]));

        $this->assertSame($existing->getKey(), $resolved->getKey());
        $this->assertSame(1, Patient::query()->count());
        // Both installations converge on one identity so later syncs match
        // directly on public_id.
        $this->assertSame($remotePublicId, $resolved->fresh()->public_id);
    }

    public function test_name_and_phone_together_match_across_different_phone_formats(): void
    {
        $existing = Patient::factory()->create([
            'first_name' => 'Amina',
            'last_name' => 'Benali',
            'phone' => '05 51 22 33 44',
        ]);

        $resolved = $this->resolver->resolve($this->cabinetId, $this->identity([
            'phone' => '+213551223344',
        ]));

        $this->assertSame($existing->getKey(), $resolved->getKey());
        $this->assertSame(1, Patient::query()->count());
    }

    public function test_matching_is_case_insensitive_on_names(): void
    {
        $existing = Patient::factory()->create([
            'first_name' => 'amina',
            'last_name' => 'BENALI',
            'phone' => '0551223344',
        ]);

        $resolved = $this->resolver->resolve($this->cabinetId, $this->identity());

        $this->assertSame($existing->getKey(), $resolved->getKey());
    }

    public function test_a_shared_phone_alone_does_not_merge_two_family_members(): void
    {
        // Same household phone, different person: merging these would put one
        // person's appointments on another person's medical file.
        Patient::factory()->create([
            'first_name' => 'Karim',
            'last_name' => 'Benali',
            'phone' => '0551223344',
        ]);

        $resolved = $this->resolver->resolve($this->cabinetId, $this->identity());

        $this->assertSame(2, Patient::query()->count());
        $this->assertSame('Amina', $resolved->first_name);
    }

    public function test_an_unknown_patient_is_created_with_the_remote_identity(): void
    {
        $identity = $this->identity();

        $resolved = $this->resolver->resolve($this->cabinetId, $identity);

        $this->assertSame(1, Patient::query()->count());
        $this->assertSame($identity['public_id'], $resolved->public_id);
        $this->assertSame('Amina', $resolved->first_name);
        $this->assertSame('Benali', $resolved->last_name);
        $this->assertSame($this->cabinetId, $resolved->cabinet_id);
        // A dossier number is always present, generated when none was supplied.
        $this->assertNotEmpty($resolved->patient_number);
    }

    public function test_a_patient_from_another_cabinet_is_never_reused(): void
    {
        [$otherCabinet, $otherOwner] = $this->activeCabinetWithOwner('other@example.com');
        $this->actingAs($otherOwner);
        $foreign = Patient::factory()->create(['first_name' => 'Amina', 'last_name' => 'Benali']);

        // Back to this cabinet's own session, which is how a real sync run
        // executes: the actor always belongs to the cabinet being synced.
        $this->actingAs($this->owner);

        $resolved = $this->resolver->resolve(
            $this->cabinetId,
            $this->identity(['public_id' => $foreign->public_id]),
        );

        $this->assertNotSame($foreign->getKey(), $resolved->getKey());
        $this->assertSame($this->cabinetId, (int) $resolved->cabinet_id);
        $this->assertNotSame((int) $otherCabinet->getKey(), (int) $resolved->cabinet_id);
        // The foreign patient already holds that identity, so a fresh one is
        // minted rather than colliding on the database-wide unique index.
        $this->assertNotSame($foreign->public_id, $resolved->public_id);
    }

    public function test_an_exact_identity_match_restores_an_archived_patient(): void
    {
        $existing = Patient::factory()->create();
        $publicId = $existing->public_id;
        $existing->delete();

        $resolved = $this->resolver->resolve(
            $this->cabinetId,
            $this->identity(['public_id' => $publicId]),
        );

        $this->assertSame($existing->getKey(), $resolved->getKey());
        $this->assertFalse($resolved->fresh()->trashed());
    }

    public function test_a_heuristic_match_never_resurrects_an_archived_patient(): void
    {
        // The clinic archived this record deliberately; only an exact public_id
        // match may undo that.
        $archived = Patient::factory()->create([
            'first_name' => 'Amina',
            'last_name' => 'Benali',
            'phone' => '0551223344',
        ]);
        $archived->delete();

        $resolved = $this->resolver->resolve($this->cabinetId, $this->identity());

        $this->assertNotSame($archived->getKey(), $resolved->getKey());
        $this->assertTrue($archived->fresh()->trashed());
    }

    public function test_a_colliding_dossier_number_does_not_break_the_run(): void
    {
        Patient::factory()->create(['patient_number' => 'P-000123']);

        // Same dossier number, unmistakably a different person.
        $resolved = $this->resolver->resolve($this->cabinetId, $this->identity([
            'patient_number' => 'P-000123',
            'first_name' => 'Karim',
            'last_name' => 'Haddad',
            'phone' => '0770001122',
        ]));

        // The dossier number matched first, so this is treated as the same
        // record rather than creating a duplicate that would violate the
        // uniqueness constraint.
        $this->assertSame('P-000123', $resolved->patient_number);
        $this->assertSame(1, Patient::query()->count());
    }

    public function test_an_unknown_gender_is_dropped_rather_than_failing(): void
    {
        $resolved = $this->resolver->resolve($this->cabinetId, $this->identity([
            'gender' => 'not-a-gender',
        ]));

        $this->assertNull($resolved->gender);
    }

    public function test_a_missing_identity_resolves_to_nothing(): void
    {
        $this->assertNull($this->resolver->resolve($this->cabinetId, null));
    }
}
