<?php

namespace App\Http\Controllers\Staff;

use App\Enums\RoleName;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Cabinet;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PendingMemberController extends Controller
{
    /**
     * List members of the current cabinet awaiting the owner's approval.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', User::class);

        $cabinetId = $request->user()->cabinet_id;

        $pending = User::query()
            ->where('cabinet_id', $cabinetId)
            ->whereNull('approved_at')
            ->orderBy('created_at')
            ->get()
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'created_at' => $user->created_at?->toISOString(),
            ])
            ->all();

        return Inertia::render('staff/Pending', [
            'pending' => $pending,
            'roles' => $this->assignableRoles($request->user()),
            'seats' => [
                'used' => $cabinetId ? User::query()->where('cabinet_id', $cabinetId)->count() : 0,
                'max' => Cabinet::MAX_SEATS,
            ],
        ]);
    }

    /**
     * Approve a pending member by assigning them a role.
     */
    public function approve(Request $request, User $user): RedirectResponse
    {
        $this->authorize('update', $user);

        $data = $request->validate([
            'role' => ['required', Rule::in($this->assignableRoles($request->user()))],
        ]);

        $this->assertSameCabinet($request, $user);

        if ($user->approved_at !== null) {
            throw ValidationException::withMessages([
                'user' => 'Ce membre est déjà approuvé.',
            ]);
        }

        DB::transaction(function () use ($data, $request, $user): void {
            $user->forceFill(['approved_at' => now()])->save();
            $user->syncRoles([$data['role']]);
            AuditLog::record('cabinet.member_approved', $user, [
                'role' => $data['role'],
            ], $request->user()->getKey());
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Membre approuvé.',
        ]);

        return back();
    }

    /**
     * Reject (delete) a pending member request.
     */
    public function reject(Request $request, User $user): RedirectResponse
    {
        $this->authorize('delete', $user);
        $this->assertSameCabinet($request, $user);

        if ($user->approved_at !== null) {
            throw ValidationException::withMessages([
                'user' => 'Ce membre est déjà approuvé et ne peut pas être rejeté ici.',
            ]);
        }

        DB::transaction(function () use ($request, $user): void {
            AuditLog::record('cabinet.member_rejected', $user, [
                'email' => $user->email,
            ], $request->user()->getKey());
            $user->delete();
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Demande rejetée.',
        ]);

        return back();
    }

    private function assertSameCabinet(Request $request, User $user): void
    {
        abort_unless(
            $user->cabinet_id !== null && $user->cabinet_id === $request->user()->cabinet_id,
            403,
        );
    }

    /**
     * @return list<string>
     */
    private function assignableRoles(User $actor): array
    {
        return array_values(collect(RoleName::values())
            ->when(
                ! $actor->hasRole(RoleName::SUPER_ADMINISTRATOR->value),
                fn ($roles) => $roles->reject(
                    fn (string $role): bool => $role === RoleName::SUPER_ADMINISTRATOR->value,
                ),
            )
            ->values()
            ->all());
    }
}
