<?php

namespace Tests\Feature\Payments;

use App\Enums\RoleName;
use App\Models\Consultation;
use App\Models\Expense;
use App\Models\Patient;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ExpensesTest extends TestCase
{
    use RefreshDatabase;

    private User $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->doctor = User::factory()->create();
        $this->doctor->assignRole(RoleName::DOCTOR->value);
        $this->actingAs($this->doctor);
    }

    public function test_doctor_can_record_edit_and_delete_expenses_with_an_audit_trail(): void
    {
        $this->post(route('app.expenses.store'), [
            'category' => 'rent',
            'label' => 'Loyer du local',
            'amount' => 45000,
            'spent_on' => '2026-06-01',
            'method' => 'Virement',
            'supplier' => 'M. Benali',
            'is_recurring' => true,
        ])->assertSessionHasNoErrors();
        $this->post(route('app.expenses.store'), [
            'category' => 'medical_supplies',
            'label' => 'Gants et compresses',
            'amount' => 3500.5,
            'spent_on' => '2026-06-10',
        ])->assertSessionHasNoErrors();

        $expense = Expense::query()->where('label', 'Loyer du local')->firstOrFail();
        $this->assertSame(4500000, $expense->amount_minor);
        $this->assertTrue($expense->is_recurring);

        $this->get(route('app.expenses.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('payments/Expenses')
                ->has('expenses.data', 2)
                ->where('totals.amount', 48500.5)
                ->where('totals.count', 2)
                ->where('totals.byCategory.0.label', 'Loyer')
                ->where('expenses.data.0.label', 'Gants et compresses')
            );

        $this->patch(route('app.expenses.update', $expense), [
            'category' => 'rent',
            'label' => 'Loyer du local',
            'amount' => 50000,
            'spent_on' => '2026-06-01',
            'is_recurring' => true,
        ])->assertSessionHasNoErrors();
        $this->assertSame(5000000, $expense->refresh()->amount_minor);

        $this->delete(route('app.expenses.destroy', $expense))->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('expenses', ['id' => $expense->getKey()]);

        foreach (['expense.created', 'expense.updated', 'expense.deleted'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => $action]);
        }
    }

    public function test_validation_rejects_bad_expenses(): void
    {
        $this->post(route('app.expenses.store'), [
            'category' => 'not-a-category',
            'label' => '',
            'amount' => 0,
            'spent_on' => 'hier',
        ])->assertSessionHasErrors(['category', 'label', 'amount', 'spent_on']);
    }

    public function test_net_profit_appears_in_analytics_print_and_dashboard_for_doctors_only(): void
    {
        $patient = Patient::factory()->create();
        $consultation = Consultation::query()->create([
            'patient_id' => $patient->getKey(),
            'consulted_at' => '2026-06-02 09:00:00',
            'status' => 'completed',
            'payment_amount_minor' => 300000,
            'is_paid' => true,
            'created_by' => $this->doctor->getKey(),
        ]);
        $consultation->payments()->create([
            'patient_id' => $patient->getKey(),
            'amount_minor' => 300000,
            'received_at' => '2026-06-02 09:10:00',
            'received_by' => $this->doctor->getKey(),
        ]);
        $this->expense('rent', 'Loyer', 100000, '2026-06-01');
        $this->expense('salaries', 'Secrétaire', 20000, '2026-06-05');
        $this->expense('rent', 'Loyer', 100000, '2026-05-01');

        $this->get(route('app.payments.analytics', ['year' => 2026, 'month' => 6]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('profit.expenses', 1200)
                ->where('profit.net', 1800)
                ->where('profit.margin', 60)
                ->where('profit.categories.0.label', 'Loyer')
                ->where('profit.timeline.2026-06-02.net', 3000)
                ->where('profit.timeline.2026-06-01.expenses', 1000)
            );

        $this->get(route('app.payments.analytics', ['year' => 2026]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('profit.expenses', 2200)
                ->where('profit.timeline.2026-05.net', -1000)
            );

        $this->get(route('app.payments.analytics.print', ['year' => 2026, 'month' => 6]))
            ->assertSee('Bénéfice net (encaissé − charges)')
            ->assertSee('Charges par catégorie');

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('profit.net', 1800)
                ->where('profit.expenses', 1200)
            );

        $assistant = User::factory()->create();
        $assistant->assignRole(RoleName::ASSISTANT->value);
        $this->actingAs($assistant);

        $this->get(route('app.expenses.index'))->assertForbidden();
        $this->post(route('app.expenses.store'), ['category' => 'rent', 'label' => 'x', 'amount' => 1, 'spent_on' => '2026-06-01'])
            ->assertForbidden();
        $this->get(route('app.payments.analytics'))
            ->assertInertia(fn (Assert $page) => $page->where('profit', null));
        $this->get(route('app.payments.analytics.print'))
            ->assertOk()
            ->assertDontSee('Charges par catégorie');
        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('profit', null));
    }

    public function test_recurring_expenses_are_copied_once_into_the_next_month(): void
    {
        $this->expense('rent', 'Loyer', 4500000, '2026-05-31', true);
        $this->expense('salaries', 'Secrétaire', 3000000, '2026-05-05', true);
        $this->expense('medical_supplies', 'Achat ponctuel', 100000, '2026-05-10');

        $this->post(route('app.expenses.recurring'), ['month' => '2026-06'])->assertSessionHasNoErrors();
        $this->post(route('app.expenses.recurring'), ['month' => '2026-06'])->assertSessionHasNoErrors();

        $june = Expense::query()->spentBetween('2026-06-01', '2026-06-30')->orderBy('spent_on')->get();
        $this->assertCount(2, $june);
        $this->assertSame('2026-06-05', $june[0]->spent_on->toDateString());
        // 31 May becomes 30 June.
        $this->assertSame('2026-06-30', $june[1]->spent_on->toDateString());
        $this->assertTrue($june->every(fn (Expense $expense): bool => $expense->is_recurring));
    }

    public function test_expenses_export_as_csv(): void
    {
        $this->expense('telecom', '=CMD()', 250000, '2026-06-03');

        $response = $this->get(route('app.expenses.export', ['from' => '2026-06-01', 'to' => '2026-06-30']));
        $response->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringContainsString('Téléphone et internet', $csv);
        $this->assertStringContainsString('2500,00', $csv);
        $this->assertStringContainsString("'=CMD()", $csv);
    }

    private function expense(string $category, string $label, int $amountMinor, string $date, bool $recurring = false): Expense
    {
        return Expense::query()->create([
            'category' => $category,
            'label' => $label,
            'amount_minor' => $amountMinor,
            'spent_on' => $date,
            'is_recurring' => $recurring,
            'created_by' => $this->doctor->getKey(),
        ]);
    }
}
