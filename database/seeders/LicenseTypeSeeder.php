<?php

namespace Database\Seeders;

use App\Models\LicenseType;
use Illuminate\Database\Seeder;

class LicenseTypeSeeder extends Seeder
{
    public function run(): void
    {
        LicenseType::query()->updateOrCreate(
            ['slug' => 'trial-7-days'],
            ['name' => 'Essai 7 jours', 'duration_days' => 7, 'is_active' => true],
        );

        LicenseType::query()->updateOrCreate(
            ['slug' => 'lifetime'],
            ['name' => 'À vie', 'duration_days' => null, 'is_active' => true],
        );
    }
}
