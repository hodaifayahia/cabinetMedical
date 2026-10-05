<?php

namespace Tests\Feature\Hub;

use App\Enums\CabinetStatus;
use App\Models\Cabinet;
use App\Models\HubAuthority;
use App\Models\User;
use App\Services\Hub\HubMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Direct coverage of the HubMode decision table: the middleware, the health
 * endpoint and the console commands all read these answers.
 */
class HubModeServiceTest extends TestCase
{
    use RefreshDatabase;

    private const string HUB_ID = 'hub-unit-0001';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'hub.enabled' => false,
            'hub.id' => null,
            'hub.cabinet_id' => null,
            'hub.hostname' => null,
            'hub.tls_spki_sha256' => null,
            'hub.protocol_version' => 1,
        ]);
    }

    public function test_a_hosted_installation_is_neither_declared_nor_enabled(): void
    {
        $hub = $this->hub();

        $this->assertFalse($hub->isDeclared());
        $this->assertFalse($hub->isEnabled());
        $this->assertNull($hub->misconfigurationReason());
        $this->assertNull($hub->advertisement());
        $this->assertNull($hub->authority());
        $this->assertNull($hub->authorityEpoch());
    }

    public function test_a_hosted_installation_serves_every_user(): void
    {
        $this->assertTrue($this->hub()->serves(User::factory()->create()));
        $this->assertTrue($this->hub()->serves(User::factory()->create(['is_platform_admin' => true])));
    }

    /** @return array<string, array{mixed, int|null}> */
    public static function cabinetBindings(): array
    {
        return [
            'positive int' => [12, 12],
            'numeric string' => ['12', 12],
            'padded numeric string' => [' 12 ', 12],
            'zero' => [0, null],
            'negative' => [-4, null],
            'zero string' => ['0', null],
            'non numeric' => ['twelve', null],
            'decimal string' => ['1.5', null],
            'empty' => ['', null],
            'null' => [null, null],
        ];
    }

    #[DataProvider('cabinetBindings')]
    public function test_the_cabinet_binding_accepts_only_positive_integers(mixed $configured, ?int $expected): void
    {
        config(['hub.cabinet_id' => $configured]);

        $this->assertSame($expected, $this->hub()->boundCabinetId());
    }

    public function test_identity_strings_are_trimmed_and_blank_values_are_null(): void
    {
        config([
            'hub.id' => '  '.self::HUB_ID.'  ',
            'hub.hostname' => '   ',
            'hub.tls_spki_sha256' => 42,
            'hub.protocol_version' => '3',
        ]);

        $this->assertSame(self::HUB_ID, $this->hub()->hubId());
        $this->assertNull($this->hub()->hostname());
        $this->assertNull($this->hub()->tlsSpkiSha256());
        $this->assertSame(3, $this->hub()->protocolVersion());
    }

    public function test_a_declared_hub_without_an_id_is_misconfigured_and_not_enabled(): void
    {
        config(['hub.enabled' => true, 'hub.cabinet_id' => 1]);

        $this->assertTrue($this->hub()->isDeclared());
        $this->assertFalse($this->hub()->isEnabled());
        $this->assertSame(HubMode::MISCONFIGURED_REASON_NO_ID, $this->hub()->misconfigurationReason());
    }

    public function test_a_declared_hub_without_a_cabinet_is_misconfigured(): void
    {
        config(['hub.enabled' => true, 'hub.id' => self::HUB_ID]);

        $this->assertFalse($this->hub()->isEnabled());
        $this->assertSame(HubMode::MISCONFIGURED_REASON_NO_CABINET, $this->hub()->misconfigurationReason());
    }

    public function test_a_hub_bound_to_an_unknown_cabinet_is_misconfigured(): void
    {
        config(['hub.enabled' => true, 'hub.id' => self::HUB_ID, 'hub.cabinet_id' => 999]);

        $this->assertTrue($this->hub()->isEnabled());
        $this->assertNull($this->hub()->boundCabinet());
        $this->assertSame(HubMode::MISCONFIGURED_REASON_CABINET_UNKNOWN, $this->hub()->misconfigurationReason());
    }

    public function test_an_unadopted_hub_is_misconfigured(): void
    {
        $cabinet = $this->declaredHub();

        $this->assertTrue($cabinet->is($this->hub()->boundCabinet()));
        $this->assertSame(HubMode::MISCONFIGURED_REASON_NOT_ADOPTED, $this->hub()->misconfigurationReason());
        $this->assertNull($this->hub()->authorityEpoch());
    }

    public function test_a_hub_whose_authority_belongs_to_another_hub_is_displaced(): void
    {
        $cabinet = $this->declaredHub();
        $this->authority($cabinet, 'hub-other-0002', 3);

        $this->assertSame(HubMode::MISCONFIGURED_REASON_DISPLACED, $this->hub()->misconfigurationReason());
        $this->assertSame(3, $this->hub()->authorityEpoch());
    }

    public function test_an_adopted_hub_is_ready(): void
    {
        $cabinet = $this->declaredHub();
        $this->authority($cabinet, self::HUB_ID, 2);

        $this->assertNull($this->hub()->misconfigurationReason());
        $this->assertSame(2, $this->hub()->authorityEpoch());
        $this->assertTrue($this->hub()->authority()?->isHeldBy(self::HUB_ID));
    }

    public function test_an_authority_record_is_never_held_by_an_anonymous_hub(): void
    {
        $cabinet = $this->declaredHub();
        $authority = $this->authority($cabinet, self::HUB_ID, 1);

        $this->assertFalse($authority->isHeldBy(null));
        $this->assertFalse($authority->isHeldBy('hub-unit-000'));
    }

    public function test_an_enabled_hub_serves_only_members_of_its_cabinet(): void
    {
        $cabinet = $this->declaredHub();
        $other = $this->cabinet();

        $member = User::factory()->create(['cabinet_id' => $cabinet->getKey()]);
        $outsider = User::factory()->create(['cabinet_id' => $other->getKey()]);
        $admin = User::factory()->create(['is_platform_admin' => true]);

        $this->assertTrue($this->hub()->serves($member));
        $this->assertFalse($this->hub()->serves($outsider));
        $this->assertFalse($this->hub()->serves($admin));
    }

    public function test_the_advertisement_carries_identity_and_readiness_only(): void
    {
        $cabinet = $this->declaredHub();
        config([
            'hub.hostname' => 'hub-cabinet.drclick.local',
            'hub.tls_spki_sha256' => str_repeat('ab', 32),
        ]);
        $this->authority($cabinet, self::HUB_ID, 5);

        $this->assertSame([
            'mode' => 'hub',
            'protocol_version' => 1,
            'hub_id' => self::HUB_ID,
            'cabinet_id' => $cabinet->getKey(),
            'hostname' => 'hub-cabinet.drclick.local',
            'tls_spki_sha256' => str_repeat('ab', 32),
            'authority_epoch' => 5,
            'ready' => true,
            'reason' => null,
        ], $this->hub()->advertisement());
    }

    public function test_a_broken_hub_still_advertises_why_it_is_not_ready(): void
    {
        config(['hub.enabled' => true]);

        $advertisement = $this->hub()->advertisement();

        $this->assertIsArray($advertisement);
        $this->assertFalse($advertisement['ready']);
        $this->assertSame(HubMode::MISCONFIGURED_REASON_NO_ID, $advertisement['reason']);
        $this->assertNull($advertisement['authority_epoch']);
    }

    private function hub(): HubMode
    {
        return app(HubMode::class);
    }

    private function declaredHub(): Cabinet
    {
        $cabinet = $this->cabinet();
        config([
            'hub.enabled' => true,
            'hub.id' => self::HUB_ID,
            'hub.cabinet_id' => (string) $cabinet->getKey(),
        ]);

        return $cabinet;
    }

    private function cabinet(): Cabinet
    {
        return Cabinet::query()->create([
            'name' => 'Cabinet '.fake()->unique()->lastName(),
            'status' => CabinetStatus::ACTIVE,
            'activated_at' => now(),
        ]);
    }

    private function authority(Cabinet $cabinet, string $hubId, int $epoch): HubAuthority
    {
        return HubAuthority::query()->create([
            'cabinet_id' => $cabinet->getKey(),
            'hub_id' => $hubId,
            'authority_epoch' => $epoch,
            'adopted_at' => now(),
            'adopted_reason' => HubAuthority::REASON_PROVISIONED,
        ]);
    }
}
