<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the AI assistant may receive images (ECG, scanned documents) and
 * dictation audio. Off, only de-identified text leaves the PC.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('cabinets', 'ai_media_enabled')) {
            return;
        }

        Schema::table('cabinets', function (Blueprint $table): void {
            $table->boolean('ai_media_enabled')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('cabinets', function (Blueprint $table): void {
            $table->dropColumn('ai_media_enabled');
        });
    }
};
