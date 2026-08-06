<?php

namespace Tests\Feature\Payments;

use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\CabinetSetting;
use App\Models\Consultation;
use App\Models\DoctorProfile;
use App\Models\Patient;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PaymentControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->user = User::factory()->create();
        $this->user->assignRole(RoleName::ADMINISTRATOR->value);
    }

    public function test_payments_page_filters_records_and_calculates_totals(): void
    {
        $patient = Patient::factory()->create([
            'first_name' => 'Nadia',
            'last_name' => 'Benali',
        ]);
        $this->payment($patient, [
            'consulted_at' => now(),
            'payment_amount_minor' => 310000,
            'payment_method' => 'Cash',
            'payment_service' => 'Consultation + ECG',
            'is_paid' => true,
        ]);
        $this->payment($patient, [
            'consulted_at' => now()->subDay(),
            'payment_amount_minor' => 100000,
            'payment_method' => 'Card',
            'payment_service' => 'Ultrasound',
            'is_paid' => false,
        ]);

        $this->actingAs($this->user)
            ->get(route('app.payments.index', [
                'from' => now()->subWeek()->toDateString(),
                'to' => now()->toDateString(),
                'status' => 'paid',
                'method' => 'Cash',
                'search' => 'Nadia',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('payments/Index')
                ->has('payments.data', 1)
                ->where('payments.data.0.service', 'Consultation + ECG')
                ->where('summary.paid', 3100)
                ->where('summary.outstanding', 0)
            );
    }

    public function test_authorized_user_can_update_and_print_a_payment(): void
    {
        $patient = Patient::factory()->create();
        $consultation = $this->payment($patient, [
            'payment_amount_minor' => 200000,
            'is_paid' => false,
        ]);

        $this->actingAs($this->user)
            ->patch(route('app.payments.update', $consultation), [
                'amount' => 2800,
                'method' => 'Cash',
                'service' => 'Consultation and ECG',
                'is_paid' => true,
            ])
            ->assertRedirect();

        $consultation->refresh();
        $this->assertSame(280000, $consultation->payment_amount_minor);
        $this->assertSame('Consultation and ECG', $consultation->payment_service);
        $this->assertTrue($consultation->is_paid);
        $audit = AuditLog::query()
            ->where('action', 'payment.updated')
            ->where('subject_id', (string) $consultation->getKey())
            ->firstOrFail();
        $this->assertSame($this->user->getKey(), $audit->user_id);
        $this->assertTrue($audit->metadata['is_paid']);
        $this->assertContains(
            'payment_amount_minor',
            $audit->metadata['changed_fields'],
        );

        $this->actingAs($this->user)
            ->get(route('app.payments.receipt', $consultation))
            ->assertOk()
            ->assertSee('REÇU DE PAIEMENT')
            ->assertSee('Imprimer le reçu')
            ->assertSee('Consultation and ECG');

        $this->actingAs($this->user)
            ->get(route('app.payments.print', [
                'from' => now()->startOfMonth()->toDateString(),
                'to' => now()->toDateString(),
            ]))
            ->assertOk()
            ->assertSee('RAPPORT DES PAIEMENTS')
            ->assertSee('Imprimer le rapport');
    }

    public function test_payment_prints_follow_one_canonical_utf8_branding_update(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('cabinet/logo-atlas.png', $this->png());

        $doctor = DoctorProfile::factory()->for($this->user)->create([
            'specialty' => 'Cardiologie pédiatrique',
            'specialty_code' => 'cardiologie_pediatrique',
            'professional_identifier' => 'ORD-ÉTOILE-42',
        ]);
        $this->user->update(['name' => 'Dr Amel Benyahia']);

        $cabinet = CabinetSetting::current();
        $cabinet->update([
            'name' => 'Clinique Étoile الشفاء',
            'phone' => '+213 555 12 34 56',
            'email' => 'contact@etoile.test',
            'address' => '12, rue de la Liberté',
            'city' => 'الجزائر',
            'prescription_footer' => 'الاستقبال بالطابق الأول',
            'receipt_footer' => 'Paiement acquitté — merci',
            'logo_path' => 'cabinet/logo-atlas.png',
        ]);

        $consultation = $this->payment(Patient::factory()->create());
        $receiptRoute = route('app.payments.receipt', $consultation);
        $reportRoute = route('app.payments.print', [
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->toDateString(),
        ]);

        foreach ([$receiptRoute, $reportRoute] as $route) {
            $response = $this->actingAs($this->user)->get($route)->assertOk();
            $response->assertSeeText('Clinique Étoile الشفاء');
            $response->assertSeeText('Dr Amel Benyahia');
            $response->assertSeeText('Cardiologie pédiatrique');
            $response->assertSeeText('ORD-ÉTOILE-42');
            $response->assertSeeText('12, rue de la Liberté, الجزائر');
            $response->assertSeeText('الاستقبال بالطابق الأول');
            $response->assertSee(Storage::disk('public')->url('cabinet/logo-atlas.png'), false);
        }

        $this->assertStringContainsString(
            'Paiement acquitté — merci',
            $this->actingAs($this->user)->get($receiptRoute)->getContent(),
        );

        Storage::disk('public')->put('cabinet/logo-nour.png', $this->png());
        $this->user->update(['name' => 'Dr Leïla Haddad']);
        $doctor->correctLockedSpecialty('Médecine générale', 'medecine_generale', $this->user);
        $doctor->update([
            'professional_identifier' => 'ORD-NOUR-77',
        ]);
        $cabinet->update([
            'name' => 'Cabinet Nour',
            'address' => '8 avenue des Oliviers',
            'city' => 'وهران',
            'prescription_footer' => 'Sur rendez-vous uniquement',
            'logo_path' => 'cabinet/logo-nour.png',
        ]);

        foreach ([$receiptRoute, $reportRoute] as $route) {
            $response = $this->actingAs($this->user)->get($route)->assertOk();
            $response->assertSeeText('Cabinet Nour');
            $response->assertSeeText('Dr Leïla Haddad');
            $response->assertSeeText('ORD-NOUR-77');
            $response->assertSeeText('8 avenue des Oliviers, وهران');
            $response->assertSeeText('Sur rendez-vous uniquement');
            $response->assertSee(Storage::disk('public')->url('cabinet/logo-nour.png'), false);
            $response->assertDontSeeText('Clinique Étoile الشفاء');
            $response->assertDontSeeText('ORD-ÉTOILE-42');
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function payment(Patient $patient, array $attributes = []): Consultation
    {
        return Consultation::query()->create([
            'patient_id' => $patient->getKey(),
            'consulted_at' => now(),
            'status' => 'completed',
            'payment_amount_minor' => 100000,
            'payment_method' => 'Cash',
            'payment_service' => 'Consultation',
            'is_paid' => true,
            'created_by' => $this->user->getKey(),
            ...$attributes,
        ]);
    }

    private function png(): string
    {
        return (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Z9mAAAAAASUVORK5CYII=',
            true,
        );
    }
}
