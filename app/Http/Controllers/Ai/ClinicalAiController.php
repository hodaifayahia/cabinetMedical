<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\AiInsight;
use App\Models\Consultation;
use App\Models\Document;
use App\Models\Patient;
use App\Models\User;
use App\Services\Ai\AiException;
use App\Services\Ai\AiGateway;
use App\Services\Ai\ClinicalAiAssistant;
use App\Services\Ai\CopilotService;
use App\Services\Ai\DictationAudio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * JSON endpoints behind the "✨ IA" buttons of the consultation workspace and
 * the patient list. Every failure answers with a message the doctor can read,
 * plus the reason and balance so the screen can offer the right next step.
 */
class ClinicalAiController extends Controller
{
    public function __construct(
        private readonly ClinicalAiAssistant $assistant,
        private readonly AiGateway $gateway,
    ) {}

    public function status(Request $request): JsonResponse
    {
        return response()->json($this->gateway->status($this->user($request)));
    }

    public function consultationText(Request $request, Consultation $consultation): JsonResponse
    {
        $data = $request->validate(['transcript' => ['nullable', 'string', 'max:20000']]);

        return $this->run(fn (): array => $this->assistant->suggestConsultation(
            $this->user($request),
            $consultation,
            $this->draft($request),
            $data['transcript'] ?? null,
        ));
    }

    /**
     * One recorded segment of the doctor's dictation, as text. The audio is
     * forwarded to the speech model and never stored.
     */
    public function transcribeDictation(Request $request, Consultation $consultation): JsonResponse
    {
        $request->validate(['audio' => DictationAudio::rules()], DictationAudio::messages());
        $audio = $request->file('audio');
        abort_unless($audio instanceof UploadedFile, 422);

        return $this->run(function () use ($request, $audio): array {
            $completion = $this->gateway->transcribe(
                $this->user($request),
                (string) $audio->getRealPath(),
                DictationAudio::mimeOf($audio),
            );

            return ['text' => $completion->content, 'balance' => $completion->balance];
        });
    }

    public function copilotHistory(Consultation $consultation, CopilotService $copilot): JsonResponse
    {
        return response()->json(['messages' => $copilot->history($consultation)]);
    }

    public function copilot(Request $request, Consultation $consultation, CopilotService $copilot): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'workspace' => ['nullable', 'array'],
            'workspace.exams' => ['nullable', 'array', 'max:60'],
            'workspace.exams.*' => ['string', 'max:200'],
            'workspace.medications' => ['nullable', 'array', 'max:30'],
            'workspace.medications.*' => ['string', 'max:200'],
        ]);

        return $this->run(fn (): array => $copilot->ask(
            $this->user($request),
            $consultation,
            trim($data['message']),
            $this->draft($request),
            [
                'exams' => array_values($data['workspace']['exams'] ?? []),
                'medications' => array_values($data['workspace']['medications'] ?? []),
            ],
        ));
    }

    public function copilotReset(Consultation $consultation, CopilotService $copilot): JsonResponse
    {
        $copilot->clear($consultation);

        return response()->json(['messages' => []]);
    }

    public function exams(Request $request, Consultation $consultation): JsonResponse
    {
        return $this->run(fn (): array => $this->assistant->suggestExams(
            $this->user($request),
            $consultation,
            $this->draft($request),
        ));
    }

    public function prescription(Request $request, Consultation $consultation): JsonResponse
    {
        $data = $request->validate([
            'current_items' => ['nullable', 'array', 'max:30'],
            'current_items.*' => ['string', 'max:200'],
        ]);

        return $this->run(fn (): array => $this->assistant->suggestPrescription(
            $this->user($request),
            $consultation,
            $this->draft($request),
            array_values($data['current_items'] ?? []),
        ));
    }

    public function analyzeDocument(Request $request, Consultation $consultation, Document $document): JsonResponse
    {
        abort_if((int) $document->patient_id !== (int) $consultation->patient_id, 404);

        return $this->run(fn (): array => $this->assistant->analyzeDocument($this->user($request), $document));
    }

    public function documentAnalyses(Consultation $consultation): JsonResponse
    {
        $latest = AiInsight::query()
            ->where('patient_id', $consultation->patient_id)
            ->where('kind', AiInsight::KIND_DOCUMENT_ANALYSIS)
            ->whereNotNull('document_id')
            ->latest()
            ->get()
            ->unique('document_id')
            ->map(fn (AiInsight $insight): array => $this->assistant->insightPayload($insight))
            ->values();

        return response()->json(['analyses' => $latest]);
    }

    public function patientAnalysis(Patient $patient): JsonResponse
    {
        $insight = AiInsight::query()
            ->where('patient_id', $patient->getKey())
            ->where('kind', AiInsight::KIND_PATIENT_ANALYSIS)
            ->latest()
            ->first();

        return response()->json([
            'analysis' => $insight instanceof AiInsight ? $this->assistant->insightPayload($insight) : null,
        ]);
    }

    public function analyzePatient(Request $request, Patient $patient): JsonResponse
    {
        return $this->run(fn (): array => $this->assistant->analyzePatient($this->user($request), $patient));
    }

    /**
     * @param  callable(): array<string, mixed>  $call
     */
    private function run(callable $call): JsonResponse
    {
        try {
            return response()->json($call());
        } catch (AiException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'reason' => $exception->reason,
                'balance' => $exception->balance,
            ], $exception->httpStatus());
        }
    }

    /**
     * The visit fields as they are on screen right now: autosave may not have
     * caught up with the last keystrokes.
     *
     * @return array<string, mixed>
     */
    private function draft(Request $request): array
    {
        $data = $request->validate([
            'draft' => ['nullable', 'array'],
            'draft.motif' => ['nullable', 'string', 'max:5000'],
            'draft.examens' => ['nullable', 'string', 'max:5000'],
            'draft.diagnostic' => ['nullable', 'string', 'max:5000'],
            'draft.traitement' => ['nullable', 'string', 'max:5000'],
            'draft.notes' => ['nullable', 'string', 'max:5000'],
            'draft.weight_kg' => ['nullable', 'numeric'],
            'draft.height_cm' => ['nullable', 'numeric'],
            'draft.temperature_c' => ['nullable', 'numeric'],
            'draft.blood_pressure' => ['nullable', 'string', 'max:20'],
        ]);

        return array_intersect_key($data['draft'] ?? [], array_flip([
            'motif', 'examens', 'diagnostic', 'traitement', 'notes',
            'weight_kg', 'height_cm', 'temperature_c', 'blood_pressure',
        ]));
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
