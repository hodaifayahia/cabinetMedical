<?php

namespace App\Http\Controllers\Staff;

use App\Enums\RoleName;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CabinetSetting;
use App\Models\User;
use App\Services\LicenseService;
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
    public function __invoke(Request $request, LicenseService $licenses): Response
    {
        $this->authorize('viewAny', User::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'role' => ['nullable', 'string', Rule::in(RoleName::values())],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));
        $role = trim((string) ($filters['role'] ?? ''));

        $staff = User::query()
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
            'multiUserCapability' => $this->multiUserCapability($licenses),
        ]);
    }

    public function store(Request $request, LicenseService $licenses): RedirectResponse
    {
        $this->authorize('create', User::class);
        abort_unless(
            $licenses->featureEnabled('multi_user'),
            403,
            'La licence active n’autorise pas l’ajout d’un autre utilisateur.',
        );

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'string', Password::default(), 'confirmed'],
            'role' => ['required', Rule::in($this->assignableRoles($request->user()))],
            'assigned_to_cabinet' => ['required', 'boolean'],
        ]);

        DB::transaction(function () use ($data, $request): void {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'cabinet_setting_id' => $data['assigned_to_cabinet']
                    ? CabinetSetting::current()->getKey()
                    : null,
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();
            $user->syncRoles([$data['role']]);
            AuditLog::record('staff.user_created', $user, [
                'role' => $data['role'],
                'assigned_to_cabinet' => $data['assigned_to_cabinet'],
            ], $request->user()->getKey());
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
            'assigned_to_cabinet' => ['required', 'boolean'],
        ]);

        DB::transaction(function () use ($data, $request, $user): void {
            $target = User::query()
                ->whereKey((int) $user->getKey())
                ->firstOrFail();
            $wasSuperAdministrator = $target->hasRole(RoleName::SUPER_ADMINISTRATOR->value);

            if ($wasSuperAdministrator
                && $data['role'] !== RoleName::SUPER_ADMINISTRATOR->value
                && User::role(RoleName::SUPER_ADMINISTRATOR->value)->count() <= 1) {
                throw ValidationException::withMessages([
                    'role' => 'Le dernier super administrateur ne peut pas être rétrogradé.',
                ]);
            }

            $target->fill([
                'name' => $data['name'],
                'email' => $data['email'],
                'cabinet_setting_id' => $data['assigned_to_cabinet']
                    ? CabinetSetting::current()->getKey()
                    : null,
            ]);
            $passwordChanged = ! empty($data['password']);

            if ($passwordChanged) {
                $target->password = Hash::make($data['password']);
            }

            $target->save();
            $target->syncRoles([$data['role']]);
            AuditLog::record('staff.user_updated', $target, [
                'role' => $data['role'],
                'assigned_to_cabinet' => $data['assigned_to_cabinet'],
                'credentials_changed' => $passwordChanged,
            ], $request->user()->getKey());
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

        DB::transaction(function () use ($request, $user): void {
            $target = User::query()
                ->whereKey((int) $user->getKey())
                ->firstOrFail();

            if ($target->hasRole(RoleName::SUPER_ADMINISTRATOR->value)
                && User::role(RoleName::SUPER_ADMINISTRATOR->value)->count() <= 1) {
                throw ValidationException::withMessages([
                    'user' => 'Le dernier super administrateur ne peut pas être supprimé.',
                ]);
            }

            AuditLog::record('staff.user_deleted', $target, [
                'roles' => $target->getRoleNames()->sort()->values()->all(),
            ], $request->user()->getKey());
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

    /** @return array{available: bool, reason: string|null} */
    private function multiUserCapability(LicenseService $licenses): array
    {
        $available = $licenses->featureEnabled('multi_user');

        return [
            'available' => $available,
            'reason' => $available
                ? null
                : 'La licence active n’autorise pas l’ajout d’utilisateurs. Les comptes existants restent accessibles.',
        ];
    }
}
