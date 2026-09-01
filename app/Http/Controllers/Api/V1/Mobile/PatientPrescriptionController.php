<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Resources\Mobile\MobilePrescriptionResource;
use App\Models\FamilyMember;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Prescriptions written for the dossiers that belong to this patient account:
 * the account's own dossier rows plus the ones of family members the account
 * owns. Ownership is explicit — the tenant scope is inert for patients.
 */
class PatientPrescriptionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $ownedPatientIds = Patient::withoutCabinetScope()
            ->where(function (Builder $query) use ($user): void {
                $query->where('patient_user_id', $user->getKey())
                    ->orWhereIn('family_member_id', FamilyMember::query()
                        ->where('owner_user_id', $user->getKey())
                        ->select('id'));
            })
            ->select('id');

        $prescriptions = Prescription::withoutCabinetScope()
            ->whereIn('patient_id', $ownedPatientIds)
            ->with(['patient', 'cabinet'])
            ->latest('prescribed_at')
            ->latest('id')
            ->paginate((int) ($validated['per_page'] ?? 15))
            ->withQueryString();

        return MobilePrescriptionResource::collection($prescriptions);
    }
}
