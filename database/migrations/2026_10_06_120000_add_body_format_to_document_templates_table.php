<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Templates authored in the Word-like editor (or imported from a .docx)
     * are stored as sanitized HTML. Rows created before the editor existed
     * keep their line-based body ("## " headings) and stay readable through
     * the 'text' format, which the editor converts on load.
     */
    public function up(): void
    {
        if (Schema::hasColumn('document_templates', 'body_format')) {
            return;
        }

        Schema::table('document_templates', function (Blueprint $table): void {
            // text | html
            $table->string('body_format', 10)->default('text')->after('body');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('document_templates', 'body_format')) {
            return;
        }

        Schema::table('document_templates', function (Blueprint $table): void {
            $table->dropColumn('body_format');
        });
    }
};
