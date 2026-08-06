<?php

namespace Tests\Feature\Desktop;

use App\Models\CabinetSetting;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductionSeederSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_seed_creates_reference_data_but_no_known_account(): void
    {
        config(['medismart.development.seed_demo_user' => false]);

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, User::query()->count());
        $this->assertGreaterThan(0, Role::query()->count());
        $this->assertSame(1, CabinetSetting::query()->count());
    }
}
