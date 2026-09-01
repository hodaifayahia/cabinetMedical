<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Arabic display labels for the medical specialty catalogue, keyed by the same
 * slugs as MedicalSpecialtyCatalog. Consumers fall back to the French label
 * when a slug is missing here.
 *
 * DoctorProfile stores specialty_code by slugging the raw specialty string
 * rather than resolving it through the catalogue, so a clinic that typed a
 * French name ends up with a French-derived code ("pediatrie") that is not a
 * catalogue key ("pediatrics"). canonicalCode() folds those variants back onto
 * the canonical slug so the Arabic-first mobile client does not silently fall
 * back to French, and so the same specialty is not listed twice under two
 * codes.
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
     * Accent-folded slugs of the catalogue's French labels, mapped onto the
     * canonical code. Keys are lowercase ASCII slugs so "Pédiatrie",
     * "pediatrie" and "PEDIATRIE" all resolve alike.
     *
     * @var array<string, string>
     */
    private const FRENCH_SLUG_ALIASES = [
        'medecine_generale' => 'general_medicine',
        'medecine_familiale' => 'family_medicine',
        'medecine_interne' => 'internal_medicine',
        'medecine_du_travail' => 'occupational_medicine',
        'anesthesie_reanimation' => 'anesthesiology',
        'cardiologie' => 'cardiology',
        'chirurgie_generale' => 'general_surgery',
        'dermatologie' => 'dermatology',
        'endocrinologie_et_diabetologie' => 'endocrinology',
        'endocrinologie' => 'endocrinology',
        'gastro_enterologie' => 'gastroenterology',
        'gynecologie_obstetrique' => 'obstetrics_gynecology',
        'gynecologie' => 'obstetrics_gynecology',
        'nephrologie' => 'nephrology',
        'neurologie' => 'neurology',
        'ophtalmologie' => 'ophthalmology',
        'orl' => 'otorhinolaryngology',
        'pediatrie' => 'pediatrics',
        'pneumologie' => 'pulmonology',
        'psychiatrie' => 'psychiatry',
        'radiologie' => 'radiology',
        'rhumatologie' => 'rheumatology',
        'urologie' => 'urology',
    ];

    /**
     * Resolve a stored specialty code onto the catalogue's canonical slug.
     * Unknown codes are returned unchanged so callers keep their own value.
     */
    public static function canonicalCode(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        $trimmed = trim($code);

        if ($trimmed === '' || isset(self::LABELS[$trimmed])) {
            return $trimmed === '' ? $code : $trimmed;
        }

        $slug = Str::of($trimmed)->ascii()->lower()->slug('_')->toString();

        if (isset(self::LABELS[$slug])) {
            return $slug;
        }

        return self::FRENCH_SLUG_ALIASES[$slug] ?? $trimmed;
    }

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

        return self::LABELS[self::canonicalCode($code) ?? $code] ?? null;
    }
}
