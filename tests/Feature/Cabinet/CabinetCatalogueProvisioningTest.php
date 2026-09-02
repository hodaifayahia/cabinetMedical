<?php

namespace Tests\Feature\Cabinet;

use App\Models\Cabinet;
use App\Services\Cabinet\CabinetCatalogueProvisioner;
use App\Services\Cabinet\CabinetProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `exams`, `medications` and `bilan_types` all carry `cabinet_id`, but the
 * install seeders run once and land the whole catalogue on whichever cabinet is
 * in context. Every cabinet registered afterwards opened with an empty
 * prescription list and nothing to order, and no way to fill either from inside
 * the application.
 *
 * @see CabinetCatalogueProvisioner
 */
class CabinetCatalogueProvisioningTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function registrationPayload(string $email): array
    {
        return [
            'name' => 'Dr Test',
            'email' => $email,
            'password' => 'mot-de-passe-solide-2026',
            'phone' => '0550112233',
            'cabinet_name' => 'Cabinet '.$email,
            'specialization' => 'Cardiologie',
            'wilaya' => 16,
        ];
    }

    private function countFor(string $table, Cabinet $cabinet): int
    {
        return (int) DB::table($table)->where('cabinet_id', $cabinet->getKey())->count();
    }

    public function test_a_newly_registered_cabinet_opens_with_its_own_catalogue(): void
    {
        $owner = app(CabinetProvisioningService::class)
            ->provision($this->registrationPayload('new-cabinet@example.test'));

        $cabinet = $owner->cabinet;
        $this->assertNotNull($cabinet);

        $this->assertGreaterThan(0, $this->countFor('exams', $cabinet), 'the cabinet has no examinations to order');
        $this->assertGreaterThan(0, $this->countFor('medications', $cabinet), 'the cabinet has an empty prescription list');
        $this->assertGreaterThan(0, $this->countFor('bilan_types', $cabinet), 'exam categories have nothing to resolve against');
    }

    public function test_each_cabinet_gets_its_own_rows_rather_than_sharing(): void
    {
        $service = app(CabinetProvisioningService::class);

        $first = $service->provision($this->registrationPayload('first@example.test'))->cabinet;
        $second = $service->provision($this->registrationPayload('second@example.test'))->cabinet;

        $this->assertNotSame($first->getKey(), $second->getKey());
        $this->assertSame(
            $this->countFor('medications', $first),
            $this->countFor('medications', $second),
            'the second cabinet did not receive its own copy of the catalogue',
        );

        // Nothing may be left unattributed: a null cabinet_id row leaks across
        // every tenant, because the global scope only filters on a value.
        $this->assertSame(0, (int) DB::table('medications')->whereNull('cabinet_id')->count());
        $this->assertSame(0, (int) DB::table('exams')->whereNull('cabinet_id')->count());
    }

    public function test_reprovisioning_adds_nothing_the_cabinet_already_has(): void
    {
        $provisioner = app(CabinetCatalogueProvisioner::class);
        $cabinet = app(CabinetProvisioningService::class)
            ->provision($this->registrationPayload('idempotent@example.test'))
            ->cabinet;

        $before = $this->countFor('medications', $cabinet);
        $added = $provisioner->provisionFor($cabinet);

        $this->assertSame(0, $added['medications']);
        $this->assertSame(0, $added['exams']);
        $this->assertSame(0, $added['bilan_types']);
        $this->assertSame($before, $this->countFor('medications', $cabinet));
    }

    /**
     * BelongsToCabinet's creating hook rewrites cabinet_id to the *authenticated*
     * user's cabinet. Provisioning through the models would therefore file a new
     * clinic's catalogue under the admin's own cabinet.
     */
    public function test_the_catalogue_is_filed_under_the_target_cabinet_not_the_actor(): void
    {
        $service = app(CabinetProvisioningService::class);
        $actor = $service->provision($this->registrationPayload('actor@example.test'));
        $actorCabinet = $actor->cabinet;

        $actorBefore = $this->countFor('exams', $actorCabinet);

        $this->actingAs($actor);
        $target = Cabinet::query()->create([
            'name' => 'Cabinet cible',
            'status' => $actorCabinet->status,
        ]);
        app(CabinetCatalogueProvisioner::class)->provisionFor($target);

        $this->assertGreaterThan(0, $this->countFor('exams', $target));
        $this->assertSame(
            $actorBefore,
            $this->countFor('exams', $actorCabinet),
            "the target cabinet's catalogue was written onto the signed-in actor's cabinet",
        );
    }

    public function test_lab_examinations_resolve_to_the_lab_grouping(): void
    {
        $cabinet = app(CabinetProvisioningService::class)
            ->provision($this->registrationPayload('grouping@example.test'))
            ->cabinet;

        // Several groupings share the "labo" category, so a naive lookup files
        // every lab exam under whichever was created last.
        $this->assertSame(
            0,
            (int) DB::table('exams')
                ->where('cabinet_id', $cabinet->getKey())
                ->whereIn('category', ['Bilan lipidique', 'Bilan rénal'])
                ->count(),
            'lab exams were filed under a specific panel instead of the Labo grouping',
        );

        $this->assertGreaterThan(
            0,
            (int) DB::table('exams')
                ->where('cabinet_id', $cabinet->getKey())
                ->where('category', 'Cardio')
                ->count(),
            'the cabinet cannot order any cardiology examination',
        );
    }
}
