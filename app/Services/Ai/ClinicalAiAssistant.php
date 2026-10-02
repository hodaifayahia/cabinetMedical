<?php

namespace App\Services\Ai;

use App\Enums\AiFeature;
use App\Models\AiInsight;
use App\Models\Consultation;
use App\Models\Document;
use App\Models\Exam;
use App\Models\Medication;
use App\Models\Patient;
use App\Models\User;
use App\Services\Clinical\PatientSafety;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The clinical features: each builds its prompt, calls the gateway (which
 * charges the wallet) and normalises the model's JSON into exactly the shape
 * the screen renders, so a malformed answer can never break the UI.
 */
final class ClinicalAiAssistant
{
    private const SYSTEM = <<<'TXT'
Tu es l’assistant clinique de ClickDz, utilisé par des médecins en Algérie.
- Tu aides le médecin ; tu ne le remplaces pas. Tout ce que tu proposes sera relu et validé par lui.
- Réponds en français médical clair et concis, sans formule de politesse.
- N’invente jamais un résultat, une constante ou un antécédent absent du dossier. Si une information manque, dis-le.
- Pour les médicaments, privilégie ceux disponibles en Algérie (nom commercial et DCI), adapte les doses à l’âge, au poids et aux allergies, et signale les interactions ou contre-indications.
- Réponds UNIQUEMENT avec un objet JSON valide respectant le schéma demandé.
TXT;

    public function __construct(
        private readonly AiGateway $gateway,
        private readonly PatientContextBuilder $context,
        private readonly DocumentTextExtractor $extractor,
        private readonly PatientSafety $safety,
    ) {}

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    public function suggestConsultation(User $user, Consultation $consultation, array $draft, ?string $transcript = null): array
    {
        // Dictation: the doctor spoke the visit; the browser transcribed it.
        $dictation = $transcript !== null && trim($transcript) !== ''
            ? "\n\nDICTÉE DU MÉDECIN (transcription vocale automatique : corrige les termes médicaux mal reconnus, sans rien ajouter) :\n".Str::limit(trim($transcript), 12000)
                ."\nRépartis le contenu de cette dictée dans les champs ci-dessous ; elle prime sur les champs déjà saisis quand elle les complète."
            : '';

        $prompt = $this->context->build($this->patientOf($consultation), $consultation, $draft).$dictation."\n\n".<<<'TXT'
TÂCHE : aide à rédiger la consultation actuelle.
- Reformule et complète ce que le médecin a saisi, sans perdre aucune de ses informations.
- "examens" = examen clinique : structure-le par appareil ; pour un élément non renseigné écris « [à compléter] » au lieu d’inventer.
- "diagnostic" : hypothèse principale puis diagnostics différentiels.
- "traitement" : conduite à tenir proposée (médicaments avec posologie, mesures, surveillance).
Schéma : {"motif": string, "examens": string, "diagnostic": string, "traitement": string, "alerts": [string]}
"alerts" = points d’attention (allergie, interaction, signe de gravité) ; tableau vide s’il n’y en a pas.
TXT;

        $completion = $this->gateway->complete($user, AiFeature::CONSULTATION_TEXT, $this->messages($prompt));
        $json = $completion->json();

        return [
            'fields' => [
                'motif' => $this->text($json['motif'] ?? null),
                'examens' => $this->text($json['examens'] ?? null),
                'diagnostic' => $this->text($json['diagnostic'] ?? null),
                'traitement' => $this->text($json['traitement'] ?? null),
            ],
            'alerts' => $this->strings($json['alerts'] ?? []),
            'balance' => $completion->balance,
        ];
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    public function suggestExams(User $user, Consultation $consultation, array $draft): array
    {
        $exams = Exam::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'category']);
        $catalogue = $exams->pluck('name')->unique()->take(600)->implode('; ');

        $prompt = $this->context->build($this->patientOf($consultation), $consultation, $draft)."\n\n"
            .'CATALOGUE D’EXAMENS DU CABINET : '.($catalogue !== '' ? $catalogue : '(vide)')."\n\n".<<<'TXT'
TÂCHE : propose les examens complémentaires (biologie, imagerie, autres) pertinents pour cette consultation.
- Utilise EXACTEMENT le nom tel qu’il est écrit dans le catalogue quand l’examen y figure.
- 3 à 10 examens, du plus important au moins important ; n’en propose pas d’inutiles.
Schéma : {"exams": [{"name": string, "reason": string, "priority": "urgent" | "recommandé" | "optionnel"}], "note": string}
TXT;

        $completion = $this->gateway->complete($user, AiFeature::EXAM_SUGGESTIONS, $this->messages($prompt));
        $json = $completion->json();

        $suggestions = collect(is_array($json['exams'] ?? null) ? $json['exams'] : [])
            ->filter(fn ($item): bool => is_array($item) && is_string($item['name'] ?? null) && trim($item['name']) !== '')
            ->take(12)
            ->map(function (array $item) use ($exams): array {
                $match = $this->match($exams, (string) $item['name'], fn (Exam $exam): string => $exam->name);

                return [
                    'name' => $match?->name ?? trim((string) $item['name']),
                    'exam_id' => $match?->getKey(),
                    'reason' => $this->text($item['reason'] ?? null),
                    'priority' => in_array($item['priority'] ?? null, ['urgent', 'recommandé', 'optionnel'], true) ? $item['priority'] : 'recommandé',
                ];
            })
            ->unique('name')
            ->values()
            ->all();

        return [
            'exams' => $suggestions,
            'note' => $this->text($json['note'] ?? null),
            'balance' => $completion->balance,
        ];
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  list<string>  $currentItems  Medications already on the ordonnance.
     * @return array<string, mixed>
     */
    public function suggestPrescription(User $user, Consultation $consultation, array $draft, array $currentItems): array
    {
        $medications = Medication::query()->where('is_active', true)->orderBy('name')->limit(400)->get(['id', 'name', 'dci', 'form', 'dosage']);
        $catalogue = $medications
            ->map(fn (Medication $m): string => trim($m->name.($m->dci ? ' ('.$m->dci.')' : '').($m->dosage ? ' '.$m->dosage : '')))
            ->implode('; ');

        $prompt = $this->context->build($this->patientOf($consultation), $consultation, $draft)."\n\n"
            .'CATALOGUE DE MÉDICAMENTS DU CABINET : '.($catalogue !== '' ? $catalogue : '(vide)')."\n"
            .'DÉJÀ SUR L’ORDONNANCE : '.($currentItems !== [] ? implode(', ', $currentItems) : 'rien')."\n\n".<<<'TXT'
TÂCHE : propose l’ordonnance adaptée au diagnostic et au patient.
- Fonde chaque proposition sur un élément pertinent et daté des consultations ou ordonnances précédentes du patient ; utilise la consultation actuelle pour vérifier que cet élément reste pertinent. Dans "reason", cite le fait et sa date. S’il n’existe pas d’élément antérieur pertinent, ne propose aucun médicament.
- Utilise uniquement un produit du catalogue du cabinet et reprends exactement son nom. N’invente pas de produit absent du catalogue.
- "dosage" = posologie (ex. « 1 cp x 3/j »), "duration" = quantité ou durée (ex. « 1 boîte », « 7 jours »), "instructions" = conseil de prise.
- Ne répète pas ce qui est déjà sur l’ordonnance. Tiens compte des allergies et des traitements au long cours enregistrés. Ces lignes restent des propositions : le médecin choisit manuellement celles à ajouter.
Schéma : {"items": [{"medication": string, "dosage": string, "duration": string, "instructions": string, "reason": string}], "warnings": [string], "advice": string}
"advice" = conseils hygiéno-diététiques courts pour le patient.
TXT;

        $completion = $this->gateway->complete($user, AiFeature::PRESCRIPTION_SUGGESTIONS, $this->messages($prompt));
        $json = $completion->json();

        $items = collect(is_array($json['items'] ?? null) ? $json['items'] : [])
            ->filter(fn ($item): bool => is_array($item) && is_string($item['medication'] ?? null) && trim($item['medication']) !== '')
            ->take(10)
            ->map(function (array $item) use ($medications): array {
                $match = $this->match($medications, (string) $item['medication'], fn (Medication $m): string => $m->name);

                return [
                    'medication' => $match?->name ?? Str::limit(trim((string) $item['medication']), 190, ''),
                    'in_catalogue' => $match !== null,
                    'dosage' => Str::limit($this->text($item['dosage'] ?? null), 190, ''),
                    'duration' => Str::limit($this->text($item['duration'] ?? null), 95, ''),
                    'instructions' => Str::limit($this->text($item['instructions'] ?? null), 480, ''),
                    'reason' => $this->text($item['reason'] ?? null),
                ];
            })
            ->values()
            ->all();

        // The cabinet's deterministic check, not the model's opinion, decides
        // which lines carry an allergy warning.
        $conflicts = collect($this->safety->allergyConflicts(
            $this->patientOf($consultation),
            array_column($items, 'medication'),
        ))->groupBy('medication');
        $items = array_map(static fn (array $item): array => [
            ...$item,
            'allergy_conflicts' => $conflicts->get($item['medication'], collect())->pluck('reason')->values()->all(),
        ], $items);

        return [
            'items' => $items,
            'warnings' => $this->strings($json['warnings'] ?? []),
            'advice' => $this->text($json['advice'] ?? null),
            'balance' => $completion->balance,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function analyzeDocument(User $user, Document $document): array
    {
        $patient = Patient::query()->withTrashed()->findOrFail($document->patient_id);
        $instructions = <<<'TXT'
TÂCHE : analyse ce document médical du patient (compte rendu, bilan biologique, imagerie, ordonnance…).
- Résume-le, relève chaque valeur ou constatation importante et indique si elle est normale ou non.
- Mets en évidence ce qui demande une action du médecin.
Schéma : {"document_type": string, "summary": string, "findings": [{"label": string, "value": string, "status": "normal" | "anormal" | "à surveiller"}], "recommendations": [string]}
TXT;
        $patientContext = $this->context->build($patient);

        $mime = (string) $document->mime_type;
        $isImage = in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true);

        if ($document->category !== 'uploaded') {
            $text = $this->context->plain((string) $document->content);
            $vision = false;
            $userContent = $patientContext."\n\nDOCUMENT « ".$document->title." » :\n".$text."\n\n".$instructions;
        } elseif ($isImage) {
            $bytes = $this->readUpload($document);

            if (strlen($bytes) > (int) config('ai.max_image_bytes')) {
                throw new AiException('Cette image est trop lourde pour l’analyse (4 Mo maximum). Importez une photo moins volumineuse.', AiException::UNSUPPORTED);
            }

            $vision = true;
            $userContent = [
                ['type' => 'image_url', 'image_url' => ['url' => 'data:'.$mime.';base64,'.base64_encode($bytes)]],
                ['type' => 'text', 'text' => $patientContext."\n\nDOCUMENT « ".$document->title." » : voir l’image.\n\n".$instructions],
            ];
        } else {
            $text = $document->file_path !== null && Storage::exists($document->file_path)
                ? $this->extractor->extract(Storage::path($document->file_path), $mime, $document->original_filename)
                : '';

            if ($text === '') {
                throw new AiException(
                    'Impossible de lire le texte de ce fichier (document scanné ?). Importez-le en photo (JPG ou PNG) pour que l’IA puisse l’analyser.',
                    AiException::UNSUPPORTED,
                );
            }

            $vision = false;
            $userContent = $patientContext."\n\nDOCUMENT « ".$document->title." » :\n".$text."\n\n".$instructions;
        }

        if (isset($text) && trim($text) === '') {
            throw new AiException('Ce document est vide : rien à analyser.', AiException::UNSUPPORTED);
        }

        $completion = $this->gateway->complete(
            $user,
            AiFeature::DOCUMENT_ANALYSIS,
            [['role' => 'system', 'content' => self::SYSTEM], ['role' => 'user', 'content' => $userContent]],
            $vision,
        );
        $json = $completion->json();

        $content = [
            'document_type' => $this->text($json['document_type'] ?? null),
            'summary' => $this->text($json['summary'] ?? null),
            'findings' => collect(is_array($json['findings'] ?? null) ? $json['findings'] : [])
                ->filter(fn ($item): bool => is_array($item) && is_string($item['label'] ?? null))
                ->take(40)
                ->map(fn (array $item): array => [
                    'label' => $this->text($item['label']),
                    'value' => $this->text($item['value'] ?? null),
                    'status' => in_array($item['status'] ?? null, ['normal', 'anormal', 'à surveiller'], true) ? $item['status'] : 'normal',
                ])
                ->values()
                ->all(),
            'recommendations' => $this->strings($json['recommendations'] ?? []),
        ];

        $insight = AiInsight::query()->create([
            'patient_id' => $patient->getKey(),
            'document_id' => $document->getKey(),
            'kind' => AiInsight::KIND_DOCUMENT_ANALYSIS,
            'content' => $content,
            'created_by' => $user->getKey(),
        ]);

        return ['analysis' => $this->insightPayload($insight), 'balance' => $completion->balance];
    }

    /**
     * @return array<string, mixed>
     */
    public function analyzePatient(User $user, Patient $patient): array
    {
        $prompt = $this->context->build($patient, full: true)."\n\n".<<<'TXT'
TÂCHE : fais la synthèse de ce patient à partir de tout son dossier au cabinet (consultations, ordonnances, mesures, documents).
Schéma : {
  "summary": string,
  "problems": [string],
  "risks": [{"label": string, "level": "élevé" | "modéré" | "faible", "reason": string}],
  "follow_up": [string],
  "suggested_exams": [string],
  "treatment_notes": [string],
  "alerts": [string]
}
"summary" = 3 à 5 phrases ; "problems" = problèmes de santé actifs ; "follow_up" = suivi et dépistages à prévoir ;
"treatment_notes" = remarques sur les traitements (observance, interactions, renouvellement) ; "alerts" = ce qui demande une attention rapide.
Si le dossier est presque vide, dis-le dans "summary" et laisse les listes courtes.
TXT;

        $completion = $this->gateway->complete($user, AiFeature::PATIENT_ANALYSIS, $this->messages($prompt));
        $json = $completion->json();

        $content = [
            'summary' => $this->text($json['summary'] ?? null),
            'problems' => $this->strings($json['problems'] ?? []),
            'risks' => collect(is_array($json['risks'] ?? null) ? $json['risks'] : [])
                ->filter(fn ($item): bool => is_array($item) && is_string($item['label'] ?? null))
                ->take(8)
                ->map(fn (array $item): array => [
                    'label' => $this->text($item['label']),
                    'level' => in_array($item['level'] ?? null, ['élevé', 'modéré', 'faible'], true) ? $item['level'] : 'modéré',
                    'reason' => $this->text($item['reason'] ?? null),
                ])
                ->values()
                ->all(),
            'follow_up' => $this->strings($json['follow_up'] ?? []),
            'suggested_exams' => $this->strings($json['suggested_exams'] ?? []),
            'treatment_notes' => $this->strings($json['treatment_notes'] ?? []),
            'alerts' => $this->strings($json['alerts'] ?? []),
        ];

        $insight = AiInsight::query()->create([
            'patient_id' => $patient->getKey(),
            'kind' => AiInsight::KIND_PATIENT_ANALYSIS,
            'content' => $content,
            'created_by' => $user->getKey(),
        ]);

        return ['analysis' => $this->insightPayload($insight), 'balance' => $completion->balance];
    }

    /**
     * @return array<string, mixed>
     */
    public function insightPayload(AiInsight $insight): array
    {
        return [
            'id' => $insight->getKey(),
            'document_id' => $insight->document_id,
            'created_at' => $insight->created_at?->toIso8601String(),
            'content' => $insight->content,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function messages(string $prompt): array
    {
        return [
            ['role' => 'system', 'content' => self::SYSTEM],
            ['role' => 'user', 'content' => $prompt],
        ];
    }

    private function patientOf(Consultation $consultation): Patient
    {
        return Patient::query()->withTrashed()->findOrFail($consultation->patient_id);
    }

    private function readUpload(Document $document): string
    {
        if ($document->file_path === null || ! Storage::exists($document->file_path)) {
            throw new AiException('Le fichier est introuvable sur ce poste.', AiException::UNSUPPORTED);
        }

        return (string) Storage::get($document->file_path);
    }

    /**
     * Catalogue entry the model meant: exact name first (ignoring case and
     * accents), then a name that contains or is contained in the suggestion.
     *
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  Collection<int, T>  $items
     * @param  callable(T): string  $name
     * @return T|null
     */
    private function match(Collection $items, string $suggestion, callable $name): mixed
    {
        $needle = $this->normalise($suggestion);

        if ($needle === '') {
            return null;
        }

        $exact = $items->first(fn ($item): bool => $this->normalise($name($item)) === $needle);

        if ($exact !== null) {
            return $exact;
        }

        return $items
            ->filter(function ($item) use ($name, $needle): bool {
                $candidate = $this->normalise($name($item));

                return mb_strlen($candidate) >= 3
                    && (str_contains($needle, $candidate) || str_contains($candidate, $needle));
            })
            ->sortByDesc(fn ($item): int => mb_strlen($name($item)))
            ->first();
    }

    private function normalise(string $value): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower(Str::ascii($value))));
    }

    private function text(mixed $value): string
    {
        if (is_array($value)) {
            $value = implode("\n", array_filter($value, 'is_scalar'));
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn ($item): string => $this->text($item), array_slice($value, 0, 12)),
            static fn (string $item): bool => $item !== '',
        ));
    }
}
