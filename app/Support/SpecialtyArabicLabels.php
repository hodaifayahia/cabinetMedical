<?php

namespace App\Support;

/**
 * Arabic display labels for the medical specialty catalogue, keyed by the same
 * slugs as MedicalSpecialtyCatalog. Consumers fall back to the French label
 * when a slug is missing here.
 */
final class SpecialtyArabicLabels
{
    /** @var array<string, string> */
    private const LABELS = [
        'general_medicine' => 'الطب العام',
        'family_medicine' => 'طب الأسرة',
        'internal_medicine' => 'الطب الداخلي',
        'occupational_medicine' => 'طب العمل',
        'anesthesiology' => 'التخدير والإنعاش',
        'cardiology' => 'أمراض القلب',
        'general_surgery' => 'الجراحة العامة',
        'dermatology' => 'الأمراض الجلدية',
        'endocrinology' => 'الغدد الصماء والسكري',
        'gastroenterology' => 'أمراض الجهاز الهضمي',
        'obstetrics_gynecology' => 'أمراض النساء والتوليد',
        'nephrology' => 'أمراض الكلى',
        'neurology' => 'طب الأعصاب',
        'ophthalmology' => 'طب العيون',
        'otorhinolaryngology' => 'الأنف والأذن والحنجرة',
        'pediatrics' => 'طب الأطفال',
        'pulmonology' => 'أمراض الجهاز التنفسي',
        'psychiatry' => 'الطب النفسي',
        'radiology' => 'الأشعة والتصوير الطبي',
        'rheumatology' => 'أمراض الروماتيزم',
        'urology' => 'جراحة المسالك البولية',
    ];

    /**
     * The full Arabic label map keyed by specialty slug.
     *
     * @return array<string, string>
     */
    public static function map(): array
    {
        return self::LABELS;
    }

    /**
     * The Arabic label for a specialty slug, or null when the slug is unknown
     * (callers then fall back to the French label).
     */
    public static function labelFor(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        return self::LABELS[$code] ?? null;
    }
}
