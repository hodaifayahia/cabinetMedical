<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reusable ordonnance sets (« Angine adulte », « HTA – initiation »…),
 * shared by the cabinet and loaded in one click.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prescription_protocols', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cabinet_id')->nullable()->constrained('cabinets')->nullOnDelete();
            $table->uuid('public_id')->unique();
            $table->string('name', 120);
            $table->json('items');
            $table->text('notes')->nullable();
            $table->unsignedInteger('uses')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['cabinet_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prescription_protocols');
    }
};
