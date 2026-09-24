<?php

namespace Tests\Feature\Appointments;

use App\Enums\AppointmentStatus;
use App\Enums\RoleName;
use App\Models\Appointment;
use App\Models\CabinetSetting;
use App\Models\Consultation;
use App\Models\Patient;
use App\Models\PatientRecall;
use App\Models\User;
use App\Services\Communication\PatientMessages;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RemindersTest extends TestCase
{
    use RefreshDatabase;

    private User $assistant;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->assistant = User::factory()->create();
        $this->assistant->assignRole(RoleName::ASSISTANT->value);
        $this->actingAs($this->assistant);
        CabinetSetting::current()->update(['name' => 'Dr Haddad', 'phone' => '021 55 66 77']);
        $this->patient = Patient::factory()->create([
            'first_name' => 'Amina',
            'last_name' => 'Kaci',
            'phone' => '0555 12 34 56',
        ]);
    }

    public function test_phone_numbers_are_normalised_for_whatsapp(): void
    {
        $messages = app(PatientMessages::class);

        $this->assertSame('213555123456', $messages->internationalPhone('0555 12 34 56'));
        $this->assertSame('213661234567', $messages->internationalPhone('+213 661 23 45 67'));
        $this->assertSame('213771234567', $messages->internationalPhone('00213771234567'));
        $this->assertSame('213555123456', $messages->internationalPhone('555123456'));
        $this->assertSame('33612345678', $messages->internationalPhone('+33 6 12 34 56 78'));
        $this->assertNull($messages->internationalPhone('12'));
        $this->assertNull($messages->internationalPhone(null));
    }

    public function test_tomorrows_appointments_come_with_a_ready_message_and_can_be_marked_reminded(): void
    {
        $appointment = $this->appointment('2026-06-16 09:30:00', AppointmentStatus::SCHEDULED);
        $this->appointment('2026-06-16 11:00:00', AppointmentStatus::CANCELLED);
        $this->appointment('2026-06-17 09:00:00', AppointmentStatus::CONFIRMED);

        $this->get(route('app.reminders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('reminders/Index')
                ->where('date', '2026-06-16')
                ->has('appointments', 1)
                ->where('appointments.0.time', '09:30')
                ->where('appointments.0.reminded_at', null)
                ->where('appointments.0.links.whatsapp', fn (string $url): bool => str_starts_with($url, 'https://wa.me/213555123456?text='))
                ->where('appointments.0.links.message', fn (string $message): bool => str_contains($message, 'Amina Kaci')
                    && str_contains($message, 'cabinet Dr Haddad')
                    && str_contains($message, 'mardi 16 juin')
                    && str_contains($message, '09:30')
                    && str_contains($message, '021 55 66 77'))
                ->where('appointments.0.links.sms', fn (string $url): bool => str_starts_with($url, 'sms:0555123456?body='))
            );

        $this->post(route('app.reminders.appointments.mark', $appointment))->assertRedirect();
        $this->assertNotNull($appointment->refresh()->getAttribute('reminded_at'));

        $this->get(route('app.reminders.index', ['date' => '2026-06-17']))
            ->assertInertia(fn (Assert $page) => $page->has('appointments', 1));
    }

    public function test_recalls_are_programmed_listed_when_due_and_closed(): void
    {
        $consultation = Consultation::query()->create([
            'patient_id' => $this->patient->getKey(),
            'consulted_at' => now(),
            'status' => 'completed',
            'created_by' => $this->assistant->getKey(),
        ]);

        $this->post(route('app.patients.recalls.store', $this->patient), [
            'due_on' => '2026-07-01',
            'reason' => 'Contrôle HbA1c',
            'consultation_id' => $consultation->getKey(),
        ])->assertSessionHasNoErrors();
        $this->post(route('app.patients.recalls.store', $this->patient), [
            'due_on' => '2026-12-01',
            'reason' => 'Bilan annuel',
        ])->assertSessionHasNoErrors();
        $this->post(route('app.patients.recalls.store', $this->patient), [
            'due_on' => '2026-01-01',
            'reason' => 'Dans le passé',
        ])->assertSessionHasErrors('due_on');

        $recall = PatientRecall::query()->where('reason', 'Contrôle HbA1c')->firstOrFail();
        $this->assertSame($consultation->getKey(), $recall->getAttribute('consultation_id'));

        // Shown on the patient file with the safety list.
        $this->get(route('app.patients.show', $this->patient))
            ->assertInertia(fn (Assert $page) => $page
                ->has('safety.recalls', 2)
                ->where('safety.recalls.0.reason', 'Contrôle HbA1c')
            );

        // Only the one due within 30 days is on the reminders list.
        $this->get(route('app.reminders.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('recalls', 1)
                ->where('recalls.0.days', 16)
                ->where('recalls.0.links.message', fn (string $message): bool => str_contains($message, 'Contrôle HbA1c'))
            );

        $this->patch(route('app.recalls.update', $recall), ['action' => 'contacted'])->assertRedirect();
        $this->assertNotNull($recall->refresh()->contacted_at);

        $this->patch(route('app.recalls.update', $recall), ['action' => 'done'])->assertRedirect();
        $this->assertSame(PatientRecall::DONE, $recall->refresh()->status);

        $this->get(route('app.reminders.index'))
            ->assertInertia(fn (Assert $page) => $page->has('recalls', 0));
    }

    public function test_unpaid_balances_are_listed_for_staff_who_see_payments(): void
    {
        Consultation::query()->create([
            'patient_id' => $this->patient->getKey(),
            'consulted_at' => now()->subDays(10),
            'status' => 'completed',
            'payment_amount_minor' => 250000,
            'is_paid' => false,
            'created_by' => $this->assistant->getKey(),
        ]);

        $this->get(route('app.reminders.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('balances', 1)
                ->where('balances.0.amount', 2500)
                ->where('balances.0.links.message', fn (string $message): bool => str_contains($message, '2 500 DA'))
            );

        $this->actingAs(User::factory()->create())
            ->get(route('app.reminders.index'))
            ->assertForbidden();
    }

    private function appointment(string $startsAt, AppointmentStatus $status): Appointment
    {
        $start = CarbonImmutable::parse($startsAt);

        return Appointment::query()->create([
            'patient_id' => $this->patient->getKey(),
            'appointment_date' => $start->toDateString(),
            'starts_at' => $start,
            'ends_at' => $start->addMinutes(20),
            'status' => $status,
            'prestation' => 'Consultation',
            'created_by' => $this->assistant->getKey(),
        ]);
    }
}
