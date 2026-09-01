<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Enums\AppointmentStatus;
use App\Enums\FamilyMemberStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Mobile\LinkFamilyMemberRequest;
use App\Http\Requests\Api\Mobile\RespondFamilyLinkRequest;
use App\Http\Requests\Api\Mobile\StoreFamilyMemberRequest;
use App\Http\Resources\Mobile\FamilyMemberResource;
use App\Models\Appointment;
use App\Models\FamilyMember;
use App\Models\User;
use App\Notifications\Mobile\FamilyLinkRequested;
use App\Notifications\Mobile\FamilyLinkResponded;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * The patient's family circle: inline dependent profiles plus consented links
 * to other patient accounts. Patient users have cabinet_id = null, so every
 * query here carries an explicit owner_user_id / linked_user_id constraint —
 * the BelongsToCabinet scope is inert for them and is never relied upon.
 */
class FamilyMemberController extends Controller
{
    /**
     * Paginated list of the members the authenticated patient owns.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min(max($request->integer('per_page', 15), 1), 50);

        $userId = $request->user()->getKey();

        // The circle I own, plus link requests addressed to me and still
        // awaiting my answer. Without the second branch the invited account
        // can never discover the request it alone is allowed to respond to.
        $members = FamilyMember::query()
            ->where(function ($query) use ($userId): void {
                $query->where('owner_user_id', $userId)
                    ->orWhere(function ($incoming) use ($userId): void {
                        $incoming->where('linked_user_id', $userId)
                            ->where('status', FamilyMemberStatus::PENDING);
                    });
            })
            ->with(['linkedUser.patientProfile', 'owner.patientProfile'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return FamilyMemberResource::collection($members);
    }

    /**
     * Create a dependent profile (no account of its own, active immediately).
     */
    public function store(StoreFamilyMemberRequest $request): JsonResponse
    {
        $member = FamilyMember::query()->create([
            ...$request->validated(),
            'owner_user_id' => $request->user()->getKey(),
            'status' => FamilyMemberStatus::ACTIVE,
            'linked_user_id' => null,
        ]);

        return (new FamilyMemberResource($member))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Request a link to another patient account by phone number. The link
     * stays pending — and unusable for booking — until the target approves.
     */
    public function link(LinkFamilyMemberRequest $request): JsonResponse
    {
        /** @var User $owner */
        $owner = $request->user();

        /** @var User $target */
        $target = User::query()
            ->where('phone', (string) $request->string('phone'))
            ->firstOrFail();

        if ($target->is($owner)) {
            throw ValidationException::withMessages([
                'phone' => 'Vous ne pouvez pas vous ajouter vous-même comme membre de votre famille.',
            ]);
        }

        if (! $target->isMobilePatient()) {
            throw ValidationException::withMessages([
                'phone' => 'Aucun compte patient ne correspond à ce numéro de téléphone.',
            ]);
        }

        $alreadyLinked = FamilyMember::query()
            ->where('owner_user_id', $owner->getKey())
            ->where('linked_user_id', $target->getKey())
            ->exists();

        if ($alreadyLinked) {
            return response()->json([
                'message' => 'Ce compte fait déjà partie de votre famille.',
                'reason' => 'already_linked',
            ], 422);
        }

        $member = FamilyMember::query()->create([
            'owner_user_id' => $owner->getKey(),
            'linked_user_id' => $target->getKey(),
            'relation' => $request->validated('relation'),
            'status' => FamilyMemberStatus::PENDING,
        ]);

        $target->notify(new FamilyLinkRequested($member, $owner));

        return (new FamilyMemberResource($member))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Approve or decline a pending link. Only the account being linked may
     * answer — never the owner who sent the request.
     */
    public function respond(RespondFamilyLinkRequest $request, FamilyMember $familyMember): FamilyMemberResource|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($familyMember->linked_user_id !== $user->getKey()) {
            return response()->json([
                'message' => 'Seul le compte invité peut répondre à cette demande de lien familial.',
                'reason' => 'not_owner',
            ], 403);
        }

        if ($familyMember->status !== FamilyMemberStatus::PENDING) {
            return response()->json([
                'message' => 'Cette demande de lien familial a déjà reçu une réponse.',
                'reason' => 'link_not_pending',
            ], 409);
        }

        $familyMember->status = $request->validated('action') === 'approve'
            ? FamilyMemberStatus::APPROVED
            : FamilyMemberStatus::DECLINED;
        $familyMember->save();

        $familyMember->owner?->notify(new FamilyLinkResponded($familyMember, $user));

        return new FamilyMemberResource($familyMember->load('linkedUser.patientProfile'));
    }

    /**
     * Remove a member from the circle. A dependent with upcoming blocking
     * appointments must be cancelled first; a link row is always removable
     * because deleting it only severs the connection between two accounts.
     */
    public function destroy(Request $request, FamilyMember $familyMember): JsonResponse
    {
        if ($familyMember->owner_user_id !== $request->user()->getKey()) {
            return response()->json([
                'message' => 'Ce membre de famille ne vous appartient pas.',
                'reason' => 'not_owner',
            ], 403);
        }

        if ($familyMember->isDependent()) {
            $hasUpcomingAppointments = Appointment::withoutCabinetScope()
                ->where('family_member_id', $familyMember->getKey())
                ->whereIn('status', AppointmentStatus::blockingValues())
                ->where('starts_at', '>=', CarbonImmutable::now())
                ->exists();

            if ($hasUpcomingAppointments) {
                return response()->json([
                    'message' => 'Ce membre a des rendez-vous à venir. Annulez-les avant de le supprimer.',
                    'reason' => 'member_has_appointments',
                ], 422);
            }
        }

        $familyMember->delete();

        return response()->json(['message' => 'Membre de la famille supprimé.']);
    }
}
