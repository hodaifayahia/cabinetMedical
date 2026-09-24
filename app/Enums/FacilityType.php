<?php

namespace App\Enums;

/**
 * What kind of place a cabinet is, as the patient app's search tabs see it.
 *
 * A cabinet is exactly one of these. The tabs in the mobile directory filter
 * on it, so reclassifying a cabinet moves it between tabs immediately.
 */
enum FacilityType: string
{
    case DOCTOR = 'doctor';
    case CLINIC = 'clinic';
    case RADIOLOGY = 'radiology';

    public function label(): string
    {
        return match ($this) {
            self::DOCTOR => 'Cabinet médical',
            self::CLINIC => 'Clinique',
            self::RADIOLOGY => 'Centre d\'imagerie',
        };
    }

    /** The Arabic label the mobile app shows; the app is Arabic-first. */
    public function labelAr(): string
    {
        return match ($this) {
            self::DOCTOR => 'عيادة طبيب',
            self::CLINIC => 'عيادة متعددة الخدمات',
            self::RADIOLOGY => 'مركز أشعة',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
