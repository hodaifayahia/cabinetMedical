<?php

namespace Tests\Feature\Desktop;

use App\Models\CabinetSetting;
use App\Models\DoctorProfile;
use App\Models\User;
use App\Services\DocumentBrandingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentBrandingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reads_the_persisted_canonical_identity_and_preserves_a_utf8_footer(): void
    {
        Storage::fake('public');
        $user = User::factory()->create(['name' => 'Dr Amel Benyahia']);
        DoctorProfile::factory()->for($user)->create([
            'doctor_name' => 'Stale projected doctor name',
            'specialty' => 'Cardiologie pédiatrique',
            'specialty_code' => 'cardiologie_pediatrique',
            'professional_identifier' => 'ORD-CANONICAL-42',
            'medical_order_number' => 'ORD-STALE-42',
            'clinic_name' => 'Stale projected clinic',
            'phone' => '0000000000',
            'full_address' => 'Stale projected address',
        ]);

        $cabinet = CabinetSetting::current();
        $cabinet->update([
            'name' => 'عيادة الشفاء',
            'phone' => '+213 555 12 34 56',
            'email' => 'contact@medismart.test',
            'city' => 'الجزائر',
            'address' => '12, rue de la Liberté',
            'prescription_footer' => 'الاستقبال بالطابق الأوّل',
            'receipt_footer' => 'Paiement acquitté',
            'logo_path' => 'cabinet/logo-épreuve.png',
        ]);
        Storage::disk('public')->put('cabinet/logo-épreuve.png', 'logo');

        $branding = app(DocumentBrandingService::class);
        $identity = $branding->identity();
        $renderingIdentity = $branding->renderingIdentity();

        $this->assertSame('Dr Amel Benyahia', $identity['doctor_name']);
        $this->assertSame('Cardiologie pédiatrique', $identity['specialty']);
        $this->assertSame('cardiologie_pediatrique', $identity['specialty_code']);
        $this->assertSame('ORD-CANONICAL-42', $identity['medical_order_number']);
        $this->assertSame('عيادة الشفاء', $identity['clinic_name']);
        $this->assertSame('cabinet/logo-épreuve.png', $identity['logo_path']);
        $this->assertSame('Paiement acquitté', $identity['receipt_footer']);
        $this->assertSame(
            'Tél. +213 555 12 34 56 | E-mail contact@medismart.test | Adresse : 12, rue de la Liberté, الجزائر | الاستقبال بالطابق الأوّل',
            $identity['footer'],
        );
        $this->assertTrue(mb_check_encoding($identity['footer'], 'UTF-8'));
        $this->assertStringNotContainsString('TÃ©l.', $identity['footer']);
        $this->assertSame('ORD-CANONICAL-42', $renderingIdentity['order_number']);
        $this->assertSame('12, rue de la Liberté, الجزائر', $renderingIdentity['address_line']);
        $this->assertSame(
            Storage::disk('public')->url('cabinet/logo-épreuve.png'),
            $renderingIdentity['logo_url'],
        );
    }
}
