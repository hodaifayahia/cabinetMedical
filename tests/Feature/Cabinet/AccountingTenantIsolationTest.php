<?php

namespace Tests\Feature\Cabinet;

use App\Enums\CabinetStatus;
use App\Models\AccountingSetting;
use App\Models\Cabinet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountingTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_members_of_one_cabinet_share_accounting_settings_without_leaking_to_another(): void
    {
        $cabinetA = $this->cabinet('Cabinet A');
        $cabinetB = $this->cabinet('Cabinet B');
        $firstMember = User::factory()->create(['cabinet_id' => $cabinetA->getKey()]);
        $secondMember = User::factory()->create(['cabinet_id' => $cabinetA->getKey()]);
        $otherMember = User::factory()->create(['cabinet_id' => $cabinetB->getKey()]);

        $this->actingAs($firstMember);
        AccountingSetting::current()->update([
            'currency' => 'DZD',
            'receipt_prefix' => 'CAB-A-',
        ]);

        $this->actingAs($secondMember);
        $this->assertSame('DZD', AccountingSetting::current()->currency);
        $this->assertSame('CAB-A-', AccountingSetting::current()->receipt_prefix);

        $this->actingAs($otherMember);
        $this->assertSame('DA', AccountingSetting::current()->currency);
        AccountingSetting::current()->update(['receipt_prefix' => 'CAB-B-']);

        $this->actingAs($firstMember);
        $this->assertSame('CAB-A-', AccountingSetting::current()->receipt_prefix);
        $this->assertSame(2, AccountingSetting::withoutCabinetScope()->count());
    }

    private function cabinet(string $name): Cabinet
    {
        return Cabinet::query()->create([
            'name' => $name,
            'status' => CabinetStatus::ACTIVE,
            'activated_at' => now(),
        ]);
    }
}
