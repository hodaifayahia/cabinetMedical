<?php

namespace Tests\Unit\Support;

use App\Support\MedicalSpecialtyCatalog;
use PHPUnit\Framework\TestCase;

class MedicalSpecialtyCatalogTest extends TestCase
{
    public function test_it_localizes_legacy_specialties_and_keeps_stable_codes(): void
    {
        $catalog = new MedicalSpecialtyCatalog;

        $this->assertSame('Médecine générale', $catalog->display('General Medicine'));
        $this->assertSame('Médecine générale', $catalog->display('General Medicine', 'general_medicine'));
        $this->assertSame('general_medicine', $catalog->codeFor('Médecine générale'));
        $this->assertSame('pediatrics', $catalog->codeFor('Pédiatrie'));
    }

    public function test_it_accepts_custom_unicode_specialties_with_a_stable_nonempty_code(): void
    {
        $catalog = new MedicalSpecialtyCatalog;
        $specialty = 'طب الأسرة';

        $this->assertSame($specialty, $catalog->display($specialty));
        $this->assertNotSame('', $catalog->codeFor($specialty));
        $this->assertSame($catalog->codeFor($specialty), $catalog->codeFor($specialty));
    }
}
