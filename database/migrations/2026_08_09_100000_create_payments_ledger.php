<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultations', function (Blueprint $table): void {
            $table->unsignedBigInteger('payment_adjustment_minor')->default(0)->after('payment_amount_minor');
            $table->text('payment_notes')->nullable()->after('payment_service');
            $table->dateTime('payment_settled_at')->nullable()->after('is_paid');
        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cabinet_id')->nullable()->constrained('cabinets')->nullOnDelete();
            $table->uuid('public_id')->unique();
            $table->foreignId('consultation_id')->constrained('consultations')->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained('patients')->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->string('method', 50)->nullable();
            $table->text('notes')->nullable();
            $table->dateTime('received_at');
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('client_reference')->nullable();
            $table->timestamps();

            $table->index(['cabinet_id', 'received_at']);
            $table->index(['consultation_id', 'received_at']);
            $table->unique(['consultation_id', 'client_reference']);
        });

        // Preserve existing paid consultations as immutable collection entries.
        // Unpaid rows remain charges without a collection, which is their debt.
        DB::table('consultations')
            ->where('is_paid', true)
            ->whereNotNull('payment_amount_minor')
            ->where('payment_amount_minor', '>', 0)
            ->orderBy('id')
            ->each(function (object $consultation): void {
                DB::table('payments')->insert([
                    'cabinet_id' => $consultation->cabinet_id ?? null,
                    'public_id' => (string) Str::uuid7(),
                    'consultation_id' => $consultation->id,
                    'patient_id' => $consultation->patient_id,
                    'amount_minor' => $consultation->payment_amount_minor,
                    'method' => $consultation->payment_method,
                    'notes' => 'Reprise du règlement existant',
                    'received_at' => $consultation->consulted_at,
                    'received_by' => $consultation->created_by,
                    'created_at' => $consultation->created_at,
                    'updated_at' => $consultation->updated_at,
                ]);
            });

        DB::table('consultations')
            ->where('is_paid', true)
            ->whereNull('payment_settled_at')
            ->update(['payment_settled_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');

        Schema::table('consultations', function (Blueprint $table): void {
            $table->dropColumn([
                'payment_adjustment_minor',
                'payment_notes',
                'payment_settled_at',
            ]);
        });
    }
};
