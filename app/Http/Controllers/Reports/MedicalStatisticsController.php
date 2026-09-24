<?php

namespace App\Http\Controllers\Reports;

use App\Enums\PatientAlertType;
use App\Http\Controllers\Controller;
use App\Models\Consultation;
use App\Models\ConsultationDiagnosis;
use App\Models\Patient;
use App\Models\PatientAlert;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Activity and epidemiology of the practice: what the doctor treats, and
 * who the patients are. Aggregated in PHP (SQLite and MySQL alike).
 */
class MedicalStatisticsController extends Controller
{
    /** CIM-10 chapters by first letter. */
    private const CHAPTERS = [
        'A' => 'Maladies infectieuses et parasitaires', 'B' => 'Maladies infectieuses et parasitaires',
        'C' => 'Tumeurs', 'D' => 'Sang et tumeurs bénignes',
        'E' => 'Endocrinologie, nutrition, métabolisme', 'F' => 'Troubles mentaux',
        'G' => 'Système nerveux', 'H' => 'Œil et oreille', 'I' => 'Appareil circulatoire',
        'J' => 'Appareil respiratoire', 'K' => 'Appareil digestif', 'L' => 'Peau',
        'M' => 'Ostéo-articulaire et muscles', 'N' => 'Appareil génito-urinaire',
        'O' => 'Grossesse et accouchement', 'P' => 'Période périnatale', 'Q' => 'Malformations congénitales',
        'R' => 'Symptômes et signes', 'S' => 'Traumatismes', 'T' => 'Traumatismes et intoxications',
        'U' => 'Codes spéciaux', 'Z' => 'Recours aux soins (bilans, vaccins, suivi)',
    ];

    private const AGE_GROUPS = [
        [0, 4, '0 – 4 ans'], [5, 14, '5 – 14 ans'], [15, 24, '15 – 24 ans'], [25, 44, '25 – 44 ans'],
        [45, 64, '45 – 64 ans'], [65, 200, '65 ans et +'],
    ];

    public function __invoke(Request $request): Response
    {
        $now = CarbonImmutable::now();
        $validated = $request->validate([
            'year' => ['nullable', 'integer', 'min:2000', 'max:'.($now->year + 1)],
            'month' => ['nullable', 'integer', 'between:1,12'],
        ]);
        $year = (int) ($validated['year'] ?? $now->year);
        $month = isset($validated['month']) ? (int) $validated['month'] : null;
        $start = $now->setDate($year, $month ?? 1, 1)->startOfDay();
        $end = $month !== null ? $start->endOfMonth() : $start->endOfYear();

        $consultations = Consultation::query()
            ->whereBetween('consulted_at', [$start, $end])
            ->get(['id', 'patient_id', 'consulted_at']);
        $consultationIds = $consultations->pluck('id')->all();
        $patientIds = $consultations->pluck('patient_id')->unique()->values()->all();

        $diagnoses = ConsultationDiagnosis::query()
            ->whereIn('consultation_id', $consultationIds)
            ->get(['consultation_id', 'patient_id', 'code', 'label']);

        $patients = Patient::query()
            ->withTrashed()
            ->whereIn('id', $patientIds)
            ->get(['id', 'date_of_birth', 'gender', 'city']);

        return Inertia::render('statistics/Index', [
            'period' => [
                'year' => $year,
                'month' => $month,
                'from' => $start->toDateString(),
                'to' => $end->toDateString(),
            ],
            'summary' => [
                'consultations' => $consultations->count(),
                'patients' => count($patientIds),
                'new_patients' => Patient::query()->whereBetween('created_at', [$start, $end])->count(),
                'coded_share' => $consultations->count() > 0
                    ? round($diagnoses->pluck('consultation_id')->unique()->count() / $consultations->count() * 100, 1)
                    : null,
            ],
            'monthly' => $this->monthly($consultations, $year),
            'topDiagnoses' => $diagnoses
                ->groupBy('code')
                ->map(static fn ($rows, string $code): array => [
                    'code' => $code,
                    'label' => (string) $rows->first()?->label,
                    'value' => $rows->count(),
                    'patients' => $rows->pluck('patient_id')->unique()->count(),
                ])
                ->sortByDesc('value')
                ->take(15)
                ->values()
                ->all(),
            'chapters' => $diagnoses
                ->groupBy(static fn (ConsultationDiagnosis $diagnosis): string => self::CHAPTERS[strtoupper($diagnosis->code[0] ?? '')] ?? 'Autres')
                ->map(static fn ($rows, string $label): array => ['label' => $label, 'value' => $rows->count()])
                ->sortByDesc('value')
                ->values()
                ->all(),
            'demographics' => $this->demographics($patients, $now),
            'chronic' => PatientAlert::query()
                ->where('type', PatientAlertType::CONDITION->value)
                ->where('is_active', true)
                ->get(['patient_id', 'label'])
                ->groupBy(static fn (PatientAlert $alert): string => Str::of($alert->label)->ascii()->lower()->squish()->toString())
                ->map(static fn ($rows): array => [
                    'label' => (string) $rows->first()?->label,
                    'value' => $rows->pluck('patient_id')->unique()->count(),
                ])
                ->sortByDesc('value')
                ->take(10)
                ->values()
                ->all(),
            'totalPatients' => Patient::query()->count(),
        ]);
    }

    /**
     * @param  Collection<int, Consultation>  $consultations
     * @return list<array{label: string, value: int}>
     */
    private function monthly($consultations, int $year): array
    {
        $labels = ['Janv.', 'Févr.', 'Mars', 'Avr.', 'Mai', 'Juin', 'Juil.', 'Août', 'Sept.', 'Oct.', 'Nov.', 'Déc.'];
        $counts = array_fill(1, 12, 0);

        $all = Consultation::query()
            ->whereBetween('consulted_at', [CarbonImmutable::create($year, 1, 1), CarbonImmutable::create($year, 12, 31, 23, 59, 59)])
            ->pluck('consulted_at');

        foreach ($all as $consultedAt) {
            if ($consultedAt instanceof CarbonImmutable) {
                $counts[(int) $consultedAt->month]++;
            }
        }

        $rows = [];
        foreach ($labels as $index => $label) {
            $rows[] = ['label' => $label, 'value' => $counts[$index + 1]];
        }

        return $rows;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, Patient>  $patients
     * @return array<string, mixed>
     */
    private function demographics($patients, CarbonImmutable $now): array
    {
        $ages = array_fill_keys(array_column(self::AGE_GROUPS, 2), 0);
        $sexes = ['female' => 0, 'male' => 0, 'unknown' => 0];
        $cities = [];

        foreach ($patients as $patient) {
            $birth = $patient->date_of_birth;

            if ($birth !== null) {
                $age = (int) $birth->diffInYears($now);

                foreach (self::AGE_GROUPS as [$min, $max, $label]) {
                    if ($age >= $min && $age <= $max) {
                        $ages[$label]++;

                        break;
                    }
                }
            }

            $gender = $patient->gender?->value;
            $sexes[in_array($gender, ['female', 'male'], true) ? $gender : 'unknown']++;

            $city = trim((string) $patient->city);

            if ($city !== '') {
                $key = Str::of($city)->ascii()->lower()->toString();
                $cities[$key] ??= ['label' => Str::title($city), 'value' => 0];
                $cities[$key]['value']++;
            }
        }

        usort($cities, static fn (array $a, array $b): int => $b['value'] <=> $a['value']);

        return [
            'ages' => array_map(static fn (string $label, int $value): array => ['label' => $label, 'value' => $value], array_keys($ages), $ages),
            'sexes' => [
                ['label' => 'Femmes', 'value' => $sexes['female']],
                ['label' => 'Hommes', 'value' => $sexes['male']],
                ['label' => 'Non renseigné', 'value' => $sexes['unknown']],
            ],
            'cities' => array_slice($cities, 0, 8),
        ];
    }
}
