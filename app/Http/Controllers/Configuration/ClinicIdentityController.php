<?php

namespace App\Http\Controllers\Configuration;

use App\Enums\RoleName;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CabinetSetting;
use App\Models\DoctorProfile;
use App\Services\DocumentBrandingService;
use App\Support\MedicalSpecialtyCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ClinicIdentityController extends Controller
{
    public function edit(
        Request $request,
        DocumentBrandingService $branding,
        MedicalSpecialtyCatalog $specialties,
    ): Response {
        $cabinet = CabinetSetting::current();
        $doctor = DoctorProfile::query()->with('user')->active()->first();
        $identity = $branding->identity($doctor, $cabinet);
        $renderingIdentity = $branding->renderingIdentity($doctor, $cabinet);

        return Inertia::render('configuration/ClinicIdentity', [
            'identity' => [
                'doctor_name' => $identity['doctor_name'],
                'specialty' => $identity['specialty'],
                'professional_identifier' => $identity['medical_order_number'],
                'clinic_name' => $identity['clinic_name'],
                'phone' => $identity['phone'],
                'email' => $identity['email'],
                'city' => $identity['city'],
                'address' => $identity['full_address'],
                'footer_line' => $identity['footer_extra_line'],
                'logo_url' => $renderingIdentity['logo_url'],
                'has_custom_logo' => filled($identity['logo_path']),
            ],
            'specialtySuggestions' => $specialties->labels(),
            'customBrandingCapability' => $this->customBrandingCapability(),
            'permissions' => [
                'can_correct_specialty' => $request->user()?->hasRole(RoleName::SUPER_ADMINISTRATOR->value) ?? false,
                'sensitive_actions_confirmed' => $this->recentPasswordConfirmation($request),
            ],
        ]);
    }

    public function confirmSpecialtyCorrection(Request $request): RedirectResponse
    {
        abort_unless(
            $request->user()?->hasRole(RoleName::SUPER_ADMINISTRATOR->value),
            403,
        );

        $request->session()->put(
            'url.intended',
            route('app.configuration.identity.edit'),
        );

        return to_route('password.confirm');
    }

    public function correctSpecialty(
        Request $request,
        MedicalSpecialtyCatalog $specialties,
    ): RedirectResponse {
        $actor = $request->user();

        abort_unless(
            $actor?->hasRole(RoleName::SUPER_ADMINISTRATOR->value),
            403,
        );

        $data = $request->validate([
            'specialty' => ['required', 'string', 'min:2', 'max:150'],
            'confirmation' => ['required', 'accepted'],
        ]);
        $doctor = DoctorProfile::current();

        abort_if($doctor === null, 409, 'Aucun profil médical actif ne peut être corrigé.');

        $specialty = $specialties->display(trim($data['specialty']));
        $specialtyCode = $specialties->codeFor($specialty);

        if ($doctor->specialty === $specialty && $doctor->specialty_code === $specialtyCode) {
            Inertia::flash('toast', [
                'type' => 'info',
                'message' => 'La spécialité est déjà enregistrée avec cette valeur.',
            ]);

            return back();
        }

        $doctor->correctLockedSpecialty($specialty, $specialtyCode, $actor);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Spécialité corrigée et modification consignée dans le journal d’audit.',
        ]);

        return back();
    }

    public function update(
        Request $request,
    ): RedirectResponse {
        $cabinet = CabinetSetting::current();
        $doctor = DoctorProfile::query()->with('user')->active()->first();

        $data = $request->validate([
            'doctor_name' => ['nullable', 'string', 'max:255'],
            'professional_identifier' => [
                'nullable',
                'string',
                'max:120',
                Rule::unique('doctor_profiles', 'professional_identifier')->ignore($doctor?->id),
                Rule::unique('doctor_profiles', 'medical_order_number')->ignore($doctor?->id),
            ],
            'clinic_name' => ['required', 'string', 'max:255'],
            'phone' => [
                'nullable',
                'string',
                'max:30',
                'regex:/^\\+?[0-9][0-9\\s().\\/-]{5,28}$/',
            ],
            'email' => ['nullable', 'email', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:500'],
            'footer_line' => ['nullable', 'string', 'max:500'],
            'logo' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:2048',
                'dimensions:min_width=64,min_height=64,max_width=3000,max_height=3000',
            ],
        ]);

        $newLogoPath = null;
        $logo = $request->file('logo');

        if ($logo !== null) {
            $storedLogoPath = $logo->store('cabinet', 'public');

            if (! is_string($storedLogoPath) || $storedLogoPath === '') {
                throw new \RuntimeException('The clinic logo could not be stored.');
            }

            $newLogoPath = $storedLogoPath;
        }
        /** @var list<string> $oldLogoPaths */
        $oldLogoPaths = array_values(array_unique(array_filter([
            $cabinet->logo_path,
            $doctor?->logo_path,
        ], static fn (mixed $path): bool => is_string($path) && $path !== '')));

        try {
            DB::transaction(function () use ($request, $cabinet, $doctor, $data, $newLogoPath): void {
                $cabinet->update([
                    'name' => $data['clinic_name'],
                    'phone' => ($data['phone'] ?? '') ?: null,
                    'email' => ($data['email'] ?? '') ?: null,
                    'city' => ($data['city'] ?? '') ?: null,
                    'address' => ($data['address'] ?? '') ?: null,
                    'prescription_footer' => ($data['footer_line'] ?? '') ?: null,
                    'logo_path' => $newLogoPath ?: $cabinet->logo_path,
                ]);

                if ($doctor !== null) {
                    $doctor->update([
                        'professional_identifier' => ($data['professional_identifier'] ?? '') ?: null,
                        'medical_order_number' => ($data['professional_identifier'] ?? '') ?: null,
                        'doctor_name' => ($data['doctor_name'] ?? '') ?: $doctor->doctor_name,
                        'clinic_name' => $data['clinic_name'],
                        'phone' => ($data['phone'] ?? '') ?: null,
                        'email' => ($data['email'] ?? '') ?: null,
                        'city' => ($data['city'] ?? '') ?: null,
                        'full_address' => ($data['address'] ?? '') ?: null,
                        'footer_extra_line' => ($data['footer_line'] ?? '') ?: null,
                        'logo_path' => $newLogoPath ?: $doctor->logo_path ?: $cabinet->logo_path,
                    ]);

                    if ($doctor->user !== null && filled($data['doctor_name'] ?? null)) {
                        $doctor->user->update(['name' => $data['doctor_name']]);
                    }
                }

                AuditLog::record('settings.clinic_identity_updated', $cabinet, [
                    'keys' => [
                        'doctor_name',
                        'professional_identifier',
                        'clinic_name',
                        'phone',
                        'email',
                        'city',
                        'address',
                        'footer_line',
                    ],
                    'logo_changed' => $newLogoPath !== null,
                ], $request->user()?->getKey());
            });
        } catch (\Throwable $exception) {
            if ($newLogoPath !== null) {
                Storage::disk('public')->delete($newLogoPath);
            }

            throw $exception;
        }

        if ($newLogoPath !== null) {
            Storage::disk('public')->delete(array_values(array_filter(
                $oldLogoPaths,
                static fn (string $path): bool => $path !== $newLogoPath,
            )));
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Informations du cabinet enregistrées.',
        ]);

        return back();
    }

    public function destroyLogo(Request $request): RedirectResponse
    {
        $cabinet = CabinetSetting::current();
        $doctor = DoctorProfile::query()->active()->first();

        $paths = array_values(array_unique(array_filter([
            $cabinet->logo_path,
            $doctor?->logo_path,
        ])));

        DB::transaction(function () use ($request, $cabinet, $doctor): void {
            $cabinet->update(['logo_path' => null]);
            $doctor?->update(['logo_path' => null]);
            AuditLog::record(
                'settings.clinic_logo_removed',
                $cabinet,
                userId: $request->user()?->getKey(),
            );
        });
        Storage::disk('public')->delete($paths);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Logo du cabinet supprimé.',
        ]);

        return back();
    }

    private function recentPasswordConfirmation(Request $request): bool
    {
        $confirmedAt = (int) $request->session()->get('auth.password_confirmed_at', 0);
        $timeout = max(1, (int) config('auth.password_timeout', 10800));

        return $confirmedAt >= time() - $timeout;
    }

    /** @return array{available: bool, reason: string|null} */
    private function customBrandingCapability(): array
    {
        return [
            'available' => true,
            'reason' => null,
        ];
    }
}
