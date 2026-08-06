<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Models\CabinetSetting;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);
        $this->call(CabinetDoctorSeeder::class);
        $this->call(MedicationSeeder::class);
        $this->call(ConfigurationSeeder::class);
        $this->call(ExamSeeder::class);

        // Materialize the single cabinet settings row from configuration defaults.
        CabinetSetting::current();

        // A packaged/production database must never contain a known-password
        // demo account. The desktop first-run flow creates the sole initial
        // owner; staff accounts are created by an administrator afterwards.
        if (app()->isProduction()
            || ! (bool) config('medismart.development.seed_demo_user', false)) {
            return;
        }

        $user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $user->assignRole(RoleName::SUPER_ADMINISTRATOR->value);
    }
}
