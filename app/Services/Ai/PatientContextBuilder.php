<?php

namespace App\Services\Ai;

use App\Models\AiInsight;
use App\Models\Consultation;
use App\Models\Document;
use App\Models\EcgRecord;
use App\Models\Patient;
use App\Models\PatientMeasurement;
use App\Models\Prescription;
use App\Services\Clinical\FamilyMedicalHistory;
use App\Services\Clinical\PatientSafety;
use Illuminate\Support\Str;

/**
 * Turns a patient's dossier into the plain-text context the model reads.
 *
 * Identity never leaves the cabinet: no name, file number, phone, e-mail or
 * address — only what matters clinically (age, sex, history, visits,
 * prescriptions, measurements, documents).
 */
final class PatientContextBuilder
{
    private const MAX_CHARS = 14000;

    public function __construct(
        private readonly PatientSafety $safety,
        private readonly FamilyMedicalHistory $family,
    ) {}

    /**
     * @param  array<string, mixed>  $draft  Unsaved values of the visit being written.
     */
    public function build(Patient $patient, ?Consultation $current = null, array $draft = [], bool $full = false): string
    {
        $sections = [
            $this->identity($patient),
            $this->history($patient),
            $this->relatives($patient),
        ];

        if ($current !== null) {
            $sections[] = $this->currentVisit($current, $draft);
        }

        $sections[] = $this->previousVisits($patient, $current, $full ? 15 : 6);
        $sections[] = $this->prescriptions($patient, $full ? 10 : 4);
        $sections[] = $this->measurements($patient);
        $sections[] = $this->ecgs($patient, $full ? 6 : 3);
        $sections[] = $this->documents($patient, $full ? 12 : 5);

        $text = implode("\n\n", array_filter($sections, static fn (string $section): bool => $section !== ''));

        return Str::limit($text, self::MAX_CHARS, "\n[…dossier tronqué]");
    }

    private function identity(Patient $patient): string
    {
        $age = $patient->date_of_birth?->diffInYears(now());

        return $this->section('PATIENT', [
            'Sexe' => match ($patient->gender?->value) {
                'male' => 'Homme',
                'female' => 'Femme',
                default => $patient->gender?->value,
            },
            'Âge' => $age !== null ? ((int) floor($age)).' ans' : null,
            'Groupe sanguin' => $patient->blood_group?->value,
            'Tabagisme' => $patient->smoking_status,
            'Profession' => $patient->profession,
            'Situation familiale' => $patient->marital_status,
        ]);
    }

    private function history(Patient $patient): string
    {
        return $this->alerts($patient).$this->section('ANTÉCÉDENTS', [
            'Allergies et réactions connues' => $patient->allergies,
            'Maladies chroniques / antécédents médicaux' => $patient->antecedents_medical,
            'Chirurgicaux' => $patient->antecedents_surgical,
            'Familiaux' => $patient->antecedents_family,
            'Gynéco-obstétricaux' => $patient->antecedents_gyneco,
            'Autres' => $patient->antecedents_other,
        ]);
    }

    /**
     * What the dossiers of the patient's relatives report (relation and
     * findings only: a relative's identity never leaves the cabinet either).
     */
    private function relatives(Patient $patient): string
    {
        $lines = $this->family->contextLines($patient);

        return $lines === [] ? '' : "ANTÉCÉDENTS DES PROCHES (dossiers liés)\n".implode("\n", array_slice($lines, 0, 12));
    }

    /**
     * The structured safety list (allergies, chronic conditions, long-term
     * treatments) the cabinet keeps for the patient.
     */
    private function alerts(Patient $patient): string
    {
        $summary = $this->safety->summary($patient);
        $line = static fn (array $alert): string => $alert['label']
            .($alert['severity_label'] ? ' ('.$alert['severity_label'].')' : '')
            .($alert['details'] ? ' — '.$alert['details'] : '');

        $sections = array_filter([
            'Allergies' => implode(' ; ', array_map($line, $summary['allergies'])),
            'Pathologies chroniques' => implode(' ; ', array_map($line, $summary['conditions'])),
            'Traitements au long cours' => implode(' ; ', array_map($line, $summary['treatments'])),
        ]);

        $section = $this->section('ALERTES DU DOSSIER', $sections);

        return $section === '' ? '' : $section."\n\n";
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function currentVisit(Consultation $consultation, array $draft): string
    {
        $value = static fn (string $key): mixed => array_key_exists($key, $draft) ? $draft[$key] : $consultation->{$key};

        return $this->section('CONSULTATION ACTUELLE ('.($consultation->consulted_at?->format('d/m/Y') ?? 'aujourd’hui').')', [
            'Motif' => $value('motif'),
            'Examen clinique' => $value('examens'),
            'Diagnostic' => $value('diagnostic'),
            'Traitement' => $value('traitement'),
            'Notes' => $value('notes'),
            'Poids (kg)' => $value('weight_kg'),
            'Taille (cm)' => $value('height_cm'),
            'Température (°C)' => $value('temperature_c'),
            'Tension artérielle' => $value('blood_pressure'),
        ], always: true);
    }

    private function previousVisits(Patient $patient, ?Consultation $current, int $limit): string
    {
        $lines = Consultation::query()
            ->where('patient_id', $patient->getKey())
            ->when($current !== null, fn ($query) => $query->whereKeyNot($current->getKey()))
            ->orderByDesc('consulted_at')
            ->limit($limit)
            ->get()
            ->map(fn (Consultation $item): string => '- '.($item->consulted_at?->format('d/m/Y') ?? '?').' : '.implode(' | ', array_filter([
                $this->clip($item->motif, 'Motif'),
                $this->clip($item->diagnostic, 'Diagnostic'),
                $this->clip($item->traitement, 'Traitement'),
            ])))
            ->all();

        return $lines === [] ? '' : "CONSULTATIONS PRÉCÉDENTES\n".implode("\n", $lines);
    }

    private function prescriptions(Patient $patient, int $limit): string
    {
        $lines = Prescription::query()
            ->where('patient_id', $patient->getKey())
            ->orderByDesc('prescribed_at')
            ->limit($limit)
            ->get()
            ->map(function (Prescription $prescription): string {
                $items = collect($prescription->items ?? [])
                    ->map(fn (array $item): string => trim(implode(' ', array_filter([
                        $item['medication'] ?? null,
                        isset($item['dosage']) && $item['dosage'] !== '' ? '('.$item['dosage'].')' : null,
                    ]))))
                    ->filter()
                    ->implode(', ');

                return '- '.($prescription->prescribed_at?->format('d/m/Y') ?? '?').' : '.($items !== '' ? $items : '—');
            })
            ->all();

        return $lines === [] ? '' : "ORDONNANCES RÉCENTES\n".implode("\n", $lines);
    }

    private function measurements(Patient $patient): string
    {
        $lines = PatientMeasurement::query()
            ->where('patient_id', $patient->getKey())
            ->orderByDesc('measured_at')
            ->limit(5)
            ->get()
            ->map(fn (PatientMeasurement $m): string => '- '.($m->measured_at?->format('d/m/Y') ?? '?').' : '.implode(', ', array_filter([
                $m->weight_kg !== null ? 'poids '.$m->weight_kg.' kg' : null,
                $m->height_cm !== null ? 'taille '.$m->height_cm.' cm' : null,
                $m->bmi !== null ? 'IMC '.$m->bmi : null,
                $m->waist_cm !== null ? 'tour de taille '.$m->waist_cm.' cm' : null,
            ])))
            ->all();

        return $lines === [] ? '' : "MESURES\n".implode("\n", $lines);
    }

    private function ecgs(Patient $patient, int $limit): string
    {
        $lines = EcgRecord::query()
            ->where('patient_id', $patient->getKey())
            ->orderByDesc('recorded_at')
            ->limit($limit)
            ->get()
            ->map(function (EcgRecord $ecg): string {
                $head = '- '.($ecg->recorded_at?->format('d/m/Y') ?? '?').' '.$ecg->title;
                $rate = $ecg->measurements['heart_rate_bpm'] ?? null;
                $measured = $rate !== null ? ' (FC mesurée '.$rate.'/min)' : '';

                // Only a signed conclusion is a finding; an AI reading is labelled as such.
                if ($ecg->status === EcgRecord::STATUS_VALIDATED && $ecg->doctor_conclusion) {
                    return $head.$measured.' — conclusion du médecin : '.Str::limit($this->plain($ecg->doctor_conclusion), 400);
                }

                $ai = $ecg->analysis['primary_statement'] ?? null;

                return $head.$measured.(is_string($ai) && $ai !== '' ? ' — lecture IA non validée : '.Str::limit($ai, 300) : ' — non interprété');
            })
            ->all();

        return $lines === [] ? '' : "ECG\n".implode("\n", $lines);
    }

    private function documents(Patient $patient, int $limit): string
    {
        $analyses = AiInsight::query()
            ->where('patient_id', $patient->getKey())
            ->where('kind', AiInsight::KIND_DOCUMENT_ANALYSIS)
            ->whereNotNull('document_id')
            ->latest()
            ->get()
            ->unique('document_id')
            ->keyBy('document_id');

        $lines = Document::query()
            ->where('patient_id', $patient->getKey())
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(function (Document $document) use ($analyses): string {
                $head = '- '.$document->created_at?->format('d/m/Y').' ['.$document->category.'] '.$document->title;
                $analysis = $analyses->get($document->getKey());

                if ($analysis instanceof AiInsight && is_string($analysis->content['summary'] ?? null)) {
                    return $head.' — analyse : '.Str::limit($analysis->content['summary'], 500);
                }

                if ($document->content !== null && trim(strip_tags($document->content)) !== '') {
                    return $head.' — '.Str::limit($this->plain($document->content), 500);
                }

                return $head;
            })
            ->all();

        return $lines === [] ? '' : "DOCUMENTS DU DOSSIER\n".implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function section(string $title, array $fields, bool $always = false): string
    {
        $lines = [];

        foreach ($fields as $label => $value) {
            if ($value === null || trim((string) $value) === '') {
                continue;
            }

            $lines[] = $label.' : '.Str::limit($this->plain((string) $value), 1200);
        }

        if ($lines === []) {
            return $always ? $title."\n(rien de saisi pour l’instant)" : '';
        }

        return $title."\n".implode("\n", $lines);
    }

    private function clip(?string $value, string $label): ?string
    {
        return $value !== null && trim($value) !== '' ? $label.' : '.Str::limit($this->plain($value), 220) : null;
    }

    public function plain(string $value): string
    {
        $text = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</li>'], "\n", $value)), ENT_QUOTES | ENT_HTML5);

        return trim((string) preg_replace('/[ \t]+/', ' ', (string) preg_replace("/\n{3,}/", "\n\n", $text)));
    }
}
