<?php

namespace App\Http\Controllers\Api\V1\Mobile\Admin;

use App\Models\AuditLog;
use App\Models\MedicalSpecialty;
use App\Support\MedicalSpecialtyCatalog;
use App\Support\SpecialtyDoctorCounts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Platform back office: the medical specialty catalogue.
 *
 * The patient app's specialty filter offers only ACTIVE specialties, so an
 * admin keeps that list short by switching off what the platform does not
 * cover yet. Switching one off never hides a doctor: it only removes the
 * filter entry. Each row carries its listed-doctor count so the admin can see
 * which specialties patients would actually find.
 *
 * A specialty's `code` is derived from its French label once, at creation,
 * and never changes afterwards: doctor profiles store it.
 */
class AdminSpecialtyController extends AdminController
{
    /** Every specialty, active or not, in catalogue order. */
    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('q', ''));

        $rows = MedicalSpecialty::query()
            ->when($search !== '', static fn ($query) => $query->where(
                static fn ($match) => $match
                    ->where('label_fr', 'like', "%{$search}%")
                    ->orWhere('label_ar', 'like', "%{$search}%"),
            ))
            ->orderBy('id')
            ->get();

        $doctorCounts = $this->listedDoctorCounts();

        return response()->json([
            'data' => $rows
                ->map(fn (MedicalSpecialty $specialty): array => $this->row($specialty, $doctorCounts))
                ->all(),
        ]);
    }

    /** Add a specialty to the catalogue. */
    public function store(Request $request, MedicalSpecialtyCatalog $catalog): JsonResponse
    {
        $data = $request->validate([
            'label_fr' => ['required', 'string', 'min:2', 'max:100'],
            'label_ar' => ['required', 'string', 'min:2', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $labelFr = $this->clean($data['label_fr']);
        $code = $catalog->codeFor($labelFr);

        // codeFor() maps a label the catalogue already knows (in any casing,
        // or a built-in label since renamed) onto its existing code.
        if (MedicalSpecialty::query()->where('code', $code)->exists() || $this->labelTaken($labelFr)) {
            throw ValidationException::withMessages([
                'label_fr' => 'Cette spécialité existe déjà.',
            ]);
        }

        $specialty = MedicalSpecialty::query()->create([
            'code' => $code,
            'label_fr' => $labelFr,
            'label_ar' => $this->clean($data['label_ar']),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        AuditLog::record('admin.specialty_created', $specialty, [
            'code' => $specialty->code,
            'label_fr' => $specialty->label_fr,
            'is_active' => $specialty->is_active,
        ], $request->user()?->getKey());

        return response()->json(['data' => $this->row($specialty, $this->listedDoctorCounts())], 201);
    }

    /** Correct a specialty's labels and/or switch it on or off. */
    public function update(Request $request, int $specialty): JsonResponse
    {
        $target = MedicalSpecialty::query()->whereKey($specialty)->first();

        abort_if($target === null, 404, 'Spécialité introuvable.');

        $data = $request->validate([
            'label_fr' => ['sometimes', 'string', 'min:2', 'max:100'],
            'label_ar' => ['sometimes', 'string', 'min:2', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $changes = [];

        if (array_key_exists('label_fr', $data)) {
            $labelFr = $this->clean($data['label_fr']);

            if ($this->labelTaken($labelFr, $target->id)) {
                throw ValidationException::withMessages([
                    'label_fr' => 'Une autre spécialité porte déjà ce nom.',
                ]);
            }

            $changes['label_fr'] = $labelFr;
        }

        if (array_key_exists('label_ar', $data)) {
            $changes['label_ar'] = $this->clean($data['label_ar']);
        }

        if (array_key_exists('is_active', $data)) {
            $changes['is_active'] = (bool) $data['is_active'];
        }

        if ($changes !== []) {
            $target->forceFill($changes)->save();

            AuditLog::record('admin.specialty_updated', $target, [
                'code' => $target->code,
                ...$changes,
            ], $request->user()?->getKey());
        }

        return response()->json(['data' => $this->row($target, $this->listedDoctorCounts())]);
    }

    /**
     * @param  array<string, int>  $doctorCounts
     * @return array{id: int, code: string, label_fr: string, label_ar: string|null, is_active: bool, doctors: int}
     */
    private function row(MedicalSpecialty $specialty, array $doctorCounts): array
    {
        return [
            'id' => $specialty->id,
            'code' => $specialty->code,
            'label_fr' => $specialty->label_fr,
            'label_ar' => $specialty->label_ar,
            'is_active' => $specialty->is_active,
            'doctors' => $doctorCounts[$specialty->code] ?? 0,
        ];
    }

    /**
     * The doctors a patient could reach through each specialty filter.
     *
     * @return array<string, int>
     */
    private function listedDoctorCounts(): array
    {
        return app(SpecialtyDoctorCounts::class)->listed();
    }

    /**
     * Case-insensitive, compared in PHP: SQLite's lower() only folds ASCII,
     * so "PÉDIATRIE" would slip past "Pédiatrie" in SQL. The table is small.
     */
    private function labelTaken(string $labelFr, ?int $ignoreId = null): bool
    {
        $needle = Str::lower($labelFr);

        return MedicalSpecialty::query()
            ->when($ignoreId !== null, static fn ($query) => $query->whereKeyNot($ignoreId))
            ->pluck('label_fr')
            ->contains(static fn (string $label): bool => Str::lower($label) === $needle);
    }

    /** Collapse runs of whitespace so "  Pédiatrie  " and "Pédiatrie" match. */
    private function clean(string $label): string
    {
        return (string) Str::of($label)->squish();
    }
}
