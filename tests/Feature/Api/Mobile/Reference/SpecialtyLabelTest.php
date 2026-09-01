<?php

namespace Tests\Feature\Api\Mobile\Reference;

use App\Support\SpecialtyArabicLabels;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * DoctorProfile slugs the raw specialty string into specialty_code, so a clinic
 * that typed a French name stores "pediatrie" while the catalogue key is
 * "pediatrics". The Arabic-first mobile client must still get Arabic.
 */
class SpecialtyLabelTest extends TestCase
{
    #[Test]
    public function a_french_derived_code_resolves_to_the_arabic_label(): void
    {
        $this->assertSame('طب الأطفال', SpecialtyArabicLabels::labelFor('pediatrie'));
        $this->assertSame('أمراض القلب', SpecialtyArabicLabels::labelFor('cardiologie'));
        $this->assertSame('الأنف والأذن والحنجرة', SpecialtyArabicLabels::labelFor('orl'));
        $this->assertSame('الطب العام', SpecialtyArabicLabels::labelFor('medecine_generale'));
    }

    #[Test]
    public function canonical_codes_still_resolve(): void
    {
        $this->assertSame('طب الأطفال', SpecialtyArabicLabels::labelFor('pediatrics'));
        $this->assertSame('pediatrics', SpecialtyArabicLabels::canonicalCode('pediatrics'));
    }

    #[Test]
    public function accents_and_casing_are_folded(): void
    {
        $this->assertSame('pediatrics', SpecialtyArabicLabels::canonicalCode('Pédiatrie'));
        $this->assertSame('pediatrics', SpecialtyArabicLabels::canonicalCode('PEDIATRIE'));
        $this->assertSame('obstetrics_gynecology', SpecialtyArabicLabels::canonicalCode('Gynécologie-obstétrique'));
    }

    #[Test]
    public function an_unknown_code_is_returned_unchanged_and_has_no_arabic_label(): void
    {
        $this->assertSame('specialty_zzz', SpecialtyArabicLabels::canonicalCode('specialty_zzz'));
        $this->assertNull(SpecialtyArabicLabels::labelFor('specialty_zzz'));
        $this->assertNull(SpecialtyArabicLabels::labelFor(null));
    }
}
