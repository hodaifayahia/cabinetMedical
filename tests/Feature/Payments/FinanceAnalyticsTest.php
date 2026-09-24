<?php

namespace Tests\Feature\Payments;

use App\Enums\RoleName;
use App\Models\Consultation;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FinanceAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private User $doctor;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-06-15 10:00:00');
        $this->travelTo(CarbonImmutable::now());

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->doctor = User::factory()->create();
        $this->doctor->assignRole(RoleName::DOCTOR->value);
        $this->actingAs($this->doctor);
        $this->patient = Patient::factory()->create(['first_name' => 'Amina', 'last_name' => 'Kaci']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_year_report_separates_collected_and_billed_and_compares_with_last_year(): void
    {
        // Last year, before the same day: counts in the comparison.
        $this->charge('2025-03-10 09:00', 200000, [['2025-03-10 09:05', 200000]]);
        // Last year, after the same day: in the chart overlay only.
        $this->charge('2025-09-10 09:00', 50000, [['2025-09-10 09:05', 50000]]);

        // This year: a fully paid visit, a partial one and an unpaid one.
        $this->charge('2026-03-02 09:00', 300000, [['2026-03-02 09:10', 300000]], 'Échographie', 'Espèces');
        $this->charge('2026-05-20 09:00', 100000, [['2026-05-20 09:10', 40000]], 'Consultation', 'Carte');
        $this->charge('2026-06-01 09:00', 80000, [], 'Consultation');

        $this->get(route('app.payments.analytics', ['year' => 2026]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('payments/Analytics')
                ->where('years', [2026, 2025])
                ->where('report.period.is_current', true)
                ->where('report.comparison.partial', true)
                ->where('report.comparison.to', '2025-06-15')
                ->where('report.kpis.collected', 3400)
                ->where('report.kpis.billed', 4800)
                ->where('report.kpis.outstanding', 1400)
                ->where('report.kpis.consultations', 3)
                ->where('report.kpis.average_ticket', 1600)
                ->where('report.kpis.collection_rate', 70.8)
                ->where('report.previous.collected', 2000)
                ->where('report.changes.collected', 70)
                ->has('report.timeline', 12)
                ->where('report.timeline.2.collected', 3000)
                ->where('report.timeline.2.previous_collected', 2000)
                ->where('report.timeline.8.previous_collected', 500)
                ->where('report.timeline.8.future', true)
                ->where('report.methods.0.label', 'Espèces')
                ->where('report.methods.0.value', 3000)
                ->where('report.services.0.label', 'Échographie')
                ->where('report.topPatients.0.patient_name', 'Amina Kaci')
                ->where('receivables.total', 1400)
                ->where('receivables.patients', 1)
            );
    }

    public function test_month_report_is_daily_and_nets_refunds_in_the_month_they_happen(): void
    {
        $consultation = $this->charge('2026-05-28 09:00', 100000, [['2026-05-28 09:10', 100000]]);
        $original = $consultation->payments()->firstOrFail();

        $this->post(route('app.payments.refunds.store', $consultation), [
            'payment_id' => $original->public_id,
            'amount' => 250,
            'reason' => 'Examen annulé',
            'reduce_charge' => true,
            'client_reference' => '0199a000-0000-7000-8000-000000000001',
        ])->assertRedirect();

        $this->get(route('app.payments.analytics', ['year' => 2026, 'month' => 6]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('report.period.label', 'Juin 2026')
                ->has('report.timeline', 30)
                ->where('report.kpis.collected', -250)
                ->where('report.kpis.refunds', 250)
                ->where('report.timeline.14.refunds', 250)
                ->where('report.timeline.15.future', true)
                ->where('report.comparison.label', 'Mai 2026')
                // Same elapsed span: 1–15 May, before the 28 May payment.
                ->where('report.previous.collected', 0)
            );

        $this->get(route('app.payments.analytics', ['year' => 2026, 'month' => 5]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('report.kpis.collected', 1000)
                ->where('report.kpis.billed', 750)
            );
    }

    public function test_receivables_are_grouped_by_age_and_by_patient(): void
    {
        $other = Patient::factory()->create(['first_name' => 'Yacine', 'last_name' => 'Bouzid']);
        $this->charge('2026-06-10 09:00', 20000, []);
        $this->charge('2026-04-20 09:00', 30000, [['2026-04-20 09:10', 10000]]);
        $this->charge('2026-01-05 09:00', 50000, [], 'Consultation', null, $other);

        $this->get(route('app.payments.analytics'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('receivables.total', 900)
                ->where('receivables.count', 3)
                ->where('receivables.buckets.0.amount', 200)
                ->where('receivables.buckets.1.amount', 200)
                ->where('receivables.buckets.2.amount', 0)
                ->where('receivables.buckets.3.amount', 500)
                ->where('receivables.debtors.0.patient_name', 'Yacine Bouzid')
                ->where('receivables.debtors.1.amount', 400)
                ->where('receivables.debtors.1.count', 2)
            );
    }

    public function test_refund_can_turn_the_amount_back_into_debt(): void
    {
        $consultation = $this->charge('2026-06-10 09:00', 100000, [['2026-06-10 09:10', 100000]]);
        $original = $consultation->payments()->firstOrFail();

        $this->post(route('app.payments.refunds.store', $consultation), [
            'payment_id' => $original->public_id,
            'amount' => 1000,
            'reason' => 'Chèque rejeté',
            'reduce_charge' => false,
            'client_reference' => '0199a000-0000-7000-8000-000000000002',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $consultation->refresh();
        $this->assertFalse($consultation->is_paid);
        $this->assertSame(100000, $consultation->payment_amount_minor);
        $this->assertSame(100000, $consultation->outstandingMinor());
        $this->assertSame('unpaid', $consultation->paymentStatus());
        $this->assertDatabaseHas('payments', [
            'refund_of_payment_id' => $original->getKey(),
            'amount_minor' => -100000,
            'notes' => 'Chèque rejeté',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.refunded']);
    }

    public function test_refund_reduces_the_charge_and_is_idempotent_and_bounded(): void
    {
        $consultation = $this->charge('2026-06-10 09:00', 100000, [['2026-06-10 09:10', 100000]]);
        $original = $consultation->payments()->firstOrFail();
        $payload = [
            'payment_id' => $original->public_id,
            'amount' => 400,
            'reason' => 'Trop-perçu',
            'reduce_charge' => true,
            'client_reference' => '0199a000-0000-7000-8000-000000000003',
        ];

        $this->post(route('app.payments.refunds.store', $consultation), $payload)->assertSessionHasNoErrors();
        $this->post(route('app.payments.refunds.store', $consultation), $payload)->assertSessionHasNoErrors();

        $consultation->refresh();
        $this->assertSame(1, Payment::query()->where('refund_of_payment_id', $original->getKey())->count());
        $this->assertSame(60000, $consultation->payment_amount_minor);
        $this->assertTrue($consultation->is_paid);
        $this->assertSame(60000, $consultation->collectedMinor());

        // Only 600 remain refundable on that instalment.
        $this->post(route('app.payments.refunds.store', $consultation), [
            ...$payload,
            'amount' => 700,
            'client_reference' => '0199a000-0000-7000-8000-000000000004',
        ])->assertSessionHasErrors('amount');

        $this->post(route('app.payments.refunds.store', $consultation), [
            ...$payload,
            'reason' => '',
            'client_reference' => '0199a000-0000-7000-8000-000000000005',
        ])->assertSessionHasErrors('reason');

        $this->get(route('app.payments.index', ['from' => '2026-06-01', 'to' => '2026-06-30']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('canRefund', true)
                ->where('payments.data.0.installments.0.refundable', 600)
                ->where('payments.data.0.installments.1.is_refund', true)
                ->where('payments.data.0.installments.1.amount', -400)
            );
    }

    public function test_assistants_can_see_analytics_but_cannot_refund(): void
    {
        $assistant = User::factory()->create();
        $assistant->assignRole(RoleName::ASSISTANT->value);
        $consultation = $this->charge('2026-06-10 09:00', 100000, [['2026-06-10 09:10', 100000]]);

        $this->actingAs($assistant)
            ->get(route('app.payments.analytics'))
            ->assertOk();

        $this->actingAs($assistant)
            ->post(route('app.payments.refunds.store', $consultation), [
                'payment_id' => $consultation->payments()->firstOrFail()->public_id,
                'amount' => 100,
                'reason' => 'Test',
                'reduce_charge' => true,
                'client_reference' => '0199a000-0000-7000-8000-000000000006',
            ])
            ->assertForbidden();
    }

    public function test_cash_journal_exports_as_csv_and_neutralises_formulas(): void
    {
        $patient = Patient::factory()->create(['first_name' => '=HYPERLINK("x")', 'last_name' => 'Test']);
        $this->charge('2026-06-10 09:00', 150000, [['2026-06-10 09:10', 150000]], 'Consultation', 'Espèces', $patient);
        $this->charge('2026-01-10 09:00', 90000, [['2026-01-10 09:10', 90000]]);

        $response = $this->get(route('app.payments.export', ['from' => '2026-06-01', 'to' => '2026-06-30']));
        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));

        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('1500,00', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString('900,00', $csv);
    }

    public function test_printable_financial_report_renders_the_period_figures(): void
    {
        $this->charge('2026-03-02 09:00', 300000, [['2026-03-02 09:10', 300000]], 'Échographie', 'Espèces');
        $this->charge('2026-03-20 09:00', 100000, [], 'Consultation');

        $this->get(route('app.payments.analytics.print', ['year' => 2026, 'month' => 3]))
            ->assertOk()
            ->assertSee('RAPPORT FINANCIER')
            ->assertSee('MARS 2026')
            ->assertSee('Détail jour par jour')
            ->assertSee('3 000,00 DA', false)
            ->assertSee('4 000,00 DA', false)
            ->assertSee('Échographie')
            ->assertSee('Espèces');

        $this->get(route('app.payments.analytics.print', ['year' => 2026]))
            ->assertOk()
            ->assertSee('ANNÉE 2026')
            ->assertSee('Détail mois par mois')
            ->assertSee('Encaissé 2025');

        $assistant = User::factory()->create();
        $assistant->assignRole(RoleName::ASSISTANT->value);
        $this->actingAs($assistant)
            ->get(route('app.payments.analytics.print'))
            ->assertOk();

        $this->actingAs(User::factory()->create())
            ->get(route('app.payments.analytics.print'))
            ->assertForbidden();
    }

    public function test_dashboard_exposes_finance_only_to_users_who_can_view_payments(): void
    {
        $this->charge('2026-06-14 09:00', 100000, [['2026-06-14 09:10', 100000]]);
        $this->charge('2026-06-15 09:00', 50000, [['2026-06-15 09:10', 20000]]);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('canViewFinance', true)
                ->where('finance.today.collected', 200)
                ->where('finance.month.collected', 1200)
                ->where('finance.year.collected', 1200)
                ->has('finance.timeline', 12)
                ->where('receivables.total', 300)
                ->has('todayActivity.upcoming')
                ->has('patientsTrend', 12)
            );

        $viewer = User::factory()->create();
        $this->actingAs($viewer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('canViewFinance', false)
                ->where('finance', null)
                ->where('receivables', null)
            );
    }

    /**
     * @param  list<array{0: string, 1: int}>  $collections
     */
    private function charge(
        string $consultedAt,
        int $amountMinor,
        array $collections,
        string $service = 'Consultation',
        ?string $method = null,
        ?Patient $patient = null,
    ): Consultation {
        $patient ??= $this->patient;
        $paid = array_sum(array_column($collections, 1));
        $consultation = Consultation::query()->create([
            'patient_id' => $patient->getKey(),
            'consulted_at' => $consultedAt,
            'status' => 'completed',
            'payment_amount_minor' => $amountMinor,
            'payment_service' => $service,
            'payment_method' => $method,
            'is_paid' => $paid >= $amountMinor,
            'created_by' => $this->doctor->getKey(),
        ]);

        foreach ($collections as [$receivedAt, $minor]) {
            $consultation->payments()->create([
                'patient_id' => $patient->getKey(),
                'amount_minor' => $minor,
                'method' => $method,
                'received_at' => $receivedAt,
                'received_by' => $this->doctor->getKey(),
            ]);
        }

        return $consultation;
    }
}
