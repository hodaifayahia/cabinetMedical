<?php

namespace App\Http\Controllers;

use App\Services\ApplicationHealthService;
use App\Services\RemoteUploadBoundary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class HealthController extends Controller
{
    public function __invoke(
        Request $request,
        ApplicationHealthService $health,
        RemoteUploadBoundary $boundary,
    ): JsonResponse {
        $status = $health->status($request);
        $detailsKey = (string) config('medismart.health.details_key');
        $providedKey = (string) $request->header('X-MediSmart-Health-Key');
        $mayViewDetails = $boundary->isDirectLocalRequest($request)
            && $detailsKey !== ''
            && hash_equals($detailsKey, $providedKey);

        if (! $mayViewDetails) {
            $status = [
                'status' => $status['status'],
                'application' => [
                    'name' => $status['application']['name'],
                    'version' => $status['application']['version'],
                ],
                'checked_at' => $status['checked_at'],
            ];
        }

        return response()->json($status, $status['status'] === 'healthy' ? 200 : 503);
    }
}
