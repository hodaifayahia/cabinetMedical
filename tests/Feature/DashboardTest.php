<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\DoctorProfile;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard(): void
    {
        $user = User::factory()->create();
        DoctorProfile::factory()->for($user)->create([
            'specialty' => 'General Medicine',
            'specialty_code' => 'general_medicine',
        ]);
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('profile.specialty', 'Médecine générale')
                ->where('appointmentsByStatus.0.label', 'Planifié')
                ->where('appointmentsByStatus.6.label', 'Absent'));
    }

    public function test_dashboard_revenue_uses_actual_installments_instead_of_the_full_charge(): void
    {
        $user = User::factory()->create();
        $patient = Patient::factory()->create();
        $consultation = Consultation::query()->create([
            'patient_id' => $patient->getKey(),
            'consulted_at' => now(),
            'status' => 'completed',
            'payment_amount_minor' => 100000,
            'payment_service' => 'Consultation',
            'is_paid' => false,
            'created_by' => $user->getKey(),
        ]);

        $this->actingAs($user);
        $consultation->payments()->create([
            'patient_id' => $patient->getKey(),
            'amount_minor' => 30000,
            'method' => 'Cash',
            'received_at' => now(),
            'received_by' => $user->getKey(),
        ]);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.revenue_this_month', 300)
                ->where('stats.revenue_total', 300)
                ->where('revenueTrend.5.value', 300)
                ->has('recentPayments', 1)
                ->where('recentPayments.0.amount', 300)
                ->where('recentPayments.0.id', $consultation->getKey())
            );
    }
}
