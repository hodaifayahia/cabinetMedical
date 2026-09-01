<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Links a cabinet's patient dossier to the mobile account (or family
     * member) it belongs to, and adds the geographic identity fields the
     * mobile registration collects.
     */
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->foreignId('patient_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('family_member_id')->nullable()->constrained('family_members')->nullOnDelete();
            $table->unsignedTinyInteger('wilaya_code')->nullable();
            $table->foreignId('baladiya_id')->nullable()->constrained('baladiyas')->nullOnDelete();
            $table->string('place_of_birth', 150)->nullable();

            $table->index(['cabinet_id', 'patient_user_id']);
            $table->index(['cabinet_id', 'family_member_id']);
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->dropIndex(['cabinet_id', 'patient_user_id']);
            $table->dropIndex(['cabinet_id', 'family_member_id']);
            $table->dropConstrainedForeignId('patient_user_id');
            $table->dropConstrainedForeignId('family_member_id');
            $table->dropConstrainedForeignId('baladiya_id');
            $table->dropColumn(['wilaya_code', 'place_of_birth']);
        });
    }
};
