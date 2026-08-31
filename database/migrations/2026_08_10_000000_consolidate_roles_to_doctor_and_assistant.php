<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The canonical Assistant permission set, frozen here as literals.
     *
     * 'Assistant' is created fresh by this migration, so without this it would
     * own no permissions at all. Every former Receptionist/Cashier/Stock
     * Manager/Pharmacist resolves through it once their old role is deleted
     * below, and CabinetRolePermissionService falls back to the role's own
     * permissions whenever a cabinet has no set for that role name — so an
     * empty Assistant means every non-doctor user in every cabinet loses
     * every permission.
     *
     * Kept as strings, not PermissionName cases: a migration must keep
     * describing the same change after the enum moves on.
     *
     * @var list<string>
     */
    private const ASSISTANT_PERMISSIONS = [
        'patients.view',
        'patients.create',
        'patients.update',
        'appointments.view',
        'appointments.create',
        'appointments.update',
        'appointments.cancel',
        'appointments.check-in',
        'payments.view',
        'payments.create',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('model_has_roles')) {
            return;
        }

        $now = now();
        foreach (['Doctor', 'Assistant'] as $name) {
            DB::table('roles')->updateOrInsert(
                ['name' => $name, 'guard_name' => 'web'],
                ['created_at' => $now, 'updated_at' => $now],
            );
        }

        $doctorId = DB::table('roles')->where(['name' => 'Doctor', 'guard_name' => 'web'])->value('id');
        $assistantId = DB::table('roles')->where(['name' => 'Assistant', 'guard_name' => 'web'])->value('id');

        $roleMap = [
            'Super Administrator' => $doctorId,
            'Administrator' => $doctorId,
            'Doctor' => $doctorId,
            'Receptionist' => $assistantId,
            'Cashier' => $assistantId,
            'Stock Manager' => $assistantId,
            'Pharmacist' => $assistantId,
        ];

        foreach ($roleMap as $oldName => $newRoleId) {
            $oldRoleId = DB::table('roles')->where(['name' => $oldName, 'guard_name' => 'web'])->value('id');
            if ($oldRoleId === null || $oldRoleId === $newRoleId) {
                continue;
            }

            $assignments = DB::table('model_has_roles')->where('role_id', $oldRoleId)->get();
            foreach ($assignments as $assignment) {
                $alreadyAssigned = DB::table('model_has_roles')
                    ->where('role_id', $newRoleId)
                    ->where('model_type', $assignment->model_type)
                    ->where('model_id', $assignment->model_id)
                    ->exists();

                if ($alreadyAssigned) {
                    DB::table('model_has_roles')->where('role_id', $oldRoleId)
                        ->where('model_type', $assignment->model_type)
                        ->where('model_id', $assignment->model_id)
                        ->delete();
                } else {
                    DB::table('model_has_roles')->where('role_id', $oldRoleId)
                        ->where('model_type', $assignment->model_type)
                        ->where('model_id', $assignment->model_id)
                        ->update(['role_id' => $newRoleId]);
                }
            }

            DB::table('role_has_permissions')->where('role_id', $oldRoleId)->delete();
            DB::table('roles')->where('id', $oldRoleId)->delete();
        }

        $this->grantAssistantPermissions($assistantId);
        $this->consolidateCabinetPermissionSets();
    }

    /**
     * Populate the freshly created Assistant role.
     *
     * Without this the role exists with no permissions, and since the loop
     * above deletes the legacy roles' permission rows, every former
     * Receptionist/Cashier/Stock Manager/Pharmacist would end up able to do
     * nothing at all.
     */
    private function grantAssistantPermissions(?int $assistantId): void
    {
        if ($assistantId === null
            || ! Schema::hasTable('permissions')
            || ! Schema::hasTable('role_has_permissions')) {
            return;
        }

        $rows = DB::table('permissions')
            ->where('guard_name', 'web')
            ->whereIn('name', self::ASSISTANT_PERMISSIONS)
            ->pluck('id')
            ->map(static fn (int $permissionId): array => [
                'role_id' => $assistantId,
                'permission_id' => $permissionId,
            ])
            ->all();

        if ($rows !== []) {
            DB::table('role_has_permissions')->insertOrIgnore($rows);
        }
    }

    /**
     * Re-key each cabinet's per-role permission overrides onto the surviving
     * role names.
     *
     * CabinetRolePermissionService::setsFor() looks these up by the user's
     * CURRENT role name, so a set still keyed 'Receptionist' becomes
     * unreachable the moment that user is remapped to 'Assistant' — silently
     * dropping the cabinet back to the role default.
     *
     * Several legacy roles collapse onto one, so their sets are merged as a
     * UNION. Everyone holding those roles is now the same Assistant, and an
     * intersection would quietly strip access that staff had yesterday. The
     * union can only widen an Assistant's rights within a cabinet that had
     * already granted them to some staff member, and the matrix screen remains
     * available to tighten them afterwards.
     */
    private function consolidateCabinetPermissionSets(): void
    {
        if (! Schema::hasTable('cabinet_role_permission_sets')) {
            return;
        }

        $map = [
            'Super Administrator' => 'Doctor',
            'Administrator' => 'Doctor',
            'Receptionist' => 'Assistant',
            'Cashier' => 'Assistant',
            'Stock Manager' => 'Assistant',
            'Pharmacist' => 'Assistant',
        ];

        $legacyRows = DB::table('cabinet_role_permission_sets')
            ->whereIn('role_name', array_keys($map))
            ->get();

        if ($legacyRows->isEmpty()) {
            return;
        }

        /** @var array<string, array{cabinet_id: int, role_name: string, permissions: list<string>}> $merged */
        $merged = [];

        foreach ($legacyRows as $row) {
            $target = $map[$row->role_name];
            $key = $row->cabinet_id.'|'.$target;

            if (! isset($merged[$key])) {
                $merged[$key] = [
                    'cabinet_id' => (int) $row->cabinet_id,
                    'role_name' => $target,
                    'permissions' => [],
                ];
            }

            $merged[$key]['permissions'] = array_merge(
                $merged[$key]['permissions'],
                $this->decodePermissions($row->permissions),
            );
        }

        $now = now();

        foreach ($merged as $entry) {
            $existing = DB::table('cabinet_role_permission_sets')
                ->where('cabinet_id', $entry['cabinet_id'])
                ->where('role_name', $entry['role_name'])
                ->first();

            $permissions = $entry['permissions'];

            if ($existing !== null) {
                $permissions = array_merge($permissions, $this->decodePermissions($existing->permissions));
            }

            $permissions = array_values(array_unique($permissions));
            sort($permissions);

            if ($existing !== null) {
                DB::table('cabinet_role_permission_sets')
                    ->where('id', $existing->id)
                    ->update(['permissions' => json_encode($permissions), 'updated_at' => $now]);

                continue;
            }

            DB::table('cabinet_role_permission_sets')->insert([
                'cabinet_id' => $entry['cabinet_id'],
                'role_name' => $entry['role_name'],
                'permissions' => json_encode($permissions),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('cabinet_role_permission_sets')
            ->whereIn('role_name', array_keys($map))
            ->delete();
    }

    /**
     * @return list<string>
     */
    private function decodePermissions(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_map(strval(...), $value));
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded)
            ? array_values(array_map(strval(...), $decoded))
            : [];
    }

    public function down(): void
    {
        // Role consolidation is intentionally irreversible.
    }
};
