<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Mobile\StoreWalkInPatientRequest;
use App\Http\Resources\PatientResource;
use App\Models\Patient;
use Illuminate\Http\JsonResponse;

/**
 * Walk-in registration from the staff mobile app. The phone number is the
 * dedup key inside the cabinet: an existing dossier is returned as-is so the
 * desk never creates duplicates for a returning patient.
 */
class StaffPatientController extends Controller
{
    public function store(StoreWalkInPatientRequest $request): JsonResponse
    {
        $this->authorize('create', Patient::class);

        $data = $request->validated();

        // The cabinet global scope narrows the lookup to the caller's tenant.
        $existing = Patient::query()
            ->where('phone', $data['phone'])
            ->first();

        if ($existing !== null) {
            return (new PatientResource($existing))
                ->additional(['existing' => true])
                ->response();
        }

        // cabinet_id and patient_number are assigned by the model's creating
        // hooks; created_by records the staff member at the desk.
        $patient = Patient::query()->create([
            ...$data,
            'created_by' => $request->user()?->id,
        ]);

        return (new PatientResource($patient))
            ->additional(['existing' => false])
            ->response()
            ->setStatusCode(201);
    }
}
