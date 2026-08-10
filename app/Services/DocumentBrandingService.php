<?php

namespace App\Services;

use App\Models\CabinetSetting;
use App\Models\DoctorProfile;
use App\Support\MedicalSpecialtyCatalog;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class DocumentBrandingService
{
    public const DEFAULT_LOGO_URL = '/brand/drclick-mark.png';

    public function __construct(
        private readonly MedicalSpecialtyCatalog $specialties,
    ) {}

    /**
     * @return array{
     *     doctor_name: string,
     *     specialty: string,
     *     specialty_code: string|null,
     *     medical_order_number: string,
     *     clinic_name: string,
     *     phone: string,
     *     email: string,
     *     city: string,
     *     full_address: string,
     *     footer_extra_line: string,
     *     receipt_footer: string,
     *     footer: string,
     *     logo_path: string|null
     * }
     */
    public function identity(?DoctorProfile $doctor = null, ?CabinetSetting $cabinet = null): array
    {
        $doctor ??= DoctorProfile::query()->active()->with('user:id,name')->first();
        $cabinet ??= CabinetSetting::current();
        $doctor?->loadMissing('user:id,name');

        $identity = [
            // Existing User/CabinetSetting fields remain canonical during the
            // incremental migration; the new profile fields are projections.
            'doctor_name' => trim((string) ($doctor?->user?->name ?: $doctor?->doctor_name)),
            'specialty' => $this->specialties->display(
                $doctor?->specialty,
                $doctor?->specialty_code,
            ),
            'specialty_code' => $doctor?->specialty_code,
            'medical_order_number' => trim((string) ($doctor?->professional_identifier ?: $doctor?->medical_order_number)),
            'clinic_name' => trim((string) ($cabinet->name ?: $doctor?->clinic_name)),
            'phone' => trim((string) ($cabinet->phone ?: $doctor?->phone)),
            'email' => trim((string) ($cabinet->email ?: $doctor?->email)),
            'city' => trim((string) ($cabinet->city ?: $doctor?->city)),
            'full_address' => trim((string) ($cabinet->address ?: $doctor?->full_address)),
            'footer_extra_line' => trim((string) ($cabinet->prescription_footer ?: $doctor?->footer_extra_line)),
            'receipt_footer' => trim((string) ($cabinet->receipt_footer ?? '')),
            'logo_path' => $cabinet->logo_path ?: $doctor?->logo_path,
        ];

        return [...$identity, 'footer' => $this->footer($identity)];
    }

    /**
     * Canonical payload for printable HTML and Vue document renderers.
     *
     * Aliases such as `order_number` and `address` belong only to this
     * rendering boundary. The canonical persisted names remain explicit in
     * the same payload so new document builders do not need to reconstruct
     * identity or contact lines independently.
     *
     * @return array{
     *     doctor_name: string,
     *     specialty: string,
     *     specialty_code: string|null,
     *     medical_order_number: string,
     *     order_number: string,
     *     clinic_name: string,
     *     phone: string,
     *     email: string,
     *     city: string,
     *     full_address: string,
     *     address: string,
     *     address_line: string,
     *     footer_extra_line: string,
     *     receipt_footer: string,
     *     footer: string,
     *     logo_url: string
     * }
     */
    public function renderingIdentity(
        ?DoctorProfile $doctor = null,
        ?CabinetSetting $cabinet = null,
    ): array {
        $identity = $this->identity($doctor, $cabinet);

        return [
            'doctor_name' => $identity['doctor_name'],
            'specialty' => $identity['specialty'],
            'specialty_code' => $identity['specialty_code'],
            'medical_order_number' => $identity['medical_order_number'],
            'order_number' => $identity['medical_order_number'],
            'clinic_name' => $identity['clinic_name'],
            'phone' => $identity['phone'],
            'email' => $identity['email'],
            'city' => $identity['city'],
            'full_address' => $identity['full_address'],
            'address' => $identity['full_address'],
            'address_line' => $this->addressLine($identity),
            'footer_extra_line' => $identity['footer_extra_line'],
            'receipt_footer' => $identity['receipt_footer'],
            'footer' => $identity['footer'],
            'logo_url' => $this->logoUrl($identity['logo_path']),
        ];
    }

    /** @param array<string, string|null> $identity */
    public function footer(array $identity): string
    {
        $address = $this->addressLine($identity);

        return implode(' | ', array_filter([
            filled($identity['phone'] ?? null) ? 'Tél. '.trim((string) $identity['phone']) : null,
            filled($identity['email'] ?? null) ? 'E-mail '.trim((string) $identity['email']) : null,
            $address !== '' ? 'Adresse : '.$address : null,
            filled($identity['footer_extra_line'] ?? null) ? trim((string) $identity['footer_extra_line']) : null,
        ]));
    }

    /** @param array<string, string|null> $identity */
    private function addressLine(array $identity): string
    {
        return implode(', ', array_filter([
            trim((string) ($identity['full_address'] ?? '')),
            trim((string) ($identity['city'] ?? '')),
        ]));
    }

    private function logoUrl(?string $path): string
    {
        if (! is_string($path) || trim($path) === '') {
            return self::DEFAULT_LOGO_URL;
        }

        try {
            $disk = Storage::disk('public');

            return $disk->exists($path)
                ? $disk->url($path)
                : self::DEFAULT_LOGO_URL;
        } catch (Throwable) {
            return self::DEFAULT_LOGO_URL;
        }
    }
}
