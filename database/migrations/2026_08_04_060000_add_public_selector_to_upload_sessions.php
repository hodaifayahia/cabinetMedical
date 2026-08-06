<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('upload_sessions', function (Blueprint $table): void {
            $table->string('public_selector', 32)
                ->nullable()
                ->after('id')
                ->unique();
        });
    }

    public function down(): void
    {
        Schema::table('upload_sessions', function (Blueprint $table): void {
            $table->dropUnique(['public_selector']);
            $table->dropColumn('public_selector');
        });
    }
};
