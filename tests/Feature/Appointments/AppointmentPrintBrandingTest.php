<?php

namespace Tests\Feature\Appointments;

use App\Enums\AppointmentStatus;
use App\Enums\RoleName;
use App\Models\Appointment;
use App\Models\CabinetSetting;
use App\Models\DoctorProfile;
use App\Models\Patient;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AppointmentPrintBrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_print_view_applies_clinic_branding_and_exact_filters(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('public');
        Storage::disk('public')->put('clinic/print-logo.png', 'safe-logo');

        $administrator = User::factory()->create(['name' => 'Dr Nadia Amrane']);
        $administrator->assignRole(RoleName::ADMINISTRATOR->value);
        DoctorProfile::factory()->for($administrator)->create([
            'specialty' => 'Médecine générale',
            'professional_identifier' => 'ORD-PRINT-1',
        ]);
        CabinetSetting::current()->update([
            'name' => 'Clinique Atlas الشفاء',
            'phone' => '021 11 22 33',
            'email' => 'contact@atlas.test',
            'address' => '12 rue Didouche Mourad',
            'city' => 'Alger',
            'prescription_footer' => 'Sur rendez-vous',
            'logo_path' => 'clinic/print-logo.png',
        ]);

        $day = CarbonImmutable::parse('2026-08-12 09:00:00');
        $included = Patient::factory()->create([
            'first_name' => 'Nadia',
            'last_name' => 'Benali',
            'patient_number' => 'PAT-PRINT-1',
        ]);
        $excluded = Patient::factory()->create([
            'first_name' => 'Samir',
            'last_name' => 'Haddad',
            'patient_number' => 'PAT-PRINT-2',
        ]);
        Appointment::factory()->for($included)->create([
            'appointment_date' => $day->toDateString(),
            'starts_at' => $day,
            'ends_at' => $day->addMinutes(30),
            'status' => AppointmentStatus::CONFIRMED,
            'prestation' => 'Consultation générale',
        ]);
        Appointment::factory()->for($excluded)->create([
            'appointment_date' => $day->addDay()->toDateString(),
            'starts_at' => $day->addDay(),
            'ends_at' => $day->addDay()->addMinutes(30),
            'status' => AppointmentStatus::CONFIRMED,
        ]);

        $this->actingAs($administrator)
            ->get(route('app.appointments.print', [
                'date' => $day->toDateString(),
                'status' => AppointmentStatus::CONFIRMED->value,
            ]))
            ->assertOk()
            ->assertSee('Clinique Atlas الشفاء')
            ->assertSee('Dr Nadia Amrane')
            ->assertSee('Médecine générale')
            ->assertSee('ORD-PRINT-1')
            ->assertSee('contact@atlas.test')
            ->assertSee('Sur rendez-vous')
            ->assertSee(Storage::disk('public')->url('clinic/print-logo.png'))
            ->assertSee('Nadia Benali')
            ->assertSee('PAT-PRINT-1')
            ->assertSee('Consultation générale')
            ->assertSee('Confirmé')
            ->assertDontSee('Samir Haddad')
            ->assertDontSee('PAT-PRINT-2');
    }

    public function test_unauthenticated_user_cannot_open_the_print_view(): void
    {
        $this->get(route('app.appointments.print'))
            ->assertRedirect(route('login'));
    }
}
