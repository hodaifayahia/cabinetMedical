<?php

namespace Tests\Feature\Configuration;

use App\Enums\CabinetStatus;
use App\Enums\RoleName;
use App\Models\Appointment;
use App\Models\Cabinet;
use App\Models\CabinetSetting;
use App\Models\Consultation;
use App\Models\ConsultationFee;
use App\Models\Expense;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DemoDataTest extends TestCase
{
    use RefreshDatabase;

    private const LOCAL_ORIGIN = 'http://127.0.0.1:43123';

    private const PAGE = self::LOCAL_ORIGIN.'/app/configuration/demo-data';

    private Cabinet $cabinet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        // The installed desktop answers only on its own loopback origin.
        config([
            'app.url' => self::LOCAL_ORIGIN,
            'medismart.runtime.local_url' => self::LOCAL_ORIGIN,
            'medismart.runtime.desktop_supervised' => true,
        ]);
        $this->cabinet = Cabinet::query()->create([
            'name' => 'Cabinet Hamid',
            'status' => CabinetStatus::ACTIVE,
            'activated_at' => now(),
        ]);
        CabinetSetting::current($this->cabinet);
    }

    public function test_the_doctor_fills_the_cabinet_then_removes_only_the_demo_data(): void
    {
        $doctor = $this->member(RoleName::SUPER_ADMINISTRATOR);
        $this->actingAs($doctor);
        $realPatient = Patient::query()->create([
            'first_name' => 'HOUDAIFA',
            'last_name' => 'YAHIA',
            'gender' => 'male',
            'date_of_birth' => '2003-09-16',
        ]);

        $this->desktop('POST')->assertRedirect();

        $this->assertSame(10, Patient::query()->count());
        $this->assertTrue(Patient::query()->where('first_name', 'Karim')->exists());
        $this->assertGreaterThan(10, Consultation::query()->count());
        $this->assertGreaterThan(10, Appointment::query()->count());
        $this->assertGreaterThan(0, Payment::query()->count());
        $this->assertSame(3, Expense::query()->count());
        $this->assertTrue(ConsultationFee::query()->where('label', 'Consultation')->exists());

        $this->desktop('GET')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('configuration/DemoData')
                ->where('hasDemoData', true)
                ->where('counts.patients', 9));

        // Filling twice adds nothing more.
        $consultations = Consultation::query()->count();
        $this->desktop('POST')->assertRedirect();
        $this->assertSame($consultations, Consultation::query()->count());

        $this->desktop('DELETE')->assertRedirect();

        $this->assertSame([$realPatient->getKey()], Patient::withTrashed()->pluck('id')->all());
        $this->assertSame(0, Consultation::query()->count());
        $this->assertSame(0, Appointment::withTrashed()->count());
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, Expense::query()->count());
        $this->assertTrue(ConsultationFee::query()->where('label', 'Consultation')->exists());
    }

    public function test_an_assistant_can_neither_see_nor_use_the_demo_data(): void
    {
        $this->actingAs($this->member(RoleName::ASSISTANT));

        $this->desktop('GET')->assertForbidden();
        $this->desktop('POST')->assertForbidden();
        $this->assertSame(0, Patient::query()->count());
    }

    public function test_the_online_service_never_offers_demo_data(): void
    {
        config(['medismart.runtime.desktop_supervised' => false]);
        $this->actingAs($this->member(RoleName::SUPER_ADMINISTRATOR));

        $this->desktop('POST')->assertForbidden();
        $this->assertSame(0, Patient::query()->count());
    }

    public function test_the_navigation_flag_follows_the_same_rule(): void
    {
        $this->actingAs($this->member(RoleName::SUPER_ADMINISTRATOR));

        $this->desktop('GET')->assertInertia(fn (Assert $page) => $page->where('auth.user.can.manageDemoData', true));
    }

    private function desktop(string $method): TestResponse
    {
        return $this->call($method, self::PAGE, server: ['REMOTE_ADDR' => '127.0.0.1']);
    }

    private function member(RoleName $role): User
    {
        $user = User::factory()->create([
            'cabinet_id' => $this->cabinet->getKey(),
            'cabinet_setting_id' => CabinetSetting::current($this->cabinet)->getKey(),
            'approved_at' => now(),
        ]);
        $user->assignRole($role->value);

        return $user;
    }
}
