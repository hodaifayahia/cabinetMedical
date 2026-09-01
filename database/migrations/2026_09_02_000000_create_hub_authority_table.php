<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records which Cabinet Hub currently holds clinical write authority for a
 * cabinet, and at which authority epoch (ADR-002 invariant 1, ADR-003).
 *
 * The epoch deliberately lives in the database rather than in configuration.
 * A replacement Hub is brought up by restoring a verified backup, so the
 * authority record travels with the data: the new box can see that the
 * cabinet's authority belongs to a different Hub id and refuse to serve until
 * an operator adopts it. Holding the epoch in .env instead would mean a human
 * pasting a number and running config:cache, where a typo in one direction
 * silently fences the clinic and a typo in the other silently un-fences a
 * machine that should have stayed dead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hub_authority', function (Blueprint $table): void {
            $table->id();

            // One authority record per cabinet: a cabinet has exactly one
            // write authority at a time, which is the invariant this table
            // exists to make representable.
            $table->foreignId('cabinet_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('hub_id', 190);
            $table->unsignedInteger('authority_epoch')->default(1);

            $table->timestamp('adopted_at');
            $table->string('adopted_reason', 40);
            $table->foreignId('adopted_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // The Hub this record displaced, kept so an operator can see the
            // history of a replacement without reading the audit log.
            $table->string('previous_hub_id', 190)->nullable();

            $table->timestamps();

            $table->index(['hub_id', 'authority_epoch']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hub_authority');
    }
};
