<?php

namespace App\Http\Middleware;

use App\Enums\PermissionName;
use App\Models\CabinetSetting;
use App\Models\User;
use App\Services\DesktopDownloadService;
use App\Services\SessionLockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        if ($request->routeIs('session-lock.show')) {
            return parent::share($request);
        }

        $cabinet = CabinetSetting::current();
        $sessionLock = $request->user() instanceof User
            ? app(SessionLockService::class)
            : null;

        return [
            ...parent::share($request),
            'name' => $cabinet->name,
            'cabinet' => [
                'name' => $cabinet->name,
                'phone' => $cabinet->phone,
                'email' => $cabinet->email,
                'address' => $cabinet->address,
                'city' => $cabinet->city,
                'logo_path' => $cabinet->logo_path,
                'logo_url' => $cabinet->logo_path
                    ? Storage::disk('public')->url($cabinet->logo_path)
                    : null,
                'timezone' => $cabinet->timezone,
                'currency' => [
                    'code' => $cabinet->currency_code,
                    'symbol' => (string) config('clinic.currency.symbol', $cabinet->currency_code),
                    'minor_unit' => (int) config('clinic.currency.minor_unit', 2),
                ],
            ],
            'auth' => [
                'user' => $this->resolveAuthenticatedUser($request->user()),
            ],
            'desktopDownload' => app(DesktopDownloadService::class)->sharedProps(),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'sessionLock' => $sessionLock === null ? null : [
                'idleTimeoutSeconds' => $sessionLock->idleTimeoutSeconds(),
                'remainingSeconds' => $sessionLock->remainingSeconds($request),
                'instanceId' => $sessionLock->currentInstanceId($request),
            ],
        ];
    }

    /**
     * @return array{
     *     id: int,
     *     name: string,
     *     email: string,
     *     email_verified_at: string|null,
     *     two_factor_enabled: bool,
     *     created_at: string|null,
     *     updated_at: string|null,
     *     roles: list<string>,
     *     permissions: list<string>,
     *     can: array{accessAdminPanel: bool, manageStaff: bool}
     * }|null
     */
    protected function resolveAuthenticatedUser(mixed $user): ?array
    {
        if (! $user instanceof User) {
            return null;
        }

        $user->loadMissing('roles', 'permissions');

        /** @var list<string> $roles */
        $roles = $user->getRoleNames()->sort()->map(
            static fn (mixed $role): string => (string) $role,
        )->values()->all();

        /** @var list<string> $permissions */
        $permissions = $user->getAllPermissions()->pluck('name')->sort()->map(
            static fn (mixed $permission): string => (string) $permission,
        )->values()->all();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified_at' => $user->email_verified_at?->toISOString(),
            'two_factor_enabled' => $user->two_factor_secret !== null
                && $user->two_factor_confirmed_at !== null,
            'created_at' => $user->created_at?->toISOString(),
            'updated_at' => $user->updated_at?->toISOString(),
            'roles' => $roles,
            'permissions' => $permissions,
            'can' => [
                'accessAdminPanel' => $user->canAccessAdminPanel(),
                'manageStaff' => $user->can(PermissionName::STAFF_MANAGE->value),
            ],
        ];
    }
}
