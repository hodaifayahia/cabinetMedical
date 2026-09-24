<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AiFeature;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Ai\AiCompletion;
use App\Services\Ai\AiCreditLedger;
use App\Services\Ai\AiException;
use App\Services\Ai\AiGateway;
use App\Services\Ai\AiProviderClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The hosted side of AI for local desktop installations. The desktop builds
 * the prompt from its own records and sends it here with its sync token; this
 * service charges the token owner's cabinet and calls the provider. It never
 * relays onward, so a request cannot loop between servers.
 */
class AiRelayController extends Controller
{
    public function status(Request $request, AiGateway $gateway, AiProviderClient $provider, AiCreditLedger $ledger): JsonResponse
    {
        try {
            $cabinet = $gateway->cabinetOf($this->user($request));
        } catch (AiException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'reason' => $exception->reason], 403);
        }

        return response()->json([
            'available' => $provider->isConfigured(),
            'enabled' => (bool) $cabinet->ai_enabled,
            'balance' => $ledger->balance($cabinet),
            'costs' => AiFeature::costs(),
            'support' => $gateway->support(),
        ]);
    }

    public function complete(Request $request, AiGateway $gateway, AiProviderClient $provider, AiCreditLedger $ledger): JsonResponse
    {
        $data = $request->validate([
            'feature' => ['required', Rule::enum(AiFeature::class)],
            'vision' => ['sometimes', 'boolean'],
            'json' => ['sometimes', 'boolean'],
            // Chats replay their history, hence room for assistant turns.
            'messages' => ['required', 'array', 'min:1', 'max:40'],
            'messages.*.role' => ['required', Rule::in(['system', 'user', 'assistant'])],
            'messages.*.content' => ['required'],
        ]);

        $user = $this->user($request);
        $feature = AiFeature::from($data['feature']);
        $vision = (bool) ($data['vision'] ?? false);

        try {
            $completion = $ledger->spend(
                $gateway->cabinetOf($user),
                $user,
                $feature,
                fn (): AiCompletion => $provider->complete(
                    array_values($data['messages']),
                    $vision,
                    (bool) ($data['json'] ?? true),
                    $feature->model($vision),
                ),
            );
        } catch (AiException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'reason' => $exception->reason,
                'balance' => $exception->balance,
            ], $exception->httpStatus());
        }

        return response()->json([
            'content' => $completion->content,
            'model' => $completion->model,
            'balance' => $completion->balance,
        ]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
