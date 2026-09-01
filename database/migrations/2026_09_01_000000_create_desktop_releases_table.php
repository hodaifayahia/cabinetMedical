<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('desktop_releases', function (Blueprint $table): void {
            $table->id();

            // Semantic version, compared by the desktop updater against the
            // version compiled into the running shell.
            $table->string('version', 50);
            $table->string('channel', 32)->default('stable');

            // Tauri keys its manifest by target triple, so a future macOS or
            // Linux build is another row rather than another table.
            $table->string('platform', 64)->default('windows-x86_64');

            $table->text('notes')->nullable();

            // Path relative to storage/app/private/desktop/releases.
            $table->string('installer_path');
            $table->string('installer_name');
            $table->unsignedBigInteger('installer_size');
            $table->string('installer_sha256', 64);

            // The detached minisign signature produced by the Tauri build.
            // The server never signs anything; it only serves what the build
            // machine produced, and the shell refuses anything that does not
            // verify against its compiled in public key.
            $table->text('signature');

            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            // One row per version per platform per channel.
            $table->unique(['version', 'platform', 'channel']);
            $table->index(['channel', 'platform', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('desktop_releases');
    }
};
