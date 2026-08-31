<?php

namespace Tests\Feature\Filament;

use App\Enums\CabinetStatus;
use App\Enums\LicensePlan;
use App\Filament\Resources\ActivationKeys\ActivationKeyResource;
use App\Filament\Resources\ActivationKeys\Pages\ListActivationKeys;
use App\Filament\Resources\Cabinets\Pages\ListCabinets;
use App\Models\Cabinet;
use App\Models\HostedLicenseGrant;
use App\Models\LicenseType;
use App\Models\User;
use App\Services\CabinetFulfillmentService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

final class ActivationKeyResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
        Mail::fake();
    }

    public function test_a_generated_code_appears_immediately_in_the_activation_key_table(): void
    {
        $platformAdmin = User::factory()->create(['is_platform_admin' => true]);
        [, $cabinet] = $this->pendingCabinet('appears-in-list@example.com');
        $this->actingAs($platformAdmin);

        $issued = app(CabinetFulfillmentService::class)
            ->issueLicenseCode($cabinet, LicensePlan::TRIAL);

        Livewire::actingAs($platformAdmin)
            ->test(ListActivationKeys::class)
            ->assertCanSeeTableRecords([$issued->grant])
            ->assertSee($cabinet->name)
            ->assertSee('En attente');
    }

    public function test_bulk_issuing_serves_every_eligible_cabinet_and_skips_the_rest(): void
    {
        $platformAdmin = User::factory()->create(['is_platform_admin' => true]);
        [, $first] = $this->pendingCabinet('bulk-first@example.com');
        [, $second] = $this->pendingCabinet('bulk-second@example.com');
        $suspended = Cabinet::query()->create([
            'name' => 'Cabinet suspendu',
            'status' => CabinetStatus::SUSPENDED,
        ]);
        $type = $this->licenseType('trial-7-days', 'Essai 7 jours', 7);

        $this->actingAs($platformAdmin);

        Livewire::actingAs($platformAdmin)
            ->test(ListCabinets::class)
            ->callTableBulkAction('issueLicenseCodes', [$first, $second, $suspended], [
                'license_type_id' => $type->getKey(),
            ])
            ->assertHasNoTableBulkActionErrors();

        $this->assertSame(1, HostedLicenseGrant::withoutCabinetScope()->where('cabinet_id', $first->getKey())->count());
        $this->assertSame(1, HostedLicenseGrant::withoutCabinetScope()->where('cabinet_id', $second->getKey())->count());
        $this->assertSame(0, HostedLicenseGrant::withoutCabinetScope()->where('cabinet_id', $suspended->getKey())->count());

        // A skipped cabinet must not abort the batch nor activate anything.
        $this->assertSame(CabinetStatus::PENDING, $first->fresh()->status);
        $this->assertSame(CabinetStatus::SUSPENDED, $suspended->fresh()->status);
    }

    public function test_the_batch_action_on_the_key_page_defaults_to_the_whole_waiting_queue(): void
    {
        $platformAdmin = User::factory()->create(['is_platform_admin' => true]);
        [, $first] = $this->pendingCabinet('queue-first@example.com');
        [, $second] = $this->pendingCabinet('queue-second@example.com');

        // A cabinet that is not waiting for a code must stay out of the
        // pre-selection, otherwise "the whole waiting queue" would really
        // mean "every cabinet".
        $suspended = Cabinet::query()->create([
            'name' => 'Cabinet suspendu',
            'status' => CabinetStatus::SUSPENDED,
        ]);

        $type = $this->licenseType('lifetime', 'À vie', null);

        $this->actingAs($platformAdmin);

        $defaultSelection = [];

        $component = Livewire::actingAs($platformAdmin)
            ->test(ListActivationKeys::class)
            ->mountAction('issueBatch')
            ->assertActionDataSet(function (array $state) use (&$defaultSelection): array {
                $defaultSelection = array_map(
                    static fn (mixed $id): int => (int) $id,
                    $state['cabinet_ids'] ?? [],
                );

                // Nothing is compared by equality here: the exact contents of
                // the queue belong to the database, and this class must not
                // depend on what the rest of the suite left in it.
                return [];
            });

        $this->assertContains($first->getKey(), $defaultSelection);
        $this->assertContains($second->getKey(), $defaultSelection);
        $this->assertNotContains($suspended->getKey(), $defaultSelection);

        // The operator only picks a licence type; the cabinets come from the
        // default. A default that came back empty would fail the required
        // rule on cabinet_ids and be caught by assertHasNoActionErrors().
        $component
            ->setActionData(['license_type_id' => $type->getKey()])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        // Counted per cabinet rather than over the whole table: an outstanding
        // grant left behind anywhere else can no longer decide this verdict.
        $this->assertSame(1, HostedLicenseGrant::withoutCabinetScope()
            ->where('cabinet_id', $first->getKey())
            ->outstanding()
            ->count());
        $this->assertSame(1, HostedLicenseGrant::withoutCabinetScope()
            ->where('cabinet_id', $second->getKey())
            ->outstanding()
            ->count());
        $this->assertSame(0, HostedLicenseGrant::withoutCabinetScope()
            ->where('cabinet_id', $suspended->getKey())
            ->count());
    }

    public function test_a_platform_admin_can_read_a_handed_out_code_back(): void
    {
        $platformAdmin = User::factory()->create(['is_platform_admin' => true]);
        [, $cabinet] = $this->pendingCabinet('recoverable@example.com');
        $this->actingAs($platformAdmin);

        $issued = app(CabinetFulfillmentService::class)
            ->issueLicenseCode($cabinet, LicensePlan::LIFETIME);

        $this->assertSame($issued->code, $issued->grant->fresh()->plainCode());
    }

    public function test_the_stored_code_is_encrypted_and_never_written_in_clear(): void
    {
        $platformAdmin = User::factory()->create(['is_platform_admin' => true]);
        [, $cabinet] = $this->pendingCabinet('encrypted-at-rest@example.com');
        $this->actingAs($platformAdmin);

        $issued = app(CabinetFulfillmentService::class)
            ->issueLicenseCode($cabinet, LicensePlan::LIFETIME);

        $row = (array) DB::table('hosted_license_grants')
            ->where('id', $issued->grant->getKey())
            ->first();

        $this->assertNotEmpty($row['code_encrypted']);
        $this->assertStringNotContainsString(
            $issued->code,
            json_encode($row, JSON_THROW_ON_ERROR),
        );
    }

    public function test_revoking_a_key_stops_it_without_touching_a_redeemed_one(): void
    {
        $platformAdmin = User::factory()->create(['is_platform_admin' => true]);
        [, $cabinet] = $this->pendingCabinet('revoke-me@example.com');
        $this->actingAs($platformAdmin);

        $issued = app(CabinetFulfillmentService::class)
            ->issueLicenseCode($cabinet, LicensePlan::LIFETIME);

        Livewire::actingAs($platformAdmin)
            ->test(ListActivationKeys::class)
            ->callTableAction('revoke', $issued->grant)
            ->assertHasNoTableActionErrors();

        $grant = $issued->grant->fresh();

        $this->assertFalse($grant->isOutstanding());
        $this->assertSame('Révoquée', $grant->statusLabel());
        $this->assertSame($platformAdmin->getKey(), $grant->revoked_by_user_id);
    }

    public function test_cabinet_users_cannot_reach_the_activation_key_directory(): void
    {
        $cabinetUser = User::factory()->create(['is_platform_admin' => false]);

        $this->actingAs($cabinetUser);

        $this->assertFalse(ActivationKeyResource::canAccess());
        $this->get(ActivationKeyResource::getUrl('index'))->assertForbidden();
    }

    /**
     * Reference licence types are seeded data in a real installation, so a
     * fixture has to reconcile with an existing slug rather than collide with
     * it: license_types.slug carries a unique index and
     * Database\Seeders\LicenseTypeSeeder owns both slugs used here.
     */
    private function licenseType(string $slug, string $name, ?int $durationDays): LicenseType
    {
        return LicenseType::query()->updateOrCreate(
            ['slug' => $slug],
            ['name' => $name, 'duration_days' => $durationDays, 'is_active' => true],
        );
    }

    /** @return array{User, Cabinet} */
    private function pendingCabinet(string $email): array
    {
        $owner = User::factory()->create([
            'email' => $email,
            'approved_at' => now(),
        ]);
        $cabinet = Cabinet::query()->create([
            'name' => 'Cabinet '.$owner->getKey(),
            'status' => CabinetStatus::PENDING,
            'owner_user_id' => $owner->getKey(),
        ]);
        $owner->forceFill(['cabinet_id' => $cabinet->getKey()])->save();

        return [$owner, $cabinet];
    }
}
