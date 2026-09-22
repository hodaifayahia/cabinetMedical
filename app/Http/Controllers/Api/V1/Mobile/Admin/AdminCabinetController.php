<?php

namespace App\Http\Controllers\Api\V1\Mobile\Admin;

use App\Enums\FacilityType;
use App\Enums\LicensePlan;
use App\Http\Requests\Api\Mobile\Admin\IndexAdminCabinetsRequest;
use App\Http\Requests\Api\Mobile\Admin\StoreAdminCabinetRequest;
use App\Http\Resources\Mobile\Admin\AdminCabinetListItemResource;
use App\Models\AuditLog;
use App\Models\Cabinet;
use App\Services\Cabinet\CabinetProvisioningService;
use App\Services\CabinetFulfillmentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * Platform back office: browse every clinic on the platform, open one, and
 * create a new clinic together with its doctor owner.
 *
 * Creation is the headline operation and deliberately reuses
 * CabinetProvisioningService — the very code path web self-registration runs —
 * so an admin-created clinic is indistinguishable from a self-registered one:
 * Doctor role, approved owner, DoctorProfile, CabinetSetting and a default
 * Monday-to-Friday schedule. Activation and directory listing then go through
 * the existing CabinetFulfillmentService and public-profile row rather than
 * through a second, divergent implementation.
 */
class AdminCabinetController extends AdminController
{
    public function __construct(
        private readonly CabinetProvisioningService $provisioning,
        private readonly CabinetFulfillmentService $fulfillment,
    ) {}

    /**
     * Paginated, cross-tenant clinic list.
     */
    public function index(IndexAdminCabinetsRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        $cabinets = Cabinet::query()
            ->withoutGlobalScopes()
            ->with('owner:id,name')
            ->when(
                isset($filters['status']),
                static fn (Builder $query) => $query->where('status', $filters['status']),
            )
            ->when(
                isset($filters['wilaya_code']),
                static fn (Builder $query) => $query->where('wilaya_code', $filters['wilaya_code']),
            )
            ->when(
                filled($filters['q'] ?? null),
                static fn (Builder $query) => $query->where(static function (Builder $match) use ($filters): void {
                    $term = '%'.$filters['q'].'%';

                    $match->where('cabinets.name', 'like', $term)
                        ->orWhereHas('owner', static fn (Builder $owner) => $owner
                            ->where('name', 'like', $term)
                            ->orWhere('email', 'like', $term));
                }),
            )
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 15))
            ->withQueryString();

        $this->attachCabinetListAttributes($cabinets->getCollection());

        return AdminCabinetListItemResource::collection($cabinets);
    }

    /**
     * Create a clinic and its doctor owner in one transaction.
     */
    public function store(StoreAdminCabinetRequest $request): JsonResponse
    {
        $data = $request->validated();
        $actor = $request->user();
        [$password, $temporaryPassword] = $this->resolveInitialPassword($data['password'] ?? null);

        $shouldActivate = (bool) ($data['activate'] ?? false);
        $shouldList = (bool) ($data['is_listed'] ?? false);
        // Provisioning creates a doctor's practice; an admin classifies it as
        // a clinic or an imaging centre here or later, and the patient app's
        // search tabs follow.
        $facilityType = FacilityType::from((string) ($data['facility_type'] ?? FacilityType::DOCTOR->value));

        $cabinet = DB::transaction(function () use ($data, $password, $shouldList, $facilityType, $actor): Cabinet {
            $owner = $this->provisioning->provision([
                'name' => $data['doctor_name'],
                'email' => $data['email'],
                'password' => $password,
                'phone' => $data['phone'],
                'cabinet_name' => $data['cabinet_name'],
                'specialization' => $data['specialization'],
                'wilaya' => $data['wilaya_code'],
            ]);

            /** @var Cabinet $cabinet */
            $cabinet = Cabinet::query()
                ->withoutGlobalScopes()
                ->findOrFail((int) $owner->cabinet_id);

            if ($facilityType !== FacilityType::DOCTOR) {
                $cabinet->forceFill(['facility_type' => $facilityType])->save();
            }

            if ($shouldList) {
                $this->setCabinetListed($cabinet, true);
            }

            AuditLog::record('admin.cabinet_provisioned', $cabinet, [
                'owner_user_id' => $owner->getKey(),
                'owner_email' => $owner->email,
                'wilaya_code' => $cabinet->wilaya_code,
                'specialization' => $cabinet->specialization,
                'is_listed' => $shouldList,
                'facility_type' => $facilityType->value,
                // Deliberately not named "password_generated":
                // AuditLog::redactSensitiveMetadata() matches "password"
                // anywhere in a key, so that name would be stored as the
                // literal string [redacted] and the signal lost for every row.
                'credential_source' => filled($data['password'] ?? null) ? 'supplied' : 'generated',
            ], $actor?->getKey());

            return $cabinet;
        });

        // Activation mints a hosted entitlement and mails the owner, so it
        // runs on its own transaction after the clinic exists.
        if ($shouldActivate) {
            $cabinet = $this->fulfillment->activate($cabinet, LicensePlan::LIFETIME);
        }

        return $this->cabinetDetail($cabinet->refresh())
            ->additional(['temporary_password' => $temporaryPassword])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Full detail of one clinic.
     */
    public function show(int $cabinet): JsonResponse
    {
        return $this->cabinetDetail($this->findCabinet($cabinet))
            ->response();
    }
}
