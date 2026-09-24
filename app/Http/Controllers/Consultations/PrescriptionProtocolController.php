<?php

namespace App\Http\Controllers\Consultations;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PrescriptionProtocol;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Saved ordonnance sets. Applying one only fills the editor: the doctor
 * still reviews it and the usual allergy check runs when it is saved.
 */
class PrescriptionProtocolController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique('prescription_protocols', 'name')->where(
                    static fn ($query) => $request->user()?->cabinet_id === null
                        ? $query->whereNull('cabinet_id')
                        : $query->where('cabinet_id', $request->user()->cabinet_id),
                ),
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.medication' => ['required', 'string', 'max:200'],
            'items.*.dosage' => ['nullable', 'string', 'max:200'],
            'items.*.duration' => ['nullable', 'string', 'max:100'],
            'items.*.instructions' => ['nullable', 'string', 'max:500'],
        ], [
            'name.unique' => 'Un protocole porte déjà ce nom.',
        ]);

        $protocol = PrescriptionProtocol::query()->create([
            'name' => trim((string) $data['name']),
            'notes' => filled($data['notes'] ?? null) ? $data['notes'] : null,
            'items' => array_map(static fn (array $item): array => [
                'medication' => trim((string) $item['medication']),
                'dosage' => (string) ($item['dosage'] ?? ''),
                'duration' => (string) ($item['duration'] ?? ''),
                'instructions' => (string) ($item['instructions'] ?? ''),
            ], array_values($data['items'])),
            'created_by' => $request->user()?->getKey(),
        ]);

        AuditLog::record('prescription_protocol.created', $protocol, ['name' => $protocol->name]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Protocole « '.$protocol->name.' » enregistré.']);

        return back();
    }

    /**
     * Counts usage so the most used protocols come first.
     */
    public function used(PrescriptionProtocol $protocol): RedirectResponse
    {
        $protocol->increment('uses');

        return back();
    }

    public function destroy(PrescriptionProtocol $protocol): RedirectResponse
    {
        AuditLog::record('prescription_protocol.deleted', $protocol, ['name' => $protocol->name]);
        $protocol->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Protocole supprimé.']);

        return back();
    }
}
