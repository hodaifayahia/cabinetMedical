<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\EcgRecord;
use App\Models\User;
use App\Services\Ai\AiException;
use App\Services\Ai\EcgAiService;
use App\Services\Ai\EcgSafetyRules;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The consultation's ECG tab: upload a tracing, measure it, have the AI read
 * it, ask it questions, and sign the conclusion.
 */
class EcgController extends Controller
{
    public function __construct(
        private readonly EcgAiService $ecgAi,
        private readonly EcgSafetyRules $rules,
    ) {}

    public function index(Consultation $consultation): JsonResponse
    {
        $ecgs = EcgRecord::query()
            ->with('validator:id,name')
            ->where('patient_id', $consultation->patient_id)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (EcgRecord $ecg): array => $this->ecgAi->payload($ecg))
            ->values();

        return response()->json(['ecgs' => $ecgs]);
    }

    public function store(Request $request, Consultation $consultation): JsonResponse
    {
        $data = $request->validate([
            // Images only: the trace reader measures pixels. The screen
            // shrinks large phone photos before sending them.
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'title' => ['nullable', 'string', 'max:200'],
            'recorded_at' => ['nullable', 'date'],
        ]);

        $file = $data['file'];
        $path = $file->store('patient-ecgs/'.$consultation->patient_id);

        if ($path === false) {
            throw ValidationException::withMessages(['file' => __('The file could not be stored.')]);
        }

        $ecg = EcgRecord::query()->create([
            'patient_id' => $consultation->patient_id,
            'consultation_id' => $consultation->getKey(),
            'title' => Str::limit(trim((string) ($data['title'] ?? '')) ?: 'ECG', 200, ''),
            'recorded_at' => isset($data['recorded_at']) ? CarbonImmutable::parse($data['recorded_at']) : now(),
            'file_path' => $path,
            'original_filename' => Str::limit(basename($file->getClientOriginalName()), 190, ''),
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'status' => EcgRecord::STATUS_DRAFT,
            'created_by' => $this->user($request)->getKey(),
        ]);

        return response()->json(['ecg' => $this->ecgAi->payload($ecg)], 201);
    }

    public function file(EcgRecord $ecg): StreamedResponse
    {
        abort_unless(Storage::exists($ecg->file_path), 404);

        return Storage::response($ecg->file_path, $ecg->original_filename, [
            'Content-Type' => $ecg->mime_type ?? 'image/jpeg',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    public function measurements(Request $request, EcgRecord $ecg): JsonResponse
    {
        $data = $request->validate([
            'source' => ['nullable', 'in:auto,manual'],
            'paper_speed_mm_s' => ['nullable', 'integer', 'in:25,50'],
            'px_per_mm' => ['nullable', 'numeric', 'min:0.5', 'max:200'],
            'duration_s' => ['nullable', 'numeric', 'min:0', 'max:120'],
            'rr_ms' => ['nullable', 'array', 'max:300'],
            'rr_ms.*' => ['numeric'],
            'calipers' => ['nullable', 'array', 'max:20'],
            'calipers.*.label' => ['nullable', 'string', 'max:20'],
            'calipers.*.ms' => ['required', 'numeric'],
        ]);

        $ecg->update(['measurements' => $this->rules->normalise($data)]);

        return response()->json(['ecg' => $this->ecgAi->payload($ecg->refresh())]);
    }

    public function analyze(Request $request, EcgRecord $ecg): JsonResponse
    {
        return $this->run(fn (): array => $this->ecgAi->analyze($this->user($request), $ecg));
    }

    public function chat(Request $request, EcgRecord $ecg): JsonResponse
    {
        $data = $request->validate(['message' => ['required', 'string', 'max:1000']]);

        return $this->run(fn (): array => $this->ecgAi->chat($this->user($request), $ecg, trim($data['message'])));
    }

    public function conclude(Request $request, EcgRecord $ecg): JsonResponse
    {
        $data = $request->validate([
            'doctor_conclusion' => ['nullable', 'string', 'max:5000'],
            'title' => ['nullable', 'string', 'max:200'],
            'validate' => ['sometimes', 'boolean'],
        ]);

        $validate = (bool) ($data['validate'] ?? false);
        $conclusion = trim((string) ($data['doctor_conclusion'] ?? ''));

        if ($validate && $conclusion === '') {
            throw ValidationException::withMessages([
                'doctor_conclusion' => 'Écrivez votre conclusion avant de valider l’ECG.',
            ]);
        }

        $user = $this->user($request);
        $ecg->fill([
            'doctor_conclusion' => $conclusion !== '' ? $conclusion : null,
            'title' => isset($data['title']) && trim($data['title']) !== '' ? trim($data['title']) : $ecg->title,
        ]);

        if ($validate) {
            $ecg->fill([
                'status' => EcgRecord::STATUS_VALIDATED,
                'validated_by' => $user->getKey(),
                'validated_at' => now(),
            ]);
        } elseif ($ecg->isDirty('doctor_conclusion')) {
            // Editing a signed conclusion re-opens it for signature.
            $ecg->fill(['status' => EcgRecord::STATUS_DRAFT, 'validated_by' => null, 'validated_at' => null]);
        }

        $ecg->save();

        if ($validate) {
            AuditLog::record('ecg.validated', $ecg, [
                'ai_primary_statement' => $ecg->analysis['primary_statement'] ?? null,
                'ai_used' => $ecg->analysis !== null,
            ], $user->getKey());
        }

        return response()->json(['ecg' => $this->ecgAi->payload($ecg->refresh())]);
    }

    public function destroy(EcgRecord $ecg): JsonResponse
    {
        Storage::delete($ecg->file_path);
        $ecg->delete();

        return response()->json(['deleted' => true]);
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

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
