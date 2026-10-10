<?php

namespace App\Services\Demo;

use App\Actions\Payments\RecordConsultationPaymentAction;
use App\Enums\AppointmentStatus;
use App\Enums\ExpenseCategory;
use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\ConsultationFee;
use App\Models\Expense;
use App\Models\Patient;
use App\Models\PatientAlert;
use App\Models\Payment;
use App\Models\Prescription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Fills the signed-in doctor's cabinet with a realistic week of activity
 * (patients, past visits with prescriptions and payments, today's agenda,
 * the coming days, expenses and common acts), and removes it again.
 *
 * Every record it creates carries the [démo] marker, which is what removal
 * relies on: a patient, appointment or expense entered by hand is never
 * touched. The doctor must be the authenticated user, since cabinet-owned
 * models take their cabinet from it.
 */
final class DemoCabinetData
{
    public const MARKER = '[démo]';

    private const PATIENTS = [
        // first name, last name, gender, birth date, city, allergy
        ['Karim', 'Haddad', 'male', '1968-03-14', 'Alger', 'Pénicilline'],
        ['Samira', 'Benali', 'female', '1985-11-02', 'Blida', null],
        ['Yacine', 'Mansouri', 'male', '1992-07-21', 'Oran', null],
        ['Nadia', 'Kaci', 'female', '1954-01-30', 'Tizi Ouzou', 'Aspirine'],
        ['Mohamed', 'Belkacem', 'male', '1976-09-09', 'Alger', null],
        ['Lina', 'Ferhat', 'female', '2016-05-17', 'Boumerdès', null],
        ['Rachid', 'Ouali', 'male', '1949-12-05', 'Sétif', null],
        ['Amel', 'Zerrouki', 'female', '1990-04-26', 'Constantine', 'Sulfamides'],
    ];

    /** @var list<array{motif: string, examens: string, diagnostic: string, traitement: string, bp: string, temp: float, meds: list<array{0: string, 1: string, 2: string}>}> */
    private const VISITS = [
        [
            'motif' => 'Toux productive et fièvre depuis 4 jours',
            'examens' => 'Râles crépitants base droite. SpO2 95 %. FR 20/min.',
            'diagnostic' => 'Pneumopathie communautaire base droite',
            'traitement' => 'Antibiothérapie 7 jours, antipyrétique, radio thoracique de contrôle.',
            'bp' => '12/7', 'temp' => 38.6,
            'meds' => [['Amoxicilline 1 g', '1 cp matin et soir', '7 jours'], ['Paracétamol 1 g', '1 cp si fièvre, max 3/j', '5 jours']],
        ],
        [
            'motif' => 'Suivi hypertension artérielle',
            'examens' => 'TA 15/9 aux deux bras. Auscultation cardiaque normale. Pas d’œdème.',
            'diagnostic' => 'HTA essentielle insuffisamment contrôlée',
            'traitement' => 'Majoration du traitement, régime hyposodé, bilan rénal et ionogramme.',
            'bp' => '15/9', 'temp' => 36.8,
            'meds' => [['Amlodipine 10 mg', '1 cp le matin', '3 mois']],
        ],
        [
            'motif' => 'Contrôle diabète de type 2',
            'examens' => 'Poids stable. Pieds : pas de lésion, monofilament normal.',
            'diagnostic' => 'Diabète de type 2 équilibré (HbA1c 6,9 %)',
            'traitement' => 'Poursuite metformine, HbA1c dans 3 mois, fond d’œil annuel.',
            'bp' => '13/8', 'temp' => 36.7,
            'meds' => [['Metformine 850 mg', '1 cp matin et soir au repas', '3 mois']],
        ],
        [
            'motif' => 'Douleurs épigastriques post-prandiales',
            'examens' => 'Abdomen souple, sensibilité épigastrique, pas de défense.',
            'diagnostic' => 'Syndrome dyspeptique, suspicion de gastrite',
            'traitement' => 'IPP 4 semaines, recherche Helicobacter pylori, règles hygiéno-diététiques.',
            'bp' => '12/8', 'temp' => 36.9,
            'meds' => [['Oméprazole 20 mg', '1 gélule le matin à jeun', '4 semaines']],
        ],
        [
            'motif' => 'Palpitations et fatigue',
            'examens' => 'Pouls irrégulier à 110/min. ECG demandé.',
            'diagnostic' => 'Suspicion de fibrillation atriale — ECG et bilan thyroïdien',
            'traitement' => 'ECG 12 dérivations, TSH, ionogramme, échographie cardiaque.',
            'bp' => '13/8', 'temp' => 36.6,
            'meds' => [['Bisoprolol 2,5 mg', '1 cp le matin', '1 mois']],
        ],
        [
            'motif' => 'Angine, odynophagie',
            'examens' => 'Amygdales érythémateuses, pas d’adénopathie. TDR négatif.',
            'diagnostic' => 'Angine virale',
            'traitement' => 'Traitement symptomatique, réévaluation si persistance > 5 jours.',
            'bp' => '11/7', 'temp' => 38.1,
            'meds' => [['Paracétamol 500 mg', '1 à 2 cp toutes les 6 h', '5 jours']],
        ],
        [
            'motif' => 'Lombalgie aiguë après port de charge',
            'examens' => 'Contracture paravertébrale, Lasègue négatif, pas de déficit.',
            'diagnostic' => 'Lombalgie commune aiguë',
            'traitement' => 'Antalgiques, AINS courte durée, maintien de l’activité.',
            'bp' => '12/8', 'temp' => 36.8,
            'meds' => [['Ibuprofène 400 mg', '1 cp 3 fois/j au repas', '5 jours'], ['Thiocolchicoside 4 mg', '1 cp matin et soir', '5 jours']],
        ],
    ];

    /** Common acts with their price in DA, added when missing and kept on removal. */
    private const ACTS = [
        ['Consultation', 2000],
        ['Consultation de contrôle', 1000],
        ['Certificat médical', 500],
        ['ECG', 1500],
    ];

    public function hasData(): bool
    {
        return Patient::query()->where('notes', 'like', self::MARKER.'%')->exists();
    }

    /**
     * @return array{patients: int, consultations: int, appointments: int, expenses: int}
     */
    public function counts(): array
    {
        $patientIds = $this->demoPatientIds();

        return [
            'patients' => count($patientIds),
            'consultations' => Consultation::query()->whereIn('patient_id', $patientIds)->count(),
            'appointments' => Appointment::query()->whereIn('patient_id', $patientIds)->count(),
            'expenses' => Expense::query()->where('label', 'like', self::MARKER.'%')->count(),
        ];
    }

    /**
     * @param  bool  $realisticNames  false prefixes « Démo » and leaves phones empty
     * @return array{patients: int, consultations: int, appointments: int, expenses: int}
     */
    public function fill(User $doctor, bool $realisticNames = true): array
    {
        // All or nothing: a failure never leaves half a demo behind.
        DB::transaction(fn () => $this->seed($doctor, $realisticNames));

        return $this->counts();
    }

    /**
     * @return array{patients: int, consultations: int, appointments: int, expenses: int}
     */
    public function remove(): array
    {
        $removed = $this->counts();

        DB::transaction(function (): void {
            $patientIds = $this->demoPatientIds();
            $consultationIds = Consultation::query()->whereIn('patient_id', $patientIds)->pluck('id')->all();

            // Children before parents: payments and consultations hold the
            // patient with a restricting key, prescriptions and alerts
            // cascade with it.
            Payment::query()->whereIn('consultation_id', $consultationIds)->delete();
            Prescription::query()->whereIn('patient_id', $patientIds)->delete();
            Consultation::query()->whereIn('id', $consultationIds)->delete();

            // A soft delete first tells linked mobile apps the booking is
            // gone; the row itself must then go for the patient to go.
            Appointment::query()->whereIn('patient_id', $patientIds)->get()
                ->each(static function (Appointment $appointment): void {
                    $appointment->delete();
                    $appointment->forceDelete();
                });
            Appointment::onlyTrashed()->whereIn('patient_id', $patientIds)->forceDelete();

            PatientAlert::query()->whereIn('patient_id', $patientIds)->delete();
            Patient::withTrashed()->whereIn('id', $patientIds)->forceDelete();
            Expense::query()->where('label', 'like', self::MARKER.'%')->delete();
        });

        return $removed;
    }

    /** @return list<int> */
    private function demoPatientIds(): array
    {
        return array_values(Patient::withTrashed()
            ->where('notes', 'like', self::MARKER.'%')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all());
    }

    private function seed(User $doctor, bool $realisticNames): void
    {
        $today = CarbonImmutable::today();
        $patients = [];

        foreach (self::ACTS as [$label, $price]) {
            if (! ConsultationFee::query()->whereRaw('LOWER(label) = ?', [mb_strtolower($label)])->exists()) {
                ConsultationFee::query()->create([
                    'label' => $label,
                    'amount_minor' => $price * 100,
                    'is_active' => true,
                ]);
            }
        }

        foreach (self::PATIENTS as $index => [$first, $last, $gender, $birth, $city, $allergy]) {
            $first = $realisticNames ? $first : 'Démo '.$first;
            $patient = Patient::query()->firstOrCreate(
                ['first_name' => $first, 'last_name' => $last, 'date_of_birth' => $birth],
                [
                    'gender' => $gender,
                    'city' => $city,
                    'phone' => $realisticNames ? sprintf('05%02d %02d %02d %02d', 50 + $index, 10 + $index, 20 + $index, 30 + $index) : null,
                    'allergies' => $allergy,
                    'notes' => self::MARKER.' patient de démonstration',
                    'created_by' => $doctor->getKey(),
                ],
            );
            $patients[] = $patient;

            if ($allergy !== null && ! PatientAlert::query()->where('patient_id', $patient->getKey())->exists()) {
                PatientAlert::query()->create([
                    'patient_id' => $patient->getKey(),
                    'type' => 'allergy',
                    'label' => $allergy,
                    'severity' => 'moderate',
                    'is_active' => true,
                    'created_by' => $doctor->getKey(),
                ]);
            }
        }

        // A duplicate of the first patient, to try "Doublons" and the merge.
        Patient::query()->firstOrCreate(
            ['first_name' => 'HADDAD', 'last_name' => $realisticNames ? 'Karim' : 'Démo Karim', 'date_of_birth' => '1968-03-14'],
            ['gender' => 'male', 'phone' => $realisticNames ? '0661 00 11 22' : null, 'city' => 'Alger', 'notes' => self::MARKER.' doublon à fusionner'],
        );

        if (Appointment::query()->where('reception_notes', self::MARKER)->exists()) {
            // Patients were checked; the activity is already there.
            return;
        }

        foreach ([
            [ExpenseCategory::RENT, 'Loyer du cabinet', 6_500_000, $today->startOfMonth(), true],
            [ExpenseCategory::MEDICAL_SUPPLIES, 'Consommables médicaux', 1_250_000, $today->startOfMonth()->addDay(), false],
            [ExpenseCategory::UTILITIES, 'Électricité et eau', 480_000, $today->startOfMonth()->addDays(2), false],
        ] as [$category, $label, $amount, $spentOn, $recurring]) {
            Expense::query()->firstOrCreate(
                ['label' => self::MARKER.' '.$label, 'spent_on' => $spentOn->toDateString()],
                [
                    'category' => $category,
                    'amount_minor' => $amount,
                    'method' => 'cash',
                    'notes' => self::MARKER.' charge fictive pour les essais',
                    'is_recurring' => $recurring,
                    'created_by' => $doctor->getKey(),
                ],
            );
        }

        // History: 1 to 3 completed visits per patient over the last months.
        foreach ($patients as $index => $patient) {
            $count = 1 + ($index % 3);

            for ($visit = 0; $visit < $count; $visit++) {
                $at = $today->subDays(12 + $visit * 45 + $index * 5)->setTime(9 + ($index + $visit) % 8, 0);
                $this->completedVisit($patient, $doctor, $at, self::VISITS[($index + $visit) % count(self::VISITS)]);
            }
        }

        // Today's agenda.
        $todayPlan = [
            [0, 8, 30, AppointmentStatus::COMPLETED, 'Contrôle tension'],
            [1, 9, 0, AppointmentStatus::COMPLETED, 'Résultats de bilan'],
            [4, 10, 0, AppointmentStatus::CHECKED_IN, 'Palpitations — apporte son ECG'],
            [3, 10, 30, AppointmentStatus::CHECKED_IN, 'Renouvellement ordonnance'],
            [2, 11, 30, AppointmentStatus::CONFIRMED, 'Douleurs abdominales'],
            [6, 14, 0, AppointmentStatus::SCHEDULED, 'Suivi diabète'],
            [5, 15, 0, AppointmentStatus::SCHEDULED, 'Fièvre (enfant)'],
            [7, 16, 0, AppointmentStatus::CANCELLED, 'Consultation de contrôle'],
        ];

        foreach ($todayPlan as [$patientIndex, $hour, $minute, $status, $reason]) {
            $appointment = $this->appointment($patients[$patientIndex], $doctor, $today->setTime($hour, $minute), $status, $reason);

            if ($status === AppointmentStatus::COMPLETED) {
                $this->completedVisit($patients[$patientIndex], $doctor, $today->setTime($hour, $minute + 5), self::VISITS[$patientIndex % count(self::VISITS)], $appointment);
            }
        }

        // The coming days.
        $upcoming = [
            [1, 1, 9, 'Suivi grossesse'], [2, 1, 10, 'Certificat médical'], [6, 2, 9, 'Bilan annuel'],
            [3, 3, 11, 'Contrôle HTA'], [0, 4, 14, 'Résultats radio thoracique'], [5, 6, 10, 'Vaccination'],
            [7, 7, 15, 'Douleurs lombaires'], [4, 9, 9, 'Résultats Holter'],
        ];

        foreach ($upcoming as [$patientIndex, $inDays, $hour, $reason]) {
            $status = $inDays <= 2 ? AppointmentStatus::CONFIRMED : AppointmentStatus::SCHEDULED;
            $this->appointment($patients[$patientIndex], $doctor, $today->addDays($inDays)->setTime($hour, 0), $status, $reason);
        }

        // A missed one last week.
        $this->appointment($patients[2], $doctor, $today->subDays(6)->setTime(11, 0), AppointmentStatus::NO_SHOW, 'Contrôle');
    }

    private function appointment(Patient $patient, User $doctor, CarbonImmutable $startsAt, AppointmentStatus $status, string $reason): Appointment
    {
        return Appointment::query()->create([
            'patient_id' => $patient->getKey(),
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes(30),
            'status' => $status,
            'reason' => $reason,
            'reception_notes' => self::MARKER,
            'created_by' => $doctor->getKey(),
            'confirmed_at' => in_array($status, [AppointmentStatus::CONFIRMED, AppointmentStatus::CHECKED_IN, AppointmentStatus::COMPLETED], true) ? $startsAt->subDay() : null,
            'checked_in_at' => in_array($status, [AppointmentStatus::CHECKED_IN, AppointmentStatus::COMPLETED], true) ? $startsAt->subMinutes(10) : null,
            'started_at' => $status === AppointmentStatus::COMPLETED ? $startsAt : null,
            'completed_at' => $status === AppointmentStatus::COMPLETED ? $startsAt->addMinutes(25) : null,
            'cancelled_at' => $status === AppointmentStatus::CANCELLED ? $startsAt->subHours(3) : null,
            'cancellation_reason' => $status === AppointmentStatus::CANCELLED ? 'Annulé par le patient' : null,
        ]);
    }

    /**
     * @param  array{motif: string, examens: string, diagnostic: string, traitement: string, bp: string, temp: float, meds: list<array{0: string, 1: string, 2: string}>}  $visit
     */
    private function completedVisit(Patient $patient, User $doctor, CarbonImmutable $at, array $visit, ?Appointment $appointment = null): void
    {
        $consultation = Consultation::query()->create([
            'patient_id' => $patient->getKey(),
            'appointment_id' => $appointment?->getKey(),
            'consulted_at' => $at,
            'motif' => $visit['motif'],
            'examens' => $visit['examens'],
            'diagnostic' => $visit['diagnostic'],
            'traitement' => $visit['traitement'],
            'blood_pressure' => $visit['bp'],
            'temperature_c' => $visit['temp'],
            'weight_kg' => $patient->date_of_birth !== null && $patient->date_of_birth->age < 14 ? 28 : 62 + ($patient->getKey() % 25),
            'status' => 'completed',
            'completed_at' => $at->addMinutes(20),
            'created_by' => $doctor->getKey(),
        ]);

        Prescription::query()->create([
            'patient_id' => $patient->getKey(),
            'consultation_id' => $consultation->getKey(),
            'prescribed_at' => $at,
            'items' => array_map(static fn (array $med): array => [
                'medication' => $med[0],
                'dosage' => $med[1],
                'duration' => $med[2],
                'instructions' => null,
            ], $visit['meds']),
            'created_by' => $doctor->getKey(),
        ]);

        // 2 000 DA per visit; most are paid in full, some partly.
        $charge = 200_000;
        app(RecordConsultationPaymentAction::class)->handle($consultation, $doctor, [
            'charge_minor' => $charge,
            'paid_now_minor' => $patient->getKey() % 4 === 0 ? 100_000 : $charge,
            'method' => 'cash',
            'service' => 'Consultation',
            'notes' => self::MARKER.' encaissement fictif pour les essais',
            'settle' => false,
            'client_reference' => null,
        ]);
    }
}
