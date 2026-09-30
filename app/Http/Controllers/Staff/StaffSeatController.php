<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Cabinet;
use App\Models\User;
use App\Services\Cabinet\CabinetSeatService;
use App\Services\Sync\SyncTransportException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Vérifier mes sièges en ligne" on the staff screen of a local desktop: asks
 * the online service for the seats the platform currently grants and answers
 * with the refreshed summary, so the screen updates without a reload.
 */
class StaffSeatController extends Controller
{
    public function __invoke(Request $request, CabinetSeatService $seats): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $cabinet = $request->user()->cabinet;

        abort_unless($cabinet instanceof Cabinet, 403);

        $previous = $cabinet->seatLimit();

        try {
            $seats->refresh($cabinet);
        } catch (SyncTransportException $exception) {
            return response()->json([
                'message' => $exception->offline
                    ? 'Connexion Internet indisponible : les sièges n’ont pas pu être vérifiés. Réessayez une fois connecté.'
                    : $exception->getMessage(),
                'offline' => $exception->offline,
                'seats' => $seats->summary($cabinet),
            ], 503);
        }

        $limit = $cabinet->seatLimit();

        return response()->json([
            'changed' => $limit !== $previous,
            'message' => $limit === $previous
                ? 'Sièges vérifiés : votre cabinet dispose toujours de '.$limit.' sièges.'
                : 'Votre cabinet dispose maintenant de '.$limit.' sièges.',
            'seats' => $seats->summary($cabinet),
        ]);
    }
}
