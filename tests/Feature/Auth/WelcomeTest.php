<?php

namespace Tests\Feature\Auth;

use App\Enums\CabinetStatus;
use App\Models\Cabinet;
use App\Models\CabinetSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WelcomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_landing_page_offers_cabinet_registration(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Welcome')
                ->where('canRegister', true));
    }

    public function test_registration_remains_available_when_accounts_exist(): void
    {
        User::factory()->create();

        // Multi-cabinet: registration is always open so new cabinets can sign up.
        $this->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Welcome')
                ->where('canRegister', true));
    }

    public function test_guest_landing_page_never_exposes_an_existing_cabinets_identity(): void
    {
        $cabinet = Cabinet::query()->create([
            'name' => 'Cabinet priv\u00e9 Atlas',
            'status' => CabinetStatus::ACTIVE,
        ]);
        CabinetSetting::query()->create([
            'cabinet_id' => $cabinet->getKey(),
            'name' => 'Cabinet priv\u00e9 Atlas',
            'phone' => '0555 00 00 00',
            'email' => 'secret@example.test',
            'logo_path' => 'cabinet/secret-logo.png',
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Welcome')
                ->where('name', (string) config('app.name', 'DrClickDz'))
                ->where('cabinet.name', (string) config('app.name', 'DrClickDz'))
                ->where('cabinet.phone', null)
                ->where('cabinet.email', null)
                ->where('cabinet.address', null)
                ->where('cabinet.city', null)
                ->where('cabinet.logo_path', null)
                ->where('cabinet.logo_url', null));

        $this->assertDatabaseCount('cabinet_settings', 1);
    }
}
