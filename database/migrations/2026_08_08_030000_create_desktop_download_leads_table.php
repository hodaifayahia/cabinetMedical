<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('desktop_download_leads', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->string('email', 190)->index();
            $table->string('phone', 32);
            $table->string('cabinet_name', 160);
            $table->string('specialization', 160);
            $table->timestamp('downloaded_at')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('desktop_download_leads');
    }
};
