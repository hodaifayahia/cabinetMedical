<?php

namespace Database\Seeders;

use App\Models\LandingSetting;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Materialises the landing texts a platform admin is expected to edit.
 *
 * Without these rows the admin panel opens on an empty table, leaving the
 * admin to guess which keys exist before they can set the contact block.
 */
class LandingSettingSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        LandingSetting::ensureContactDefaults();
    }
}
