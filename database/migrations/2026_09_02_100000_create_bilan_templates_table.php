<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reusable exam selections a practitioner saves from the bilan editor.
 *
 * The exams themselves stay in `exams`; a template only remembers which ones
 * it groups, so renaming an exam updates every template that uses it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bilan_templates', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cabinet_id')
                ->nullable()
                ->constrained('cabinets')
                ->nullOnDelete();
            $table->string('name', 120);
            // Ordered list of exam ids; order is the order they print in.
            $table->json('exam_ids');
            $table->timestamps();

            // One name per cabinet keeps the picker unambiguous. NULL cabinets
            // (console/maintenance rows) are exempt, as SQL treats them as
            // distinct — the controller re-checks within the tenant scope.
            $table->unique(['cabinet_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bilan_templates');
    }
};
