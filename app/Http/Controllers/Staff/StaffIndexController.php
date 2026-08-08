<?php

namespace App\Http\Controllers\Staff;

use App\Enums\RoleName;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Cabinet;
use App\Models\CabinetSetting;
use App\Models\User;
use App\Services\Cabinet\CabinetEntitlementService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class StaffIndexController extends Controller
{
    /**
     * Display a paginated list of staff members.
     */
    public function __invoke(Request $request, CabinetEntitlementService $entitlements): Response
    {
        $this->authorize('viewAny', User::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'role' => ['nullable', 'string', Rule::in(RoleName::values())],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));
        $role = trim((string) ($filters['role'] ?? ''));

        $staff = $this->staffQuery($request->user())
            ->with(['roles', 'cabinet:id,name'])
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($nested) use ($search): void {
                    $nested
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($role !== '', fn ($query) => $query->role($role))
            ->orderBy('name')
            ->paginate(10)
            ->withQueryString()
            ->through(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at?->toISOString(),
                'roles' => $user->getRoleNames()->sort()->values()->all(),
                'cabinet' => $user->cabinet?->only(['id', 'name']),
                'created_at' => $user->created_at?->toISOString(),
            ]);

        $cabinet = CabinetSetting::current();

        return Inertia::render('staff/Index', [
            'staff' => $staff,
            'filters' => ['search' => $search, 'role' => $role],
            'roles' => $this->assignableRoles($request->user()),
            'cabinet' => $cabinet->only(['id', 'name']),
            'currentUserId' => $request->user()->getKey(),
            'multiUserCapability' => $this->multiUserCapability($entitlements, $request->user()),
        ]);
    }

    public function store(Request $request, CabinetEntitlementService $entitlements): RedirectResponse
    {
        $this->authorize('create', User::class);
        abort_unless(
            $entitlements->featureEnabled($request->user(), 'multi_user'),
            403,
            'La licence active n’autorise pas l’ajout d’un autre utilisateur.',
        );

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'string', Password::default(), 'confirmed'],
            'role' => ['required', Rule::in($this->assignableRoles($request->user()))],
            // Retained for compatibility with the existing form. Staff
            // managed here must always remain attached to a cabinet.
            'assigned_to_cabinet' => ['required', 'accepted'],
        ]);

        $actor = $request->user();
        $cabinetId = $actor->cabinet_id;

        // Platform staff provision platform accounts through Filament. This
        // endpoint has no cabinet selector, so it must never guess a tenant or
        // create an unscoped account when the actor has no cabinet.
        abort_if($cabinetId === null, 403);

        DB::transaction(function () use ($actor, $cabinetId, $data): void {
            $cabinet = Cabinet::query()
                ->whereKey($cabinetId)
                ->lockForUpdate()
                ->firstOrFail();

            // Serialise every seat allocation on the tenant row. Pending and
            // approved members both count toward the fixed cabinet limit.
            if ($cabinet->users()->count() >= Cabinet::MAX_SEATS) {
                throw ValidationException::withMessages([
                    'email' => 'Ce cabinet a atteint sa limite de '.Cabinet::MAX_SEATS.' utilisateurs.',
                ]);
            }

            $settings = CabinetSetting::current($cabinet);
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'cabinet_id' => $cabinet->getKey(),
                'cabinet_setting_id' => $settings->getKey(),
            ]);
            $user->forceFill([
                'email_verified_at' => now(),
                // Staff created directly by an owner are approved immediately.
                'approved_at' => now(),
            ])->save();
            $user->syncRoles([$data['role']]);
            AuditLog::record('staff.user_created', $user, [
                'role' => $data['role'],
                'assigned_to_cabinet' => true,
            ], $actor->getKey());
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Utilisateur ajouté.',
        ]);

        return back();
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->authorize('update', $user);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => [
                'required',
                'email',
                'max:190',
                Rule::unique('users', 'email')->ignore($user),
            ],
            'password' => ['nullable', 'string', Password::default(), 'confirmed'],
            'role' => ['required', Rule::in($this->assignableRoles($request->user()))],
            'assigned_to_cabinet' => ['required', 'accepted'],
        ]);

        $actor = $request->user();

        DB::transaction(function () use ($actor, $data, $user): void {
            $target = $this->staffQuery($actor)
                ->whereKey((int) $user->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $wasSuperAdministrator = $target->hasRole(RoleName::SUPER_ADMINISTRATOR->value);

            if ($wasSuperAdministrator
                && $data['role'] !== RoleName::SUPER_ADMINISTRATOR->value
                && $this->superAdministratorCount($actor, $target) <= 1) {
                throw ValidationException::withMessages([
                    'role' => 'Le dernier super administrateur ne peut pas être rétrogradé.',
                ]);
            }

            $settingsId = $target->cabinet_id === null
                ? $target->cabinet_setting_id
                : CabinetSetting::current($target->cabinet)->getKey();

            $target->fill([
                'name' => $data['name'],
                'email' => $data['email'],
                // The legacy assignment field cannot detach or transfer a
                // member. The target's cabinet_id is deliberately untouched.
                'cabinet_setting_id' => $settingsId,
            ]);
            $passwordChanged = ! empty($data['password']);

            if ($passwordChanged) {
                $target->password = Hash::make($data['password']);
            }

            $target->save();
            $target->syncRoles([$data['role']]);
            AuditLog::record('staff.user_updated', $target, [
                'role' => $data['role'],
                'assigned_to_cabinet' => $target->cabinet_id !== null,
                'credentials_changed' => $passwordChanged,
            ], $actor->getKey());
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Utilisateur mis à jour.',
        ]);

        return back();
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->authorize('delete', $user);

        $actor = $request->user();

        DB::transaction(function () use ($actor, $user): void {
            $target = $this->staffQuery($actor)
                ->whereKey((int) $user->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($target->hasRole(RoleName::SUPER_ADMINISTRATOR->value)
                && $this->superAdministratorCount($actor, $target) <= 1) {
                throw ValidationException::withMessages([
                    'user' => 'Le dernier super administrateur ne peut pas être supprimé.',
                ]);
            }

            AuditLog::record('staff.user_deleted', $target, [
                'roles' => $target->getRoleNames()->sort()->values()->all(),
            ], $actor->getKey());
            $target->delete();
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Utilisateur supprimé.',
        ]);

        return back();
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

    /**
     * Scope staff operations to the actor's tenant. Platform administrators
     * retain their deliberate cross-cabinet back-office bypass.
     *
     * @return Builder<User>
     */
    private function staffQuery(User $actor): Builder
    {
        return User::query()->when(
            ! $actor->is_platform_admin,
            fn (Builder $query): Builder => $query->where('cabinet_id', $actor->cabinet_id),
        );
    }

    private function superAdministratorCount(User $actor, User $target): int
    {
        return User::role(RoleName::SUPER_ADMINISTRATOR->value)
            ->when(
                ! $actor->is_platform_admin,
                fn (Builder $query): Builder => $query->where('cabinet_id', $target->cabinet_id),
            )
            ->count();
    }

    /** @return array{available: bool, reason: string|null} */
    private function multiUserCapability(CabinetEntitlementService $entitlements, User $actor): array
    {
        $available = $entitlements->featureEnabled($actor, 'multi_user');

        return [
            'available' => $available,
            'reason' => $available
                ? null
                : 'La licence active n’autorise pas l’ajout d’utilisateurs. Les comptes existants restent accessibles.',
        ];
    }
}
