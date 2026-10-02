<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->uuid('public_id')->nullable()->unique()->after('id');
        });

        DB::table('users')
            ->select('id')
            ->orderBy('id')
            ->chunkById(200, function ($users): void {
                foreach ($users as $user) {
                    DB::table('users')
                        ->where('id', $user->id)
                        ->update(['public_id' => (string) Str::uuid7()]);
                }
            });

        Schema::table('patients', function (Blueprint $table): void {
            // This portable identity groups dossiers booked by the same mobile
            // account across hosted and desktop installations. It is not a FK:
            // the receiving cabinet intentionally has no copy of the account.
            $table->uuid('family_group_public_id')->nullable();
            $table->string('family_relation', 20)->nullable();
            $table->string('family_contact_name', 200)->nullable();
            $table->index(['cabinet_id', 'family_group_public_id']);
        });

        // Preserve family identity for mobile dossiers that already exist on
        // the hosted installation before the patient-side relationship fields
        // were introduced.
        DB::table('patients')
            ->select('id', 'patient_user_id', 'family_member_id')
            ->where(function ($query): void {
                $query->whereNotNull('patient_user_id')
                    ->orWhereNotNull('family_member_id');
            })
            ->orderBy('id')
            ->chunkById(200, function ($patients): void {
                foreach ($patients as $patient) {
                    $ownerId = $patient->patient_user_id;
                    $relation = null;

                    if ($patient->family_member_id !== null) {
                        $member = DB::table('family_members')
                            ->select('owner_user_id', 'relation')
                            ->where('id', $patient->family_member_id)
                            ->first();

                        if ($member !== null) {
                            $ownerId = $member->owner_user_id;
                            $relation = $member->relation;
                        }
                    }

                    if ($ownerId === null) {
                        continue;
                    }

                    $owner = DB::table('users')
                        ->select('id', 'public_id', 'name')
                        ->where('id', $ownerId)
                        ->first();

                    if ($owner === null || $owner->public_id === null) {
                        continue;
                    }

                    $profile = DB::table('patient_profiles')
                        ->select('first_name', 'last_name')
                        ->where('user_id', $ownerId)
                        ->first();
                    $contactName = trim(sprintf(
                        '%s %s',
                        $profile->first_name ?? '',
                        $profile->last_name ?? '',
                    ));

                    DB::table('patients')
                        ->where('id', $patient->id)
                        ->update([
                            'family_group_public_id' => $owner->public_id,
                            'family_relation' => $relation,
                            'family_contact_name' => $contactName !== '' ? $contactName : $owner->name,
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->dropIndex(['cabinet_id', 'family_group_public_id']);
            $table->dropColumn([
                'family_group_public_id',
                'family_relation',
                'family_contact_name',
            ]);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['public_id']);
            $table->dropColumn('public_id');
        });
    }
};
