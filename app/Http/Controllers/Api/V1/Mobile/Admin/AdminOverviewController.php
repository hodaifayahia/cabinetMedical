<?php

namespace App\Http\Controllers\Api\V1\Mobile\Admin;

use App\Enums\AppointmentStatus;
use App\Enums\CabinetStatus;
use App\Enums\RoleName;
use App\Http\Resources\Mobile\Admin\AdminCabinetListItemResource;
use App\Models\Appointment;
use App\Models\Cabinet;
use App\Models\CabinetPublicProfile;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform dashboard counters.
 *
 * Every figure here is deliberately platform-wide. The tenant scope is inert
 * for an account with no cabinet, so a query that merely *looked* scoped would
 * return the whole platform by accident rather than by design; each aggregate
 * below therefore drops the scope explicitly and says what it is counting.
 */
class AdminOverviewController extends AdminController
{
    public function __invoke(Request $request): JsonResponse
    {
        $today = now()->toDateString();

        /** @var array<string, int> $byStatus */
        $byStatus = Cabinet::query()
            ->withoutGlobalScopes()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        $cabinets = [
            'total' => array_sum($byStatus),
            'active' => (int) ($byStatus[CabinetStatus::ACTIVE->value] ?? 0),
            'pending' => (int) ($byStatus[CabinetStatus::PENDING->value] ?? 0),
            'suspended' => (int) ($byStatus[CabinetStatus::SUSPENDED->value] ?? 0),
        ];

        // Users are not a tenant-scoped model, but a platform count still has
        // to exclude the accounts that belong to no clinic (mobile patients,
        // superadmins), hence the explicit cabinet_id constraint.
        $appointments = Appointment::withoutCabinetScope()
            ->where('status', '!=', AppointmentStatus::CANCELLED->value);

        return response()->json([
            'data' => [
                'cabinets' => $cabinets,
                'doctors' => User::query()
                    ->whereNotNull('cabinet_id')
                    ->role(RoleName::DOCTOR->value)
                    ->count(),
                'staff' => User::query()
                    ->whereNotNull('cabinet_id')
                    ->role(RoleName::ASSISTANT->value)
                    ->count(),
                'patients' => Patient::withoutCabinetScope()->count(),
                'appointments' => [
                    'today' => (clone $appointments)->whereDate('appointment_date', $today)->count(),
                    'upcoming' => (clone $appointments)->whereDate('appointment_date', '>', $today)->count(),
                ],
                // Discovery requires BOTH flags (DoctorDirectoryController), and
                // the admin API can produce the divergent state on purpose:
                // is_listed travels independently of activation, and suspending
                // an active clinic leaves its listing row untouched. Counting
                // the profile alone would advertise clinics GET /doctors hides.
                'listed_clinics' => CabinetPublicProfile::withoutCabinetScope()
                    ->where('is_listed', true)
                    ->whereIn(
                        'cabinet_id',
                        Cabinet::query()
                            ->withoutGlobalScopes()
                            ->where('status', CabinetStatus::ACTIVE->value)
                            ->select('id'),
                    )
                    ->count(),
                'recent_cabinets' => $this->recentCabinets($request),
            ],
        ]);
    }

    /**
     * The five most recently created clinics, whatever their tenant.
     *
     * @return list<mixed>
     */
    private function recentCabinets(Request $request): array
    {
        /** @var Collection<int, Cabinet> $rows */
        $rows = Cabinet::query()
            ->withoutGlobalScopes()
            ->with('owner:id,name')
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        $this->attachCabinetListAttributes($rows);

        return array_values(
            AdminCabinetListItemResource::collection($rows)->resolve($request),
        );
    }
}
