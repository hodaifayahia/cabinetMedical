<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Cabinet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The seat allowance a platform administrator granted the token owner's
 * cabinet. A local desktop reads it with its sync token and keeps a copy, so
 * seats bought while it was offline apply as soon as it is back online.
 */
class CabinetSeatController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        // A desktop linked with a platform administrator's token would sync
        // every tenant's stream, which that account can read. Refusing it here
        // is what makes the desktop turn the link down.
        if ($request->user()?->is_platform_admin === true) {
            return response()->json([
                'message' => 'Un compte d’administration de la plateforme ne peut pas relier un poste : utilisez le compte en ligne du cabinet.',
                'reason' => 'platform_admin',
            ], 403);
        }

        $cabinet = $request->user()?->cabinet;

        if (! $cabinet instanceof Cabinet) {
            return response()->json([
                'message' => 'Ce compte n’est rattaché à aucun cabinet.',
                'reason' => 'no_cabinet',
            ], 403);
        }

        return response()->json([
            'data' => [
                'seat_limit' => $cabinet->seatLimit(),
                'seats_in_use' => $cabinet->seatsInUse(),
                // Lets the desktop match the answer to its own cabinet.
                'owner_email' => $cabinet->owner?->email,
            ],
        ]);
    }
}
