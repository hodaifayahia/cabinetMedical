<?php

namespace App\Services\Clinical;

use App\Models\Patient;
use App\Models\PatientVaccination;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The Algerian national childhood immunisation calendar (MSPRH, 2016
 * revision) as a guide: which doses are done, due or overdue for a child.
 * The doctor stays free to record any vaccine; the page reminds them to
 * check the calendar in force.
 */
final class VaccinationSchedule
{
    /**
     * Age in months → vaccines. Keys are stable identifiers stored on the
     * recorded doses.
     */
    public const CALENDAR = [
        ['key' => 'birth', 'months' => 0, 'label' => 'Naissance', 'vaccines' => [
            'bcg' => 'BCG', 'vpo' => 'VPO (polio oral) – dose 0', 'hbv' => 'Hépatite B – dose 1',
        ]],
        ['key' => 'm2', 'months' => 2, 'label' => '2 mois', 'vaccines' => [
            'penta' => 'Pentavalent (DTC-Hib-HBV) – dose 1', 'vpo' => 'VPO – dose 1', 'pneumo' => 'Pneumocoque – dose 1',
        ]],
        ['key' => 'm4', 'months' => 4, 'label' => '4 mois', 'vaccines' => [
            'penta' => 'Pentavalent – dose 2', 'vpo' => 'VPO – dose 2', 'vpi' => 'VPI (polio injectable)', 'pneumo' => 'Pneumocoque – dose 2',
        ]],
        ['key' => 'm11', 'months' => 11, 'label' => '11 mois', 'vaccines' => [
            'ror' => 'ROR – dose 1',
        ]],
        ['key' => 'm12', 'months' => 12, 'label' => '12 mois', 'vaccines' => [
            'penta' => 'Pentavalent – rappel', 'vpo' => 'VPO – dose 3', 'pneumo' => 'Pneumocoque – rappel',
        ]],
        ['key' => 'm18', 'months' => 18, 'label' => '18 mois', 'vaccines' => [
            'ror' => 'ROR – dose 2',
        ]],
        ['key' => 'y6', 'months' => 72, 'label' => '6 ans', 'vaccines' => [
            'dtc' => 'DTC enfant – rappel', 'vpo' => 'VPO – rappel',
        ]],
        ['key' => 'y11', 'months' => 132, 'label' => '11 – 13 ans', 'vaccines' => [
            'dt' => 'dT adulte – rappel', 'vpo' => 'VPO – rappel',
        ]],
        ['key' => 'y16', 'months' => 192, 'label' => '16 – 18 ans', 'vaccines' => [
            'dt' => 'dT adulte – rappel',
        ]],
    ];

    /** Common vaccines offered in the « record a vaccine » list. */
    public const VACCINES = [
        'BCG', 'Hépatite B', 'VPO (polio oral)', 'VPI (polio injectable)', 'Pentavalent (DTC-Hib-HBV)',
        'Hexavalent', 'Pneumocoque', 'ROR (rougeole-oreillons-rubéole)', 'DTC enfant', 'dT adulte',
        'Grippe saisonnière', 'COVID-19', 'Hépatite A', 'Méningocoque', 'Varicelle', 'HPV', 'Rage', 'Tétanos (VAT)', 'Fièvre typhoïde',
    ];

    /**
     * @return array<string, mixed>
     */
    public function forPatient(Patient $patient, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::now()->startOfDay();
        $records = PatientVaccination::query()
            ->where('patient_id', $patient->getKey())
            ->orderByDesc('given_on')
            ->get();

        $birth = $patient->date_of_birth;
        $ageMonths = $birth instanceof CarbonInterface ? (int) CarbonImmutable::instance($birth)->diffInMonths($today) : null;
        $done = $records->pluck('schedule_key')->filter()->flip();

        $schedule = [];
        $overdue = 0;

        // The calendar is shown for children and teenagers (under 19).
        if ($ageMonths !== null && $ageMonths < 228 && $birth instanceof CarbonInterface) {
            foreach (self::CALENDAR as $slot) {
                $dueOn = CarbonImmutable::instance($birth)->addMonths($slot['months']);
                $doses = [];

                foreach ($slot['vaccines'] as $vaccineKey => $label) {
                    $key = $slot['key'].':'.$vaccineKey;
                    // One month of grace before a dose counts as late.
                    $status = isset($done[$key])
                        ? 'done'
                        : ($today->greaterThan($dueOn->addMonth()) ? 'overdue' : ($today->greaterThanOrEqualTo($dueOn) ? 'due' : 'upcoming'));
                    $overdue += $status === 'overdue' ? 1 : 0;
                    $doses[] = ['key' => $key, 'label' => $label, 'status' => $status];
                }

                $schedule[] = [
                    'key' => $slot['key'],
                    'label' => $slot['label'],
                    'due_on' => $dueOn->toDateString(),
                    'doses' => $doses,
                ];
            }
        }

        return [
            'records' => $records->map(static fn (PatientVaccination $vaccination): array => [
                'id' => $vaccination->public_id,
                'vaccine' => $vaccination->vaccine,
                'dose' => $vaccination->dose,
                'schedule_key' => $vaccination->schedule_key,
                'given_on' => $vaccination->given_on->toDateString(),
                'lot' => $vaccination->lot,
                'notes' => $vaccination->notes,
            ])->values()->all(),
            'schedule' => $schedule,
            'overdue' => $overdue,
            'vaccines' => self::VACCINES,
        ];
    }

    /**
     * Label of a calendar slot key such as "m2:penta", or null if unknown.
     */
    public function slotLabel(string $key): ?string
    {
        [$slotKey, $vaccineKey] = array_pad(explode(':', $key, 2), 2, '');

        foreach (self::CALENDAR as $slot) {
            if ($slot['key'] === $slotKey && isset($slot['vaccines'][$vaccineKey])) {
                return $slot['vaccines'][$vaccineKey];
            }
        }

        return null;
    }
}
