<?php

namespace App\Services\Ai;

use App\Enums\AiFeature;
use App\Models\AiConversation;
use App\Models\Consultation;
use App\Models\Exam;
use App\Models\Medication;
use App\Models\Patient;
use App\Models\User;
use App\Services\Clinical\PatientSafety;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The consultation copilot: a conversation grounded in the patient's dossier
 * that can *propose* actions — fill a visit field, add an exam to the bilan,
 * add a medication to the ordonnance, draft advice for the patient.
 *
 * Nothing is applied here. The screen shows each proposal as a card and the
 * doctor applies it with a click ("AI advises, clinician decides"); the whole
 * exchange is kept in ai_conversations as the audit trail.
 */
final class CopilotService
{
    private const SYSTEM = <<<'TXT'
Tu es le Copilote de ClickDz, assistant d’un médecin pendant sa consultation en Algérie.
- Tu travailles UNIQUEMENT à partir du dossier fourni. Si une information n’y est pas, dis-le au lieu de l’inventer.
- Cite dans "sources" les éléments du dossier sur lesquels tu t’appuies (ex. « Antécédents », « Consultation du 12/03/2026 », « Ordonnance du 02/04/2026 », « ECG du 05/05/2026 »).
- Vérifie systématiquement allergies, interactions, âge, grossesse possible et fonction rénale avant de proposer un médicament ; signale tout risque dans "reply".
- Quand le médecin demande de remplir, ajouter ou préparer quelque chose, propose-le dans "actions" : le médecin validera chaque action d’un clic. N’ajoute pas d’action qu’il n’a pas demandée, sauf un examen ou une précaution vraiment importants.
- Pour des conseils destinés au patient, écris simplement ; en arabe si le médecin le demande.
- Français médical concis. Réponds UNIQUEMENT en JSON valide :
{
  "reply": string,
  "sources": [string],
  "actions": [
    {"type": "set_field", "field": "motif" | "examens" | "diagnostic" | "traitement" | "notes", "text": string, "mode": "replace" | "append"},
    {"type": "add_exam", "name": string, "reason": string},
    {"type": "add_medication", "medication": string, "dosage": string, "duration": string, "instructions": string, "reason": string},
    {"type": "patient_advice", "text": string}
  ]
}
TXT;

    public function __construct(
        private readonly AiGateway $gateway,
        private readonly PatientContextBuilder $context,
        private readonly PatientSafety $safety,
    ) {}

    /**
     * @param  array<string, mixed>  $draft
     * @param  array{exams?: list<string>, medications?: list<string>}  $workspace  What is already on the bilan / ordonnance being written.
     * @return array<string, mixed>
     */
    public function ask(User $user, Consultation $consultation, string $message, array $draft, array $workspace = []): array
    {
        $patient = Patient::query()->withTrashed()->findOrFail($consultation->patient_id);
        $conversation = $this->conversationFor($consultation);

        $history = collect($conversation?->messages ?? [])
            ->take(-12)
            ->map(fn (array $turn): array => [
                'role' => $turn['role'] === 'assistant' ? 'assistant' : 'user',
                'content' => $turn['role'] === 'assistant'
                    ? (string) ($turn['reply'] ?? '').$this->actionSummary($turn['actions'] ?? [])
                    : (string) ($turn['content'] ?? ''),
            ])
            ->values()
            ->all();

        $context = $this->context->build($patient, $consultation, $draft, full: true)
            ."\n\nEN COURS DANS CETTE CONSULTATION\n"
            .'Bilan en préparation : '.($this->listOrNone($workspace['exams'] ?? []))."\n"
            .'Ordonnance en préparation : '.($this->listOrNone($workspace['medications'] ?? []));

        $completion = $this->gateway->complete($user, AiFeature::COPILOT_CHAT, [
            ['role' => 'system', 'content' => self::SYSTEM],
            ['role' => 'user', 'content' => "DOSSIER DU PATIENT\n".$context],
            ['role' => 'assistant', 'content' => '{"reply": "J’ai le dossier. Je vous écoute.", "sources": [], "actions": []}'],
            ...$history,
            ['role' => 'user', 'content' => $message],
        ]);

        $json = $completion->json();
        $answer = [
            'role' => 'assistant',
            'reply' => $this->text($json['reply'] ?? null) ?: 'Je n’ai pas pu formuler de réponse. Reformulez votre demande.',
            'sources' => $this->strings($json['sources'] ?? []),
            'actions' => $this->withAllergyConflicts($patient, $this->actions($json['actions'] ?? [])),
            'at' => now()->toIso8601String(),
        ];

        $messages = [
            ...($conversation?->messages ?? []),
            ['role' => 'user', 'content' => $message, 'at' => now()->toIso8601String(), 'by' => $user->name],
            $answer,
        ];

        if ($conversation === null) {
            $conversation = AiConversation::query()->create([
                'patient_id' => $patient->getKey(),
                'consultation_id' => $consultation->getKey(),
                'messages' => $messages,
                'created_by' => $user->getKey(),
            ]);
        } else {
            $conversation->update(['messages' => array_slice($messages, -80)]);
        }

        return [
            'conversation_id' => $conversation->getKey(),
            'message' => $answer,
            'balance' => $completion->balance,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function history(Consultation $consultation): array
    {
        return $this->conversationFor($consultation)?->messages ?? [];
    }

    public function clear(Consultation $consultation): void
    {
        // A new conversation starts; the old one stays as the audit trail.
        AiConversation::query()->create([
            'patient_id' => $consultation->patient_id,
            'consultation_id' => $consultation->getKey(),
            'messages' => [],
            'created_by' => auth()->id(),
        ]);
    }

    private function conversationFor(Consultation $consultation): ?AiConversation
    {
        return AiConversation::query()
            ->where('consultation_id', $consultation->getKey())
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Keep only well-formed actions, matched against the cabinet catalogues.
     *
     * @return list<array<string, mixed>>
     */
    private function actions(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $exams = null;
        $medications = null;
        $actions = [];

        foreach (array_slice($raw, 0, 15) as $action) {
            if (! is_array($action)) {
                continue;
            }

            switch ($action['type'] ?? null) {
                case 'set_field':
                    $field = $action['field'] ?? null;
                    $text = $this->text($action['text'] ?? null);

                    if (in_array($field, ['motif', 'examens', 'diagnostic', 'traitement', 'notes'], true) && $text !== '') {
                        $actions[] = ['type' => 'set_field', 'field' => $field, 'text' => Str::limit($text, 5000, ''), 'mode' => ($action['mode'] ?? '') === 'append' ? 'append' : 'replace'];
                    }
                    break;

                case 'add_exam':
                    $name = $this->text($action['name'] ?? null);

                    if ($name !== '') {
                        $exams ??= Exam::query()->where('is_active', true)->get(['id', 'name']);
                        $match = $this->match($exams, $name);
                        $actions[] = ['type' => 'add_exam', 'name' => $match?->name ?? $name, 'exam_id' => $match?->getKey(), 'reason' => $this->text($action['reason'] ?? null)];
                    }
                    break;

                case 'add_medication':
                    $name = $this->text($action['medication'] ?? null);

                    if ($name !== '') {
                        $medications ??= Medication::query()->where('is_active', true)->get(['id', 'name']);
                        $match = $this->match($medications, $name);
                        $actions[] = [
                            'type' => 'add_medication',
                            'medication' => Str::limit($match?->name ?? $name, 190, ''),
                            'in_catalogue' => $match !== null,
                            'dosage' => Str::limit($this->text($action['dosage'] ?? null), 190, ''),
                            'duration' => Str::limit($this->text($action['duration'] ?? null), 95, ''),
                            'instructions' => Str::limit($this->text($action['instructions'] ?? null), 480, ''),
                            'reason' => $this->text($action['reason'] ?? null),
                        ];
                    }
                    break;

                case 'patient_advice':
                    $text = $this->text($action['text'] ?? null);

                    if ($text !== '') {
                        $actions[] = ['type' => 'patient_advice', 'text' => Str::limit($text, 2000, '')];
                    }
                    break;
            }
        }

        return $actions;
    }

    /**
     * Run the cabinet's deterministic allergy check on every proposed
     * medication, so a conflict shows on the card before the doctor applies it.
     *
     * @param  list<array<string, mixed>>  $actions
     * @return list<array<string, mixed>>
     */
    private function withAllergyConflicts(Patient $patient, array $actions): array
    {
        $names = collect($actions)->where('type', 'add_medication')->pluck('medication')->values()->all();

        if ($names === []) {
            return $actions;
        }

        $conflicts = collect($this->safety->allergyConflicts($patient, $names))->groupBy('medication');

        return array_map(static fn (array $action): array => $action['type'] === 'add_medication'
            ? [...$action, 'allergy_conflicts' => $conflicts->get($action['medication'], collect())->pluck('reason')->values()->all()]
            : $action, $actions);
    }

    /**
     * @param  list<array<string, mixed>>  $actions
     */
    private function actionSummary(array $actions): string
    {
        if ($actions === []) {
            return '';
        }

        return "\n[Actions proposées : ".collect($actions)->map(fn (array $action): string => match ($action['type']) {
            'set_field' => 'remplir '.$action['field'],
            'add_exam' => 'examen '.$action['name'],
            'add_medication' => 'médicament '.$action['medication'],
            default => 'conseils patient',
        })->implode(', ').']';
    }

    /**
     * @param  Collection<int, Exam|Medication>  $items
     */
    private function match(Collection $items, string $suggestion): Exam|Medication|null
    {
        $needle = $this->normalise($suggestion);

        return $items->first(fn ($item): bool => $this->normalise($item->name) === $needle)
            ?? $items
                ->filter(function ($item) use ($needle): bool {
                    $candidate = $this->normalise($item->name);

                    return mb_strlen($candidate) >= 3 && (str_contains($needle, $candidate) || str_contains($candidate, $needle));
                })
                ->sortByDesc(fn ($item): int => mb_strlen($item->name))
                ->first();
    }

    private function normalise(string $value): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower(Str::ascii($value))));
    }

    /**
     * @param  list<string>  $items
     */
    private function listOrNone(array $items): string
    {
        $items = array_values(array_filter(array_map(static fn ($item): string => trim((string) $item), $items)));

        return $items === [] ? 'rien' : implode(', ', array_slice($items, 0, 30));
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
            ? array_values(array_filter(array_map(fn ($item): string => $this->text($item), array_slice($value, 0, 10))))
            : [];
    }
}
