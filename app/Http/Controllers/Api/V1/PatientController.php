<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PatientResource;
use App\Models\Patient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PatientController extends Controller
{
    /**
     * Searchable, paginated list of the cabinet's patients.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Patient::class);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $search = trim((string) ($validated['q'] ?? ''));

        $patients = Patient::query()
            ->when($search !== '', fn ($query) => $query->search($search))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate((int) ($validated['per_page'] ?? 15))
            ->withQueryString();

        return PatientResource::collection($patients);
    }

    public function show(Patient $patient): PatientResource
    {
        $this->authorize('view', $patient);

        return new PatientResource($patient);
    }
}
