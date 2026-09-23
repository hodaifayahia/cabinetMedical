<?php

namespace App\Http\Controllers\Consultations;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BilanTemplate;
use App\Models\Exam;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Named exam selections saved from the bilan editor.
 *
 * Templates are cabinet-scoped through the model's global scope, so a
 * practitioner only ever sees and edits their own cabinet's presets.
 */
class BilanTemplateController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:120',
                // Scoped by the tenant's cabinet so two cabinets may each have
                // a "Bilan pré-opératoire" without colliding.
                Rule::unique('bilan_templates', 'name')
                    ->where('cabinet_id', $request->user()?->cabinet_id),
            ],
            'exam_ids' => ['required', 'array', 'min:1', 'max:100'],
            // Existence is checked against the cabinet-scoped Exam query below
            // rather than with `exists`, which would ignore the tenant scope.
            'exam_ids.*' => ['integer'],
        ], [
            // Written in French rather than through __(): the app runs with
            // locale "fr" but ships no fr.json, so a translation key would
            // surface to the practitioner in English.
            'name.unique' => 'Un modèle portant ce nom existe déjà.',
            'exam_ids.required' => 'Sélectionnez au moins un examen pour enregistrer un modèle.',
        ]);

        $examIds = $this->cabinetExamIds($data['exam_ids']);

        if ($examIds === []) {
            return back()->withErrors([
                'exam_ids' => 'Sélectionnez au moins un examen pour enregistrer un modèle.',
            ]);
        }

        /** @var User $user */
        $user = $request->user();

        $template = BilanTemplate::query()->create([
            'name' => $data['name'],
            'exam_ids' => $examIds,
        ]);

        AuditLog::record('bilan_template.created', $template, [
            'exam_count' => count($examIds),
        ], $user->getKey());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Modèle enregistré.',
        ]);

        return back();
    }

    public function destroy(Request $request, BilanTemplate $bilanTemplate): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        AuditLog::record('bilan_template.deleted', $bilanTemplate, [
            'name' => $bilanTemplate->name,
        ], $user->getKey());

        $bilanTemplate->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Modèle supprimé.',
        ]);

        return back();
    }

    /**
     * Keep only ids that name an active exam in the caller's cabinet, in the
     * order they were submitted — that order is the order they print in.
     *
     * @param  list<mixed>  $submitted
     * @return list<int>
     */
    private function cabinetExamIds(array $submitted): array
    {
        $allowed = Exam::query()
            ->where('is_active', true)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $allowed = array_flip($allowed);
        $seen = [];

        foreach ($submitted as $id) {
            $id = (int) $id;

            if (isset($allowed[$id]) && ! isset($seen[$id])) {
                $seen[$id] = true;
            }
        }

        return array_keys($seen);
    }
}
