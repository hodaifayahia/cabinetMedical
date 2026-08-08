<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'cabinet_id')) {
                $table->foreignId('cabinet_id')
                    ->nullable()
                    ->after('cabinet_setting_id')
                    ->constrained('cabinets')
                    ->nullOnDelete();
                $table->index('cabinet_id');
            }

            if (! Schema::hasColumn('users', 'is_platform_admin')) {
                $table->boolean('is_platform_admin')->default(false)->after('cabinet_id');
            }

            if (! Schema::hasColumn('users', 'approved_at')) {
                // Null means the member is awaiting owner approval. Owners and
                // legacy accounts are approved at creation / backfill time.
                $table->timestamp('approved_at')->nullable()->after('is_platform_admin');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'approved_at')) {
                $table->dropColumn('approved_at');
            }

            if (Schema::hasColumn('users', 'is_platform_admin')) {
                $table->dropColumn('is_platform_admin');
            }

            if (Schema::hasColumn('users', 'cabinet_id')) {
                $table->dropConstrainedForeignId('cabinet_id');
            }
        });
    }
};
