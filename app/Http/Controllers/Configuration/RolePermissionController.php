<?php

namespace App\Http\Controllers\Configuration;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Cabinet;
use App\Models\CabinetRolePermissionSet;
use App\Models\User;
use App\Services\Authorization\CabinetRolePermissionAuthorizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class RolePermissionController extends Controller
{
    public function index(Request $request, CabinetRolePermissionAuthorizer $authorizer): Response
    {
        $actor = $this->authorizedActor($request, $authorizer);
        $cabinetId = (int) $actor->cabinet_id;

        /** @var Collection<string, Role> $rolesByName */
        $rolesByName = Role::query()
            ->where('guard_name', 'web')
            ->whereIn('name', RoleName::values())
            ->with('permissions')
            ->get()
            ->keyBy('name');

        /** @var Collection<string, CabinetRolePermissionSet> $setsByRole */
        $setsByRole = CabinetRolePermissionSet::withoutCabinetScope()
            ->where('cabinet_id', $cabinetId)
            ->get()
            ->keyBy('role_name');

        $permissions = collect(PermissionName::cases());
        $rolePayloads = array_map(
            function (RoleName $roleName) use ($rolesByName, $setsByRole): array {
                $set = $setsByRole->get($roleName->value);
                $permissions = $set?->permissionNames()
                    ?? $rolesByName->get($roleName->value)?->permissions
                        ->pluck('name')
                        ->map(static fn (mixed $name): string => (string) $name)
                        ->values()
                        ->all()
                    ?? [];

                return [
                    'name' => $roleName->value,
                    'label' => $roleName->label(),
                    'permissions' => $permissions,
                    'permission_count' => count($permissions),
                    'locked' => $roleName === RoleName::SUPER_ADMINISTRATOR,
                    'customized' => $set !== null,
                ];
            },
            RoleName::cases(),
        );

        return Inertia::render('configuration/RolesPermissions', [
            'permissionGroups' => $permissions
                ->groupBy(static fn (PermissionName $permission): string => $permission->group())
                ->map(static fn (Collection $group, string $key): array => [
                    'key' => $key,
                    'label' => PermissionName::groupLabel($key),
                    'permissions' => $group
                        ->map(static fn (PermissionName $permission): array => [
                            'name' => $permission->value,
                            'label' => $permission->label(),
                        ])
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all(),
            'roles' => $rolePayloads,
            'users' => User::query()
                ->where('cabinet_id', $cabinetId)
                ->where('is_platform_admin', false)
                ->whereNotNull('approved_at')
                ->with('roles')
                ->orderBy('name')
                ->get()
                ->map(function (User $user) use ($actor): array {
                    $isOwner = $user->cabinet?->owner_user_id === $user->getKey();
                    $isProtectedSuperAdministrator = $user->hasRole(RoleName::SUPER_ADMINISTRATOR->value)
                        && ! $actor->hasRole(RoleName::SUPER_ADMINISTRATOR->value);
                    $isProtectedOwner = $isOwner && ! $actor->is($user);

                    return [
                        'id' => $user->getKey(),
                        'name' => $user->name,
                        'email' => $user->email,
                        'role' => $user->getRoleNames()->sort()->first(),
                        'is_owner' => $isOwner,
                        'can_assign' => ! $isProtectedSuperAdministrator && ! $isProtectedOwner,
                    ];
                })
                ->all(),
            'assignableRoles' => array_map(
                static fn (RoleName $role): array => [
                    'name' => $role->value,
                    'label' => $role->label(),
                ],
                $authorizer->assignableRoles($actor),
            ),
        ]);
    }

    public function update(Request $request, CabinetRolePermissionAuthorizer $authorizer): RedirectResponse
    {
        $actor = $this->authorizedActor($request, $authorizer);
        $editableRoleNames = array_values(array_map(
            static fn (RoleName $role): string => $role->value,
            array_filter(
                RoleName::cases(),
                static fn (RoleName $role): bool => $role !== RoleName::SUPER_ADMINISTRATOR,
            ),
        ));

        /** @var array{roles: list<array{name: string, permissions: list<string>}>} $data */
        $data = $request->validate([
            'roles' => ['required', 'array', 'size:'.count($editableRoleNames)],
            'roles.*.name' => [
                'required',
                'string',
                'distinct',
                Rule::in($editableRoleNames),
            ],
            'roles.*.permissions' => ['present', 'array'],
            'roles.*.permissions.*' => [
                'required',
                'string',
                Rule::in(PermissionName::values()),
            ],
        ]);

        $submittedRoleNames = collect($data['roles'])->pluck('name')->sort()->values();
        $expectedRoleNames = collect($editableRoleNames)->sort()->values();
        if ($submittedRoleNames->all() !== $expectedRoleNames->all()) {
            throw ValidationException::withMessages([
                'roles' => 'La matrice doit contenir exactement tous les rôles modifiables.',
            ]);
        }

        $canonicalPermissionOrder = array_flip(PermissionName::values());
        $changedRoles = [];

        DB::transaction(function () use ($actor, $data, $canonicalPermissionOrder, &$changedRoles): void {
            Cabinet::query()
                ->whereKey($actor->cabinet_id)
                ->lockForUpdate()
                ->firstOrFail();

            foreach ($data['roles'] as $rolePayload) {
                $permissions = collect($rolePayload['permissions'])
                    ->unique()
                    ->sortBy(static fn (string $permission): int => $canonicalPermissionOrder[$permission])
                    ->values()
                    ->all();

                $set = CabinetRolePermissionSet::withoutCabinetScope()->updateOrCreate(
                    [
                        'cabinet_id' => $actor->cabinet_id,
                        'role_name' => $rolePayload['name'],
                    ],
                    ['permissions' => $permissions],
                );

                if ($set->wasRecentlyCreated || $set->wasChanged('permissions')) {
                    $changedRoles[] = $rolePayload['name'];
                }
            }

            AuditLog::record('configuration.role_permissions_updated', null, [
                'roles' => $changedRoles,
            ], $actor->getKey());
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Les permissions du cabinet ont été enregistrées.',
        ]);

        return back();
    }

    public function assignRole(
        Request $request,
        int $user,
        CabinetRolePermissionAuthorizer $authorizer,
    ): RedirectResponse {
        $actor = $this->authorizedActor($request, $authorizer);
        $assignableRoleNames = array_map(
            static fn (RoleName $role): string => $role->value,
            $authorizer->assignableRoles($actor),
        );
        /** @var array{role: string} $data */
        $data = $request->validate([
            'role' => ['required', 'string', Rule::in($assignableRoleNames)],
        ]);

        DB::transaction(function () use ($actor, $data, $user): void {
            $target = User::query()
                ->whereKey($user)
                ->where('cabinet_id', $actor->cabinet_id)
                ->where('is_platform_admin', false)
                ->whereNotNull('approved_at')
                ->lockForUpdate()
                ->firstOrFail();
            $cabinet = Cabinet::query()
                ->whereKey($actor->cabinet_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($target->hasRole(RoleName::SUPER_ADMINISTRATOR->value)
                && ! $actor->hasRole(RoleName::SUPER_ADMINISTRATOR->value)) {
                throw ValidationException::withMessages([
                    'role' => 'Seul un super administrateur peut modifier ce compte protégé.',
                ]);
            }

            if ($cabinet->owner_user_id === $target->getKey() && ! $actor->is($target)) {
                throw ValidationException::withMessages([
                    'role' => 'Le rôle du propriétaire ne peut être modifié que par lui-même.',
                ]);
            }

            $previousRoles = $target->getRoleNames()->sort()->values()->all();
            $target->syncRoles([$data['role']]);
            AuditLog::record('configuration.user_role_assigned', $target, [
                'previous_roles' => $previousRoles,
                'role' => $data['role'],
            ], $actor->getKey());
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Le rôle de l’utilisateur a été mis à jour.',
        ]);

        return back();
    }

    private function authorizedActor(
        Request $request,
        CabinetRolePermissionAuthorizer $authorizer,
    ): User {
        $actor = $request->user();

        abort_unless($actor instanceof User && $authorizer->canManage($actor), 403);

        return $actor;
    }
}
