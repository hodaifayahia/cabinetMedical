<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('documents') && Schema::hasColumn('documents', 'medical_model_id')) {
            Schema::table('documents', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('medical_model_id');
            });
        }

        Schema::dropIfExists('model_placeholder_attributes');
        Schema::dropIfExists('model_placeholder_sections');
        Schema::dropIfExists('medical_models');
    }

    public function down(): void
    {
        // The custom model feature is intentionally retired and is not restored.
    }
};
