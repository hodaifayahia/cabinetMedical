<?php

namespace Tests\Feature\Cabinet;

use App\Enums\CabinetStatus;
use App\Models\AuditLog;
use App\Models\Cabinet;
use App\Models\User;
use App\Services\Cabinet\CabinetSeatService;
use App\Services\Sync\MobileSyncSettings;
use App\Services\Sync\SyncTransportException;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CabinetSeatServiceTest extends TestCase
{
    use RefreshDatabase;

    private const string ENDPOINT = 'https://online.example.test';

    private Cabinet $cabinet;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(CarbonImmutable::parse('2026-09-01T10:00:00Z'));
        $this->cabinet = $this->cabinetOwnedBy('Doctor@Clinic.test');
    }

    public function test_an_unlinked_installation_cannot_check_online(): void
    {
        $this->assertFalse($this->seats()->canCheckOnline());
        $this->assertFalse($this->seats()->canCheckOnline($this->cabinet));

        $this->expectException(SyncTransportException::class);
        $this->seats()->refresh();
    }

    public function test_a_link_bound_to_a_cabinet_checks_only_for_that_cabinet(): void
    {
        $other = $this->cabinetOwnedBy('other@clinic.test');
        $this->link($this->cabinet->getKey());

        $this->assertTrue($this->seats()->canCheckOnline());
        $this->assertTrue($this->seats()->canCheckOnline($this->cabinet));
        $this->assertFalse($this->seats()->canCheckOnline($other));
    }

    public function test_refreshing_stores_the_granted_limit_and_audits_the_change(): void
    {
        $this->link($this->cabinet->getKey());
        $this->fakeAllowance(['seat_limit' => 5, 'owner_email' => '  doctor@clinic.TEST ']);

        $cabinet = $this->seats()->refresh($this->cabinet);

        $this->assertTrue($cabinet->is($this->cabinet));
        $this->assertSame(5, $this->cabinet->seat_limit);
        $this->assertTrue($this->cabinet->seat_limit_synced_at->equalTo(now()));
        $audit = AuditLog::query()->where('action', 'cabinet.seats_synced')->firstOrFail();
        $this->assertSame(Cabinet::DEFAULT_SEATS, $audit->metadata['previous_seat_limit']);
        $this->assertSame(5, $audit->metadata['seat_limit']);
    }

    public function test_an_unchanged_limit_refreshes_the_sync_time_without_an_audit_entry(): void
    {
        $this->link($this->cabinet->getKey());
        $this->fakeAllowance(['seat_limit' => Cabinet::DEFAULT_SEATS, 'owner_email' => 'doctor@clinic.test']);

        $this->seats()->refresh();

        $this->assertNotNull($this->cabinet->fresh()->seat_limit_synced_at);
        $this->assertSame(0, AuditLog::query()->where('action', 'cabinet.seats_synced')->count());
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function unreadableAllowances(): array
    {
        return [
            'zero' => [['seat_limit' => 0, 'owner_email' => 'doctor@clinic.test']],
            'above the grantable maximum' => [['seat_limit' => Cabinet::MAX_GRANTABLE_SEATS + 1, 'owner_email' => 'doctor@clinic.test']],
            'numeric string' => [['seat_limit' => '5', 'owner_email' => 'doctor@clinic.test']],
            'missing' => [['owner_email' => 'doctor@clinic.test']],
        ];
    }

    /** @param array<string, mixed> $allowance */
    #[DataProvider('unreadableAllowances')]
    public function test_an_unreadable_allowance_changes_nothing(array $allowance): void
    {
        $this->link($this->cabinet->getKey());
        $this->fakeAllowance($allowance);

        try {
            $this->seats()->refresh($this->cabinet);
            $this->fail('An unreadable allowance was stored.');
        } catch (SyncTransportException $exception) {
            $this->assertStringContainsString('illisible', $exception->getMessage());
            $this->assertNull($exception->reason);
        }

        $this->assertSame(Cabinet::DEFAULT_SEATS, $this->cabinet->fresh()->seat_limit);
        $this->assertNull($this->cabinet->fresh()->seat_limit_synced_at);
    }

    public function test_the_maximum_grantable_allowance_is_accepted(): void
    {
        $this->link($this->cabinet->getKey());
        $this->fakeAllowance(['seat_limit' => Cabinet::MAX_GRANTABLE_SEATS, 'owner_email' => 'doctor@clinic.test']);

        $this->assertSame(Cabinet::MAX_GRANTABLE_SEATS, $this->seats()->refresh()->seat_limit);
    }

    /** @return array<string, array{mixed}> */
    public static function unmatchedOwners(): array
    {
        return [
            'unknown address' => ['stranger@clinic.test'],
            'blank' => ['   '],
            'missing' => [null],
        ];
    }

    #[DataProvider('unmatchedOwners')]
    public function test_an_allowance_that_matches_no_local_cabinet_is_refused(mixed $ownerEmail): void
    {
        $this->link();
        $this->fakeAllowance(array_filter(['seat_limit' => 9, 'owner_email' => $ownerEmail], static fn ($value) => $value !== null));

        try {
            $this->seats()->refresh();
            $this->fail('An allowance for an unknown cabinet was stored.');
        } catch (SyncTransportException $exception) {
            $this->assertSame(SyncTransportException::REASON_CABINET_MISMATCH, $exception->reason);
        }

        $this->assertSame(Cabinet::DEFAULT_SEATS, $this->cabinet->fresh()->seat_limit);
    }

    public function test_another_practices_allowance_never_reaches_the_expected_cabinet(): void
    {
        $other = $this->cabinetOwnedBy('other@clinic.test');
        $this->link();
        $this->fakeAllowance(['seat_limit' => 9, 'owner_email' => 'other@clinic.test']);

        try {
            $this->seats()->refresh($this->cabinet);
            $this->fail('Another practice\'s seats were accepted.');
        } catch (SyncTransportException $exception) {
            $this->assertSame(SyncTransportException::REASON_CABINET_MISMATCH, $exception->reason);
        }

        $this->assertSame(Cabinet::DEFAULT_SEATS, $this->cabinet->fresh()->seat_limit);
        $this->assertSame(Cabinet::DEFAULT_SEATS, $other->fresh()->seat_limit);
    }

    public function test_a_link_bound_to_one_cabinet_refuses_an_allowance_for_another(): void
    {
        $other = $this->cabinetOwnedBy('other@clinic.test');
        $this->link($this->cabinet->getKey());
        $this->fakeAllowance(['seat_limit' => 9, 'owner_email' => 'other@clinic.test']);

        $this->expectException(SyncTransportException::class);

        try {
            $this->seats()->refresh();
        } finally {
            $this->assertSame(Cabinet::DEFAULT_SEATS, $other->fresh()->seat_limit);
        }
    }

    public function test_the_summary_reports_used_and_remaining_seats(): void
    {
        User::factory()->create(['cabinet_id' => $this->cabinet->getKey()]);

        $summary = $this->seats()->summary($this->cabinet);

        $this->assertSame([
            'used' => 2,
            'limit' => Cabinet::DEFAULT_SEATS,
            'remaining' => 0,
            'canCheckOnline' => false,
            'syncedAt' => null,
        ], $summary);
    }

    public function test_the_summary_never_reports_negative_remaining_seats(): void
    {
        User::factory()->count(3)->create(['cabinet_id' => $this->cabinet->getKey()]);
        $this->cabinet->forceFill(['seat_limit_synced_at' => now()])->save();
        $this->link($this->cabinet->getKey());

        $summary = $this->seats()->summary($this->cabinet->fresh());

        $this->assertSame(4, $summary['used']);
        $this->assertSame(0, $summary['remaining']);
        $this->assertTrue($summary['canCheckOnline']);
        $this->assertSame(now()->toIso8601String(), $summary['syncedAt']);
    }

    private function seats(): CabinetSeatService
    {
        return app(CabinetSeatService::class);
    }

    private function link(?int $cabinetId = null): void
    {
        app(MobileSyncSettings::class)->configure(self::ENDPOINT, '1|token', cabinetId: $cabinetId);
    }

    /** @param array<string, mixed> $allowance */
    private function fakeAllowance(array $allowance): void
    {
        Http::fake([self::ENDPOINT.'/api/v1/cabinet/seats' => Http::response(['data' => $allowance])]);
    }

    private function cabinetOwnedBy(string $email): Cabinet
    {
        $cabinet = Cabinet::query()->create([
            'name' => 'Cabinet '.$email,
            'status' => CabinetStatus::ACTIVE,
            'activated_at' => now(),
        ]);
        $owner = User::factory()->create([
            'email' => $email,
            'cabinet_id' => $cabinet->getKey(),
            'approved_at' => now(),
        ]);
        $cabinet->forceFill(['owner_user_id' => $owner->getKey()])->save();

        return $cabinet->refresh();
    }
}
