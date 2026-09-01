<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Resources\Mobile\NotificationResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The authenticated mobile user's in-app inbox (database notifications).
 */
class NotificationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $notifications = $user->notifications()
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return NotificationResource::collection($notifications);
    }

    public function markRead(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required_without:all', 'array', 'min:1', 'max:100'],
            'ids.*' => ['string', 'uuid'],
            'all' => ['required_without:ids', 'boolean'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $query = $user->unreadNotifications();

        if (! $request->boolean('all')) {
            $query->whereIn('id', $validated['ids'] ?? []);
        }

        $updated = $query->update(['read_at' => now()]);

        return response()->json([
            'message' => 'Notifications marquées comme lues.',
            'updated' => (int) $updated,
        ]);
    }
}
