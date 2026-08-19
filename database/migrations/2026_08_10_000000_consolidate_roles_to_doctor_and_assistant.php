<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
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
    }

    public function down(): void
    {
        // Role consolidation is intentionally irreversible.
    }
};
