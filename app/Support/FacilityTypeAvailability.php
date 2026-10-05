<?php

namespace App\Support;

use App\Enums\FacilityType;
use App\Models\ApplicationSetting;

/**
 * Which kinds of place the patient app offers at all: doctors' practices,
 * clinics, imaging centres.
 *
 * A platform admin switches a whole kind off (say, imaging until the first
 * centres have signed up), and from then on its search tab disappears and its
 * cabinets are unreachable from the public API, exactly like a region outside
 * coverage. The cabinets themselves keep working; only discovery and patient
 * booking change.
 *
 * Stored as the list of DISABLED types, so every type ships enabled and a type
 * added to the enum later is on by default.
 */
final class FacilityTypeAvailability
{
    public const SETTING_KEY = 'directory.disabled_facility_types';

    /**
     * @return list<string>
     */
    public function disabledValues(): array
    {
        $stored = ApplicationSetting::valueFor(self::SETTING_KEY, []);

        if (! is_array($stored)) {
            return [];
        }

        return array_values(array_intersect(FacilityType::values(), $stored));
    }

    /**
     * @return list<FacilityType>
     */
    public function enabled(): array
    {
        $disabled = $this->disabledValues();

        return array_values(array_filter(
            FacilityType::cases(),
            static fn (FacilityType $type): bool => ! in_array($type->value, $disabled, true),
        ));
    }

    public function isEnabled(FacilityType|string|null $type): bool
    {
        $value = $type instanceof FacilityType ? $type->value : $type;

        // A cabinet with no type predates the column and is a doctor's practice.
        return ! in_array($value ?? FacilityType::DOCTOR->value, $this->disabledValues(), true);
    }

    public function setEnabled(FacilityType $type, bool $enabled): void
    {
        $disabled = array_values(array_filter(
            $this->disabledValues(),
            static fn (string $value): bool => $value !== $type->value,
        ));

        if (! $enabled) {
            $disabled[] = $type->value;
        }

        ApplicationSetting::putValue(self::SETTING_KEY, $disabled, type: 'json', group: 'directory');
    }
}
