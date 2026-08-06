<?php

namespace Tests\Feature\Settings;

use App\Models\CabinetSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CabinetSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_creates_a_singleton_from_config_defaults(): void
    {
        $this->assertDatabaseCount('cabinet_settings', 0);

        $cabinet = CabinetSetting::current();

        $this->assertDatabaseCount('cabinet_settings', 1);
        $this->assertSame((string) config('clinic.name', config('app.name')), $cabinet->name);
        $this->assertSame((int) config('clinic.appointments.default_duration', 30), $cabinet->default_appointment_duration);
        $this->assertSame((string) config('clinic.currency.code', 'DZD'), $cabinet->currency_code);
    }

    public function test_current_is_idempotent_and_returns_the_same_row(): void
    {
        $first = CabinetSetting::current();
        $second = CabinetSetting::current();

        $this->assertTrue($first->is($second));
        $this->assertDatabaseCount('cabinet_settings', 1);
    }

    public function test_current_returns_the_persisted_row_after_updates(): void
    {
        CabinetSetting::current()->update(['name' => 'Cabinet Dr. Diallo']);

        $this->assertSame('Cabinet Dr. Diallo', CabinetSetting::current()->name);
        $this->assertDatabaseCount('cabinet_settings', 1);
    }

    public function test_cabinet_settings_are_shared_with_the_frontend(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('cabinet.name', CabinetSetting::current()->name)
                ->where('cabinet.currency.code', CabinetSetting::current()->currency_code)
                ->has('cabinet.timezone'),
            );
    }
}
