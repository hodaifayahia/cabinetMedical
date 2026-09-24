<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\RoleName;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Global search, waiting-room display and audit log viewer.
 */
class PracticeToolsTest extends TestCase
{
    use RefreshDatabase;

    private User $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->doctor = User::factory()->create(['name' => 'Dr Haddad']);
        $this->doctor->assignRole(RoleName::DOCTOR->value);
        $this->actingAs($this->doctor);
    }

    public function test_global_search_finds_patients_by_full_name_in_any_order_number_or_phone(): void
    {
        $amina = Patient::factory()->create([
            'first_name' => 'Amina',
            'last_name' => 'Kaci',
            'phone' => '0555 12 34 56',
            'date_of_birth' => '1990-06-01',
        ]);
        Patient::factory()->create(['first_name' => 'Amine', 'last_name' => 'Belkacem', 'phone' => '0661000000']);
        Appointment::query()->create([
            'patient_id' => $amina->getKey(),
            'appointment_date' => '2026-06-20',
            'starts_at' => '2026-06-20 09:30:00',
            'ends_at' => '2026-06-20 09:50:00',
            'status' => AppointmentStatus::SCHEDULED,
            'created_by' => $this->doctor->getKey(),
        ]);

        foreach (['amina kaci', 'Kaci Amina', '0555123456', $amina->patient_number] as $query) {
            $this->getJson(route('app.search', ['q' => $query]))
                ->assertOk()
                ->assertJsonCount(1, 'patients')
                ->assertJsonPath('patients.0.name', 'Amina Kaci')
                ->assertJsonPath('patients.0.age', 36)
                ->assertJsonPath('patients.0.next_appointment', '20/06/2026 09:30');
        }

        $this->getJson(route('app.search', ['q' => 'ami']))->assertJsonCount(2, 'patients');
        $this->getJson(route('app.search', ['q' => 'a']))->assertJsonCount(0, 'patients');
        $this->actingAs(User::factory()->create())->getJson(route('app.search', ['q' => 'amina']))->assertForbidden();
    }

    public function test_waiting_room_board_orders_arrivals_and_hides_full_names(): void
    {
        $this->appointment('Yacine', 'Bouzid', '09:00', AppointmentStatus::COMPLETED, '08:50');
        $this->appointment('Amina', 'Kaci', '09:30', AppointmentStatus::IN_PROGRESS, '09:05');
        $this->appointment('Omar', 'Saadi', '10:00', AppointmentStatus::CHECKED_IN, '09:40');
        $this->appointment('Lina', 'Meziane', '09:45', AppointmentStatus::CHECKED_IN, '09:20');
        $this->appointment('Karim', 'Hamidi', '11:00', AppointmentStatus::SCHEDULED, null);

        $this->get(route('app.waiting-room'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('waiting-room/Display')
                ->where('board.current.0.ticket', 2)
                ->where('board.current.0.name', 'Amina K.')
                ->where('board.waiting.0.ticket', 3)
                ->where('board.waiting.0.name', 'Lina M.')
                ->where('board.waiting.1.name', 'Omar S.')
                ->where('board.upcoming.0.name', 'Karim H.')
                ->where('board.done', 1)
            )
            ->assertDontSee('Kaci')
            ->assertDontSee('Meziane');
    }

    public function test_audit_log_viewer_lists_filters_and_labels_entries(): void
    {
        AuditLog::record('payment.refunded', null, ['refunded_minor' => 40000, 'reduce_charge' => true]);
        AuditLog::record('expense.created', null, ['category' => 'rent']);

        $this->get(route('app.audit-logs.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('audit/Index')
                ->has('logs.data', 2)
                ->where('logs.data.0.label', 'Charge ajoutée')
                ->where('logs.data.1.label', 'Remboursement')
                ->where('logs.data.1.details', 'refunded : 400,00 · reduce charge : oui')
                ->where('logs.data.1.user', 'Dr Haddad')
            );

        $this->get(route('app.audit-logs.index', ['action' => 'payment']))
            ->assertInertia(fn (Assert $page) => $page->has('logs.data', 1));

        $assistant = User::factory()->create();
        $assistant->assignRole(RoleName::ASSISTANT->value);
        $this->actingAs($assistant)->get(route('app.audit-logs.index'))->assertForbidden();
    }

    private function appointment(string $first, string $last, string $time, AppointmentStatus $status, ?string $arrivedAt): void
    {
        $patient = Patient::factory()->create(['first_name' => $first, 'last_name' => $last]);
        $start = CarbonImmutable::parse('2026-06-15 '.$time);

        Appointment::query()->create([
            'patient_id' => $patient->getKey(),
            'appointment_date' => '2026-06-15',
            'starts_at' => $start,
            'ends_at' => $start->addMinutes(20),
            'status' => $status,
            'checked_in_at' => $arrivedAt !== null ? CarbonImmutable::parse('2026-06-15 '.$arrivedAt) : null,
            'created_by' => $this->doctor->getKey(),
        ]);
    }
}
