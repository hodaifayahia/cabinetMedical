<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Models\Cabinet;
use App\Models\User;
use App\Services\Demo\DemoCabinetData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Command-line entry to DemoCabinetData (the desktop's « Données de
 * démonstration » page is the other one). In production, names are
 * prefixed « Démo » and the cabinet must be named explicitly with
 * DEMO_CABINET_ID and DEMO_DOCTOR_EMAIL.
 */
class DemoClinicSeeder extends Seeder
{
    public function run(DemoCabinetData $demo): void
    {
        $cabinetId = getenv('DEMO_CABINET_ID') ?: null;
        $doctorEmail = trim((string) (getenv('DEMO_DOCTOR_EMAIL') ?: ''));

        if (app()->isProduction() && (! $cabinetId || ! ctype_digit($cabinetId) || $doctorEmail === '')) {
            throw new RuntimeException('Production demo data requires DEMO_CABINET_ID and DEMO_DOCTOR_EMAIL.');
        }

        $cabinet = Cabinet::query()->findOrFail((int) ($cabinetId ?: 1));
        $doctor = User::query()
            ->where('cabinet_id', $cabinet->getKey())
            ->when($doctorEmail !== '', static fn ($query) => $query->where('email', $doctorEmail))
            ->whereHas('roles', static fn ($query) => $query->where('name', RoleName::DOCTOR->value))
            ->orderBy('id')
            ->first() ?? throw new RuntimeException('Aucun médecin correspondant dans ce cabinet.');

        // Tenant-owned models take their cabinet from the signed-in user.
        Auth::login($doctor);

        $counts = $demo->fill($doctor, realisticNames: ! app()->isProduction());

        $this->command?->info(sprintf(
            'Démo prête pour « %s » : %d patients, %d consultations, %d rendez-vous.',
            $cabinet->name,
            $counts['patients'],
            $counts['consultations'],
            $counts['appointments'],
        ));
    }
}
