<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cabinet-authored consultation document templates ("modèles"). These sit
     * next to the built-in catalogue (ordonnance, certificats, courriers…) and
     * the auto-generated bilan/exam entries, but are fully editable by the
     * cabinet from Configuration. A template carries its own body (with
     * {{placeholder}} tokens) and a default paper size (A4/A5).
     *
     * `cabinet_id` is nullable to match every other tenant table (see
     * add_cabinet_id_to_tenant_tables); the BelongsToCabinet scope fills it in
     * for authenticated cabinet writes. `public_id` gives the row a stable
     * cross-installation identity, consistent with the rest of the schema.
     */
    public function up(): void
    {
        Schema::create('document_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cabinet_id')
                ->nullable()
                ->constrained('cabinets')
                ->nullOnDelete();
            $table->uuid('public_id')->unique();
            // Catalogue key consumed by ClinicalDocumentManager; generated as
            // "custom-<uuid>" so it never collides with a built-in key.
            $table->string('template_key', 160)->unique();
            // One of the three categories the clinical document creator accepts:
            // ordonnance | bilan | courrier.
            $table->string('category', 30);
            // Free display grouping shown in the document picker (e.g.
            // "Certificats", "Mes courriers").
            $table->string('group', 120)->nullable();
            $table->string('title', 200);
            $table->longText('body');
            // A4 | A5.
            $table->string('paper_size', 5)->default('A4');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();

            $table->index(['cabinet_id', 'category']);
            $table->index(['cabinet_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_templates');
    }
};
