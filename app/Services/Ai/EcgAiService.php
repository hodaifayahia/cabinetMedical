<?php

namespace App\Services\Ai;

use App\Enums\AiFeature;
use App\Models\EcgRecord;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * Reads an ECG tracing and answers questions about it.
 *
 * The model sees the image *and* the software's measurements, answers in the
 * AHA/ACC/HRS structure (rate, rhythm, conduction intervals, axis, ST-T,
 * primary statement), and its reading is then checked against those
 * measurements by EcgSafetyRules. The doctor's validated conclusion is the only
 * part that enters the dossier as a finding.
 */
final class EcgAiService
{
    private const SYSTEM = <<<'TXT'
Tu es l’assistant ECG de ClickDz. Tu aides un médecin à lire un électrocardiogramme ; lui seul conclut.
- Les MESURES AUTOMATIQUES fournies (fréquence, intervalles RR, variabilité, étriers) viennent du logiciel : ne les contredis pas. Si le tracé te semble différent, dis-le explicitement comme un désaccord à vérifier.
- Ne déclare une onde P présente que si tu la vois nettement avant chaque QRS ; sinon écris « non identifiable ».
- Si l’image est floue, coupée, ou si le calibrage est illisible, dis-le et baisse ta confiance.
- Ne donne jamais une lecture comme certaine : tu es une aide à la lecture, pas un diagnostic.
- Réponds en français médical concis.
TXT;

    public function __construct(
        private readonly AiGateway $gateway,
        private readonly EcgSafetyRules $rules,
        private readonly PatientContextBuilder $context,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function analyze(User $user, EcgRecord $ecg): array
    {
        $prompt = $this->contextText($ecg)."\n\n".<<<'TXT'
TÂCHE : lis ce tracé selon la structure AHA/ACC/HRS et réponds UNIQUEMENT en JSON :
{
  "quality": {"rating": "bonne" | "moyenne" | "mauvaise", "issues": [string]},
  "leads_visible": string,
  "rate_bpm": number | null,
  "regularity": "régulier" | "irrégulier" | "indéterminé",
  "rhythm": string,
  "p_waves": string,
  "pr_ms": number | null,
  "qrs_ms": number | null,
  "qt_ms": number | null,
  "axis": string,
  "st_t": string,
  "other_findings": [string],
  "primary_statement": string,
  "secondary_statements": [string],
  "urgency": "normal" | "anormal" | "critique",
  "confidence": "faible" | "moyenne" | "élevée",
  "recommendations": [string]
}
Mets null pour une valeur que tu ne peux pas mesurer sur l’image. "primary_statement" = conclusion principale en une phrase.
TXT;

        $completion = $this->gateway->complete(
            $user,
            AiFeature::ECG_ANALYSIS,
            [
                ['role' => 'system', 'content' => self::SYSTEM],
                ['role' => 'user', 'content' => [$this->imagePart($ecg), ['type' => 'text', 'text' => $prompt]]],
            ],
            vision: true,
        );
        $json = $completion->json();

        $analysis = [
            'quality' => [
                'rating' => in_array($json['quality']['rating'] ?? null, ['bonne', 'moyenne', 'mauvaise'], true) ? $json['quality']['rating'] : 'moyenne',
                'issues' => $this->strings($json['quality']['issues'] ?? []),
            ],
            'leads_visible' => $this->text($json['leads_visible'] ?? null),
            'rate_bpm' => is_numeric($json['rate_bpm'] ?? null) ? (int) round((float) $json['rate_bpm']) : null,
            'regularity' => in_array($json['regularity'] ?? null, ['régulier', 'irrégulier', 'indéterminé'], true) ? $json['regularity'] : 'indéterminé',
            'rhythm' => $this->text($json['rhythm'] ?? null),
            'p_waves' => $this->text($json['p_waves'] ?? null),
            'pr_ms' => $this->ms($json['pr_ms'] ?? null),
            'qrs_ms' => $this->ms($json['qrs_ms'] ?? null),
            'qt_ms' => $this->ms($json['qt_ms'] ?? null),
            'axis' => $this->text($json['axis'] ?? null),
            'st_t' => $this->text($json['st_t'] ?? null),
            'other_findings' => $this->strings($json['other_findings'] ?? []),
            'primary_statement' => $this->text($json['primary_statement'] ?? null),
            'secondary_statements' => $this->strings($json['secondary_statements'] ?? []),
            'urgency' => in_array($json['urgency'] ?? null, ['normal', 'anormal', 'critique'], true) ? $json['urgency'] : 'anormal',
            'confidence' => in_array($json['confidence'] ?? null, ['faible', 'moyenne', 'élevée'], true) ? $json['confidence'] : 'faible',
            'recommendations' => $this->strings($json['recommendations'] ?? []),
            'model' => $completion->model,
            'analyzed_at' => now()->toIso8601String(),
        ];

        $ecg->update(['analysis' => $analysis]);

        return ['ecg' => $this->payload($ecg->refresh()), 'balance' => $completion->balance];
    }

    /**
     * @return array<string, mixed>
     */
    public function chat(User $user, EcgRecord $ecg, string $question): array
    {
        $history = collect($ecg->conversation ?? [])->take(-12)
            ->map(fn (array $turn): array => ['role' => $turn['role'] === 'assistant' ? 'assistant' : 'user', 'content' => (string) $turn['content']])
            ->values()
            ->all();

        $messages = [
            ['role' => 'system', 'content' => self::SYSTEM."\n- Réponds en texte simple (pas de JSON), en 2 à 8 phrases, en citant ce que tu vois sur le tracé ou dans les mesures."],
            ['role' => 'user', 'content' => [$this->imagePart($ecg), ['type' => 'text', 'text' => $this->contextText($ecg)."\n\nVoici le tracé dont nous allons discuter."]]],
            ['role' => 'assistant', 'content' => 'Entendu, j’ai le tracé, les mesures et la lecture en cours. Quelle est votre question ?'],
            ...$history,
            ['role' => 'user', 'content' => $question],
        ];

        $completion = $this->gateway->complete($user, AiFeature::ECG_CHAT, $messages, vision: true, json: false);
        $reply = trim($completion->content);

        $conversation = $ecg->conversation ?? [];
        $conversation[] = ['role' => 'user', 'content' => $question, 'at' => now()->toIso8601String(), 'by' => $user->name];
        $conversation[] = ['role' => 'assistant', 'content' => $reply, 'at' => now()->toIso8601String()];
        $ecg->update(['conversation' => array_slice($conversation, -60)]);

        return ['ecg' => $this->payload($ecg->refresh()), 'balance' => $completion->balance];
    }

    /**
     * What the ECG tab renders, flags recomputed on every read.
     *
     * @return array<string, mixed>
     */
    public function payload(EcgRecord $ecg): array
    {
        $gender = Patient::query()->withTrashed()->whereKey($ecg->patient_id)->first()?->gender?->value;
        $flags = $this->rules->flags($ecg->measurements, $ecg->analysis, $gender);

        return [
            'id' => $ecg->getKey(),
            'title' => $ecg->title,
            'recorded_at' => $ecg->recorded_at?->toDateString(),
            'consultation_id' => $ecg->consultation_id,
            // Same-origin on purpose: the trace reader draws it on a canvas
            // and reads its pixels, which a cross-origin image forbids.
            'file_url' => route('app.ecgs.file', ['ecg' => $ecg->getKey()], false),
            'original_filename' => $ecg->original_filename,
            'measurements' => $ecg->measurements,
            'analysis' => $ecg->analysis,
            'flags' => $flags,
            'urgency' => $this->rules->urgency($ecg->analysis, $flags),
            'conversation' => $ecg->conversation ?? [],
            'doctor_conclusion' => $ecg->doctor_conclusion,
            'status' => $ecg->status,
            'validated_at' => $ecg->validated_at?->toIso8601String(),
            'validated_by' => $ecg->validator?->name,
            'created_at' => $ecg->created_at?->toIso8601String(),
        ];
    }

    private function contextText(EcgRecord $ecg): string
    {
        $patient = Patient::query()->withTrashed()->findOrFail($ecg->patient_id);
        $m = $ecg->measurements;
        $lines = ['ECG du '.($ecg->recorded_at?->format('d/m/Y') ?? '?').' — « '.$ecg->title.' »'];

        if ($m !== null && ($m['beat_count'] ?? 0) > 0) {
            $lines[] = 'MESURES AUTOMATIQUES : '.implode(' ; ', array_filter([
                ($m['beat_count'] ?? null) ? $m['beat_count'].' QRS détectés' : null,
                ($m['duration_s'] ?? null) ? 'sur '.$m['duration_s'].' s' : null,
                ($m['heart_rate_bpm'] ?? null) ? 'FC '.$m['heart_rate_bpm'].'/min' : null,
                ($m['rr_mean_ms'] ?? null) ? 'RR moyen '.$m['rr_mean_ms'].' ms (min '.$m['rr_min_ms'].', max '.$m['rr_max_ms'].')' : null,
                ($m['rr_cv_percent'] ?? null) !== null ? 'variabilité RR '.$m['rr_cv_percent'].' % ('.(($m['rr_cv_percent'] > EcgSafetyRules::IRREGULAR_CV_PERCENT) ? 'irrégulier' : (($m['rr_cv_percent'] < EcgSafetyRules::REGULAR_CV_PERCENT) ? 'régulier' : 'limite')).')' : null,
                'vitesse '.($m['paper_speed_mm_s'] ?? 25).' mm/s',
            ]));

            foreach ($m['calipers'] ?? [] as $caliper) {
                $lines[] = 'Étrier '.$caliper['label'].' : '.$caliper['ms'].' ms';
            }

            if (($m['qtc_ms'] ?? null) !== null) {
                $lines[] = 'QTc (Bazett) calculé : '.$m['qtc_ms'].' ms';
            }
        } else {
            $lines[] = 'MESURES AUTOMATIQUES : aucune (le médecin n’a pas encore mesuré le tracé).';
        }

        if ($ecg->analysis !== null) {
            $lines[] = 'LECTURE IA ACTUELLE : '.($ecg->analysis['primary_statement'] ?? '').' (rythme : '.($ecg->analysis['rhythm'] ?? '?').')';
        }

        if ($ecg->doctor_conclusion) {
            $lines[] = 'CONCLUSION DU MÉDECIN : '.$ecg->doctor_conclusion;
        }

        return $this->context->build($patient)."\n\n".implode("\n", $lines);
    }

    /**
     * @return array{type: string, image_url: array{url: string}}
     */
    private function imagePart(EcgRecord $ecg): array
    {
        if (! Storage::exists($ecg->file_path)) {
            throw new AiException('Le fichier de cet ECG est introuvable sur ce poste.', AiException::UNSUPPORTED);
        }

        $bytes = (string) Storage::get($ecg->file_path);

        if (strlen($bytes) > (int) config('ai.max_image_bytes')) {
            throw new AiException('Cette image est trop lourde pour l’IA (4 Mo maximum). Réimportez-la : elle sera réduite automatiquement.', AiException::UNSUPPORTED);
        }

        return ['type' => 'image_url', 'image_url' => ['url' => 'data:'.($ecg->mime_type ?? 'image/jpeg').';base64,'.base64_encode($bytes)]];
    }

    private function ms(mixed $value): ?int
    {
        return is_numeric($value) && (float) $value > 0 && (float) $value < 3000 ? (int) round((float) $value) : null;
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $value): array
    {
        return is_array($value)
            ? array_values(array_filter(array_map(fn ($item): string => $this->text($item), array_slice($value, 0, 12))))
            : [];
    }
}
