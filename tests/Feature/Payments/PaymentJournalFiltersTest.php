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

/**
 * The CSV export, the printed report and the « Dettes » shortcut must agree
 * with the payments journal filters shown on screen.
 */
class PaymentJournalFiltersTest extends TestCase
{
    use RefreshDatabase;

    private User $doctor;

    private User $assistant;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-06-15 10:00:00');
        $this->travelTo(CarbonImmutable::now());

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->doctor = User::factory()->create(['name' => 'Dr Kaci']);
        $this->doctor->assignRole(RoleName::ADMINISTRATOR->value);
        $this->assistant = User::factory()->create(['name' => 'Assistante Lina']);
        $this->assistant->assignRole(RoleName::ADMINISTRATOR->value);
        $this->actingAs($this->doctor);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_export_applies_the_user_status_method_and_search_filters(): void
    {
        $amina = Patient::factory()->create(['first_name' => 'Amina', 'last_name' => 'Kaci']);
        $karim = Patient::factory()->create(['first_name' => 'Karim', 'last_name' => 'Haddad']);

        // Paid in cash, by the doctor, for Amina.
        $this->charge($amina, $this->doctor, '2026-06-10 09:00', 150000, [['2026-06-10 09:10', 150000, 'Espèces']]);
        // Partly paid by card, by the assistant, for Karim.
        $this->charge($karim, $this->assistant, '2026-06-11 09:00', 200000, [['2026-06-11 09:10', 50000, 'Carte']]);
        // Paid by card, by the doctor, for Karim.
        $this->charge($karim, $this->doctor, '2026-06-12 09:00', 120000, [['2026-06-12 09:10', 120000, 'Carte']]);

        $window = ['from' => '2026-06-01', 'to' => '2026-06-30'];

        $all = $this->exportCsv($window);
        $this->assertStringContainsString('1500,00', $all);
        $this->assertStringContainsString('500,00', $all);
        $this->assertStringContainsString('1200,00', $all);

        $byUser = $this->exportCsv([...$window, 'user' => $this->assistant->getKey()]);
        $this->assertStringContainsString('500,00', $byUser);
        $this->assertStringNotContainsString('1500,00', $byUser);
        $this->assertStringNotContainsString('1200,00', $byUser);

        $debts = $this->exportCsv([...$window, 'status' => 'debt']);
        $this->assertStringContainsString('500,00', $debts);
        $this->assertStringNotContainsString('1500,00', $debts);
        $this->assertStringNotContainsString('1200,00', $debts);

        $card = $this->exportCsv([...$window, 'method' => 'Carte']);
        $this->assertStringContainsString('500,00', $card);
        $this->assertStringContainsString('1200,00', $card);
        $this->assertStringNotContainsString('1500,00', $card);

        $search = $this->exportCsv([...$window, 'search' => 'Amina']);
        $this->assertStringContainsString('1500,00', $search);
        $this->assertStringNotContainsString('1200,00', $search);

        $combined = $this->exportCsv([...$window, 'search' => 'Karim', 'status' => 'paid']);
        $this->assertStringContainsString('1200,00', $combined);
        $this->assertStringNotContainsString('500,00', $combined);
    }

    public function test_export_of_all_debts_without_dates_covers_every_period(): void
    {
        $patient = Patient::factory()->create();
        $this->charge($patient, $this->doctor, '2025-11-03 09:00', 300000, [['2025-11-03 09:10', 70000, 'Espèces']]);
        $this->charge($patient, $this->doctor, '2026-06-10 09:00', 90000, [['2026-06-10 09:10', 90000, 'Espèces']]);

        $response = $this->get(route('app.payments.export', ['status' => 'debt']));
        $response->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringContainsString('700,00', $csv);
        $this->assertStringNotContainsString('900,00', $csv);
        $this->assertStringContainsString('journal-encaissements_debut_aujourdhui.csv', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_export_rejects_invalid_filters(): void
    {
        $this->get(route('app.payments.export', ['status' => 'bogus']))
            ->assertSessionHasErrors('status');
        $this->get(route('app.payments.export', ['from' => '2026-06-10', 'to' => '2026-06-01']))
            ->assertSessionHasErrors('to');
    }

    public function test_debts_badge_counts_every_open_debt_whatever_the_period(): void
    {
        $patient = Patient::factory()->create();
        // Old debt, outside the default (current month) window.
        $this->charge($patient, $this->doctor, '2025-11-03 09:00', 300000, [['2025-11-03 09:10', 100000, 'Espèces']]);
        // Debt of this month.
        $this->charge($patient, $this->doctor, '2026-06-10 09:00', 50000, []);

        $this->get(route('app.payments.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('payments/Index')
                ->where('summary.outstanding', 500)
                ->where('summary.debts', 2500)
                ->where('payments.data.0.payment_service', 'Consultation')
            );
    }

    public function test_raising_the_price_allows_collecting_the_difference_in_the_same_edit(): void
    {
        $consultation = $this->charge(Patient::factory()->create(), $this->doctor, '2026-06-10 09:00', 100000, [['2026-06-10 09:10', 100000, 'Espèces']]);
        $this->assertTrue($consultation->fresh()->is_paid);

        $this->patch(route('app.payments.update', $consultation), [
            'amount' => 1500,
            'paid_today' => 500,
            'method' => 'Espèces',
            'service' => 'Consultation + ECG',
            'settlement' => 'debt',
            'client_reference' => '0199a000-0000-7000-8000-0000000000a1',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $consultation->refresh();
        $this->assertSame(150000, $consultation->payment_amount_minor);
        $this->assertSame(150000, $consultation->collectedMinor());
        $this->assertTrue($consultation->is_paid);
    }

    public function test_editing_a_legacy_paid_consultation_does_not_turn_it_into_debt(): void
    {
        // Marked paid before the instalment ledger existed: no payment row.
        $consultation = Consultation::query()->create([
            'patient_id' => Patient::factory()->create()->getKey(),
            'consulted_at' => '2026-06-02 09:00',
            'status' => 'completed',
            'payment_amount_minor' => 200000,
            'payment_method' => 'Espèces',
            'payment_service' => 'Consultation',
            'is_paid' => true,
            'created_by' => $this->doctor->getKey(),
        ]);
        $this->assertSame(200000, $consultation->collectedMinor());

        // Only the method is corrected; nothing is collected now.
        $this->patch(route('app.payments.update', $consultation), [
            'amount' => 2000,
            'paid_today' => 0,
            'method' => 'Chèque',
            'service' => 'Consultation',
            'settlement' => 'debt',
            'client_reference' => '0199a000-0000-7000-8000-0000000000a2',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $consultation->refresh();
        $this->assertTrue($consultation->is_paid);
        $this->assertSame('paid', $consultation->paymentStatus());
        $this->assertSame(0, $consultation->outstandingMinor());

        // The historical collection is now in the ledger, on its original date,
        // so it is counted once (not as legacy and ledger at the same time).
        $ledger = Payment::query()->where('consultation_id', $consultation->getKey())->get();
        $this->assertCount(1, $ledger);
        $this->assertSame(200000, $ledger->first()->amount_minor);
        $this->assertSame('2026-06-02', $ledger->first()->received_at?->toDateString());

        $csv = $this->exportCsv(['from' => '2026-06-01', 'to' => '2026-06-30']);
        $this->assertSame(1, substr_count($csv, '2000,00'));

        // Raising the price afterwards leaves exactly the difference due.
        $this->patch(route('app.payments.update', $consultation), [
            'amount' => 2500,
            'paid_today' => 0,
            'method' => 'Chèque',
            'service' => 'Consultation',
            'settlement' => 'debt',
            'client_reference' => '0199a000-0000-7000-8000-0000000000a3',
        ])->assertSessionHasNoErrors();

        $consultation->refresh();
        $this->assertFalse($consultation->is_paid);
        $this->assertSame(50000, $consultation->outstandingMinor());
    }

    public function test_printed_report_uses_french_amounts_and_describes_the_period(): void
    {
        $patient = Patient::factory()->create();
        $this->charge($patient, $this->doctor, '2025-11-03 09:00', 300000, [['2025-11-03 09:10', 123456, 'Espèces']]);

        $this->get(route('app.payments.print', ['status' => 'debt']))
            ->assertOk()
            ->assertSeeText('Toutes périodes')
            ->assertSeeText('Toutes les dettes')
            ->assertSee('1 234,56 DA', false)
            ->assertDontSee('1,234.56');

        $consultation = Consultation::query()->firstOrFail();
        $this->get(route('app.payments.receipt', $consultation))
            ->assertOk()
            ->assertSee('3 000,00 DA', false)
            ->assertSee('1 765,44 DA', false);

        $this->get(route('app.payments.print', ['from' => '2026-06-01', 'to' => '2026-06-15']))
            ->assertOk()
            ->assertSeeText('01/06/2026 — 15/06/2026');
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function exportCsv(array $query): string
    {
        $response = $this->get(route('app.payments.export', $query));
        $response->assertOk();

        return $response->streamedContent();
    }

    /**
     * @param  list<array{0: string, 1: int, 2: string}>  $collections
     */
    private function charge(Patient $patient, User $creator, string $consultedAt, int $amountMinor, array $collections): Consultation
    {
        $paid = array_sum(array_column($collections, 1));
        $consultation = Consultation::query()->create([
            'patient_id' => $patient->getKey(),
            'consulted_at' => $consultedAt,
            'status' => 'completed',
            'payment_amount_minor' => $amountMinor,
            'payment_service' => 'Consultation',
            'payment_method' => $collections[0][2] ?? null,
            'is_paid' => $paid >= $amountMinor,
            'created_by' => $creator->getKey(),
        ]);

        foreach ($collections as [$receivedAt, $minor, $method]) {
            $consultation->payments()->create([
                'patient_id' => $patient->getKey(),
                'amount_minor' => $minor,
                'method' => $method,
                'received_at' => $receivedAt,
                'received_by' => $creator->getKey(),
            ]);
        }

        return $consultation;
    }
}
