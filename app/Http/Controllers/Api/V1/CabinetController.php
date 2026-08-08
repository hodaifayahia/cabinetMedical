<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Cabinet\JoinCabinetAction;
use App\Actions\Fortify\RegisterCabinetAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\JoinCabinetRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CabinetController extends Controller
{
    /**
     * Provision a brand-new cabinet in the pending state with its owner account.
     * Mirrors the web registration validation via RegisterCabinetAction.
     */
    public function register(Request $request, RegisterCabinetAction $action): JsonResponse
    {
        $owner = $action->execute($request->all());

        return response()->json([
            'cabinet_id' => $owner->cabinet_id,
            'status' => 'pending',
        ], 201);
    }

    /**
     * Request membership of an existing active cabinet. The account is created
     * pending the owner's approval (no token issued until approved + active).
     */
    public function join(JoinCabinetRequest $request, JoinCabinetAction $action): JsonResponse
    {
        /** @var User $member */
        $member = $action->execute($request->validated());

        return response()->json([
            'message' => 'Votre demande a été envoyée. Vous pourrez vous connecter une fois approuvé par le propriétaire du cabinet.',
            'cabinet_id' => $member->cabinet_id,
            'status' => 'awaiting_approval',
        ], 201);
    }
}
