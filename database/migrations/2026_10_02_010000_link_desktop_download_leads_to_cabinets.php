<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('desktop_download_leads', function (Blueprint $table): void {
            $table->foreignId('cabinet_id')
                ->nullable()
                ->after('specialization')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('desktop_download_leads', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cabinet_id');
        });
    }
};
