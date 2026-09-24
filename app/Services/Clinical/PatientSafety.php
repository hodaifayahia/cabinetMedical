<?php

namespace App\Services\Clinical;

use App\Enums\PatientAlertType;
use App\Models\Patient;
use App\Models\PatientAlert;
use App\Models\PatientRecall;
use Illuminate\Support\Str;

/**
 * The patient's safety list, and a deterministic allergy check for
 * ordonnances.
 *
 * The check is deliberately simple and explainable: a prescribed line is
 * flagged when its text contains the allergen itself or a molecule / brand
 * of the allergen's drug class (« pénicilline » → amoxicilline, Augmentin…).
 * It is a safety net, not a pharmacological database.
 */
final class PatientSafety
{
    /**
     * Drug classes: keyword the doctor is likely to type as the allergy →
     * molecules and common Algerian brand names that belong to it.
     */
    private const CLASSES = [
        'penicilline' => ['penicilline', 'amoxicilline', 'ampicilline', 'oxacilline', 'cloxacilline', 'benzathine', 'extencilline', 'augmentin', 'clamoxyl', 'amoxil', 'piperacilline', 'tazocilline', 'orbenine', 'totapen'],
        'cephalosporine' => ['cefalexine', 'cefadroxil', 'cefixime', 'ceftriaxone', 'cefuroxime', 'cefazoline', 'cefpodoxime', 'cefotaxime', 'ceftazidime', 'oroken', 'rocephine', 'zinnat', 'claforan', 'orelox'],
        'betalactamine' => ['penicilline', 'amoxicilline', 'ampicilline', 'augmentin', 'clamoxyl', 'cloxacilline', 'oxacilline', 'benzathine', 'extencilline', 'cefalexine', 'cefixime', 'ceftriaxone', 'cefuroxime', 'cefpodoxime', 'cefotaxime', 'oroken', 'rocephine', 'zinnat', 'imipeneme', 'meropeneme'],
        'sulfamide' => ['sulfamethoxazole', 'cotrimoxazole', 'bactrim', 'sulfadiazine', 'sulfasalazine'],
        'macrolide' => ['azithromycine', 'clarithromycine', 'erythromycine', 'spiramycine', 'roxithromycine', 'josamycine', 'zithromax', 'rovamycine', 'rulid'],
        'quinolone' => ['ciprofloxacine', 'levofloxacine', 'ofloxacine', 'norfloxacine', 'moxifloxacine', 'ciflox', 'tavanic', 'oflocet'],
        'cycline' => ['doxycycline', 'minocycline', 'tetracycline', 'vibramycine'],
        'aminoside' => ['gentamicine', 'amikacine', 'tobramycine', 'streptomycine'],
        'ains' => ['aspirine', 'acide acetylsalicylique', 'aspegic', 'ibuprofene', 'brufen', 'advil', 'diclofenac', 'voltarene', 'ketoprofene', 'profenid', 'naproxene', 'piroxicam', 'feldene', 'meloxicam', 'mobic', 'celecoxib', 'celebrex', 'indometacine', 'acide mefenamique', 'ponstyl', 'nimesulide', 'flurbiprofene'],
        'anti-inflammatoire' => ['ibuprofene', 'brufen', 'advil', 'diclofenac', 'voltarene', 'ketoprofene', 'profenid', 'naproxene', 'piroxicam', 'meloxicam', 'celecoxib', 'indometacine', 'nimesulide', 'aspirine', 'aspegic'],
        'aspirine' => ['aspirine', 'acide acetylsalicylique', 'aspegic', 'kardegic'],
        'paracetamol' => ['paracetamol', 'doliprane', 'efferalgan', 'dafalgan', 'perfalgan', 'panadol'],
        'codeine' => ['codeine', 'codoliprane', 'dafalgan codeine', 'klipal'],
        'opiace' => ['codeine', 'tramadol', 'morphine', 'oxycodone', 'fentanyl', 'topalgic', 'contramal', 'ixprim', 'zaldiar'],
        'metamizole' => ['metamizole', 'noramidopyrine', 'novalgin', 'baralgin'],
        'iode' => ['povidone iodee', 'betadine', 'amiodarone', 'cordarone', 'lugol'],
        'anticonvulsivant' => ['carbamazepine', 'tegretol', 'phenytoine', 'phenobarbital', 'lamotrigine', 'lamictal'],
        'insuline' => ['insuline', 'lantus', 'novorapid', 'humalog', 'mixtard', 'levemir'],
        'lidocaine' => ['lidocaine', 'xylocaine'],
        'heparine' => ['heparine', 'enoxaparine', 'lovenox', 'tinzaparine'],
    ];

    /** Words that carry no allergen information (« RAS », « aucune »…). */
    private const STOPWORDS = ['aucun', 'aucune', 'neant', 'ras', 'non', 'pas', 'connu', 'connue', 'connues', 'connus', 'allergie', 'allergies', 'allergique', 'a', 'au', 'aux', 'la', 'le', 'les', 'de', 'des', 'du', 'et', 'ou', 'medicamenteuse', 'medicamenteuses', 'rien'];

    /**
     * @return array<string, mixed>
     */
    public function summary(Patient $patient): array
    {
        $alerts = PatientAlert::query()
            ->where('patient_id', $patient->getKey())
            ->where('is_active', true)
            ->orderByRaw("case severity when 'severe' then 0 when 'moderate' then 1 else 2 end")
            ->orderBy('label')
            ->get();

        $pick = static fn (PatientAlertType $type): array => $alerts
            ->filter(static fn (PatientAlert $alert): bool => $alert->type === $type)
            ->map(static fn (PatientAlert $alert): array => [
                'id' => $alert->public_id,
                'type' => $alert->type->value,
                'label' => $alert->label,
                'severity' => $alert->severity,
                'severity_label' => $alert->severity !== null ? (PatientAlert::SEVERITIES[$alert->severity] ?? null) : null,
                'details' => $alert->details,
                'since' => $alert->since?->toDateString(),
            ])
            ->values()
            ->all();

        $legacy = trim((string) $patient->getAttribute('allergies'));

        return [
            'allergies' => $pick(PatientAlertType::ALLERGY),
            'conditions' => $pick(PatientAlertType::CONDITION),
            'treatments' => $pick(PatientAlertType::TREATMENT),
            // The older free-text field, still shown and still checked.
            'legacy_allergies' => $legacy !== '' && $this->allergenTerms($legacy) !== [] ? $legacy : null,
            'severities' => PatientAlert::SEVERITIES,
            // Planned follow-ups, shown with the safety list.
            'recalls' => PatientRecall::query()
                ->where('patient_id', $patient->getKey())
                ->where('status', PatientRecall::PENDING)
                ->orderBy('due_on')
                ->get()
                ->map(static fn (PatientRecall $recall): array => [
                    'id' => $recall->public_id,
                    'due_on' => $recall->due_on->toDateString(),
                    'reason' => $recall->reason,
                    'overdue' => $recall->due_on->lessThan(now()->startOfDay()),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  list<string>  $medications
     * @return list<array{medication: string, allergy: string, reason: string}>
     */
    public function allergyConflicts(Patient $patient, array $medications): array
    {
        $allergies = PatientAlert::query()
            ->where('patient_id', $patient->getKey())
            ->where('type', PatientAlertType::ALLERGY->value)
            ->where('is_active', true)
            ->pluck('label')
            ->all();

        $terms = [];

        foreach ($allergies as $label) {
            foreach ($this->allergenTerms((string) $label) as $term) {
                $terms[$term] = (string) $label;
            }
        }

        foreach ($this->allergenTerms((string) $patient->getAttribute('allergies')) as $term) {
            $terms[$term] ??= $term;
        }

        $conflicts = [];

        foreach ($medications as $medication) {
            $haystack = ' '.$this->normalize($medication).' ';

            foreach ($terms as $term => $allergy) {
                $match = $this->match($haystack, $term);

                if ($match !== null) {
                    $conflicts[] = [
                        'medication' => $medication,
                        'allergy' => $allergy,
                        'reason' => $match,
                    ];

                    break;
                }
            }
        }

        return $conflicts;
    }

    /**
     * Allergen keywords in a free-text allergy note, e.g.
     * « Pénicilline, AINS (urticaire) » → ['penicilline', 'ains', 'urticaire'].
     *
     * @return list<string>
     */
    public function allergenTerms(string $text): array
    {
        $normalized = $this->normalize($text);
        $words = preg_split('/[^a-z0-9\-]+/', $normalized) ?: [];
        $terms = [];

        foreach ($words as $word) {
            $word = trim($word, '-');

            if (mb_strlen($word) < 4 || in_array($word, self::STOPWORDS, true)) {
                continue;
            }

            $terms[] = $this->singular($word);
        }

        return array_values(array_unique($terms));
    }

    private function match(string $haystack, string $term): ?string
    {
        if (str_contains($haystack, $term)) {
            return 'contient « '.$term.' »';
        }

        foreach (self::CLASSES as $class => $members) {
            if (! str_starts_with($class, $term) && ! str_starts_with($term, $class)) {
                continue;
            }

            foreach ($members as $member) {
                if (str_contains($haystack, $member)) {
                    return 'classe « '.$class.' » ('.$member.')';
                }
            }
        }

        return null;
    }

    private function normalize(string $text): string
    {
        return Str::of($text)->ascii()->lower()->replaceMatches('/\s+/', ' ')->toString();
    }

    private function singular(string $word): string
    {
        return strlen($word) > 4 && str_ends_with($word, 's') ? substr($word, 0, -1) : $word;
    }
}
