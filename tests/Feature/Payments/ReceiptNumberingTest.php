<?php

namespace Tests\Feature\Payments;

use App\Enums\CabinetStatus;
use App\Enums\RoleName;
use App\Models\AccountingSetting;
use App\Models\Cabinet;
use App\Models\Consultation;
use App\Models\Patient;
use App\Models\User;
use App\Services\Billing\ReceiptNumberer;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReceiptNumberingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-03-10 10:00:00'));
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_first_collection_assigns_sequential_receipt_numbers_that_never_change(): void
    {
        $doctor = $this->member(null);
        $first = $this->consultation($doctor, 100000);
        $second = $this->consultation($doctor, 50000, ['consulted_at' => now()->subHour()]);

        $this->collect($first, 1000, 400);
        $this->collect($second, 500, 500);
        // A later instalment keeps the number already issued.
        $this->collect($first, 1000, 600);

        $this->assertSame('FACT-2026-00001', $first->refresh()->receipt_number);
        $this->assertSame('FACT-2026-00002', $second->refresh()->receipt_number);

        $this->get(route('app.payments.receipt', $first))
            ->assertOk()
            ->assertSee('Reçu n° FACT-2026-00001');

        $this->get(route('app.payments.index', ['from' => '2026-03-01', 'to' => '2026-03-31']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('payments.data.0.receipt_number', 'FACT-2026-00001')
                ->where('payments.data.1.receipt_number', 'FACT-2026-00002')
            );
    }

    public function test_unpaid_consultations_get_no_number_and_legacy_paid_ones_get_one_on_print(): void
    {
        $doctor = $this->member(null);
        $unpaid = $this->consultation($doctor, 100000);
        $legacy = $this->consultation($doctor, 80000, ['is_paid' => true]);

        $this->get(route('app.payments.receipt', $unpaid))->assertOk();
        $this->assertNull($unpaid->refresh()->receipt_number);

        $this->get(route('app.payments.receipt', $legacy))
            ->assertOk()
            ->assertSee('FACT-2026-00001');
        $this->assertSame('FACT-2026-00001', $legacy->refresh()->receipt_number);
    }

    public function test_prefix_and_fiscal_year_start_come_from_accounting_settings(): void
    {
        $doctor = $this->member(null);
        AccountingSetting::current()->update(['receipt_prefix' => 'REC/', 'fiscal_year_start' => '07-01']);
        $consultation = $this->consultation($doctor, 100000);

        $this->collect($consultation, 1000, 1000);

        // 10 March 2026 falls in the fiscal year that began on 1 July 2025.
        $this->assertSame('REC/2025-00001', $consultation->refresh()->receipt_number);
        $numberer = app(ReceiptNumberer::class);
        $this->assertSame(2026, $numberer->fiscalYear(CarbonImmutable::parse('2026-07-01'), '07-01'));
        $this->assertSame(2026, $numberer->fiscalYear(CarbonImmutable::parse('2026-12-31'), '01-01'));
    }

    public function test_each_cabinet_has_its_own_sequence(): void
    {
        $alpha = $this->member($this->cabinet('Cabinet Alpha'));
        $beta = $this->member($this->cabinet('Cabinet Beta'));

        $a1 = $this->consultation($alpha, 100000);
        $this->collect($a1, 1000, 1000);
        $a2 = $this->consultation($alpha, 100000);
        $this->collect($a2, 1000, 1000);
        $b1 = $this->consultation($beta, 100000);
        $this->collect($b1, 1000, 1000);

        $this->assertSame('FACT-2026-00002', Consultation::withoutCabinetScope()->find($a2->getKey())?->receipt_number);
        $this->assertSame('FACT-2026-00001', Consultation::withoutCabinetScope()->find($b1->getKey())?->receipt_number);
    }

    private function cabinet(string $name): Cabinet
    {
        return Cabinet::query()->create([
            'name' => $name,
            'status' => CabinetStatus::ACTIVE,
            'activated_at' => now(),
        ]);
    }

    private function member(?Cabinet $cabinet): User
    {
        $user = User::factory()->create([
            'cabinet_id' => $cabinet?->getKey(),
            'approved_at' => now(),
        ]);
        $user->assignRole(RoleName::DOCTOR->value);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function consultation(User $user, int $amountMinor, array $overrides = []): Consultation
    {
        $this->actingAs($user);
        $patient = Patient::factory()->create();

        return Consultation::query()->create([
            'patient_id' => $patient->getKey(),
            'consulted_at' => now(),
            'status' => 'completed',
            'payment_amount_minor' => $amountMinor,
            'payment_service' => 'Consultation',
            'is_paid' => false,
            'created_by' => $user->getKey(),
            ...$overrides,
        ]);
    }

    private function collect(Consultation $consultation, int $amount, int $paidToday): void
    {
        $this->post(route('app.consultations.payments.store', $consultation), [
            'amount' => $amount,
            'paid_today' => $paidToday,
            'method' => 'Espèces',
            'settlement' => 'debt',
            'client_reference' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();
    }
}
