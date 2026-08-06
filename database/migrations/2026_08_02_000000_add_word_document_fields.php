<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medical_models', function (Blueprint $table) {
            $table->string('category', 30)->default('general')->after('description');
            $table->string('paper_size', 2)->default('A4')->after('category');
            $table->index('category');
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('medical_model_id')->nullable()->after('consultation_id')
                ->constrained('medical_models')->nullOnDelete();
            $table->string('template_key', 120)->nullable()->after('category');
            $table->string('paper_size', 2)->default('A4')->after('template_key');
            $table->string('file_path')->nullable()->after('content');
            $table->string('original_filename')->nullable()->after('file_path');
            $table->string('mime_type', 150)->nullable()->after('original_filename');
            $table->unsignedBigInteger('file_size')->nullable()->after('mime_type');
            $table->unsignedInteger('file_version')->default(1)->after('file_size');
        });

        Schema::table('prescriptions', function (Blueprint $table) {
            $table->foreignId('document_id')->nullable()->after('consultation_id')
                ->constrained('documents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('prescriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('document_id');
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('medical_model_id');
            $table->dropColumn([
                'template_key',
                'paper_size',
                'file_path',
                'original_filename',
                'mime_type',
                'file_size',
                'file_version',
            ]);
        });

        Schema::table('medical_models', function (Blueprint $table) {
            $table->dropIndex(['category']);
            $table->dropColumn(['category', 'paper_size']);
        });
    }
};
