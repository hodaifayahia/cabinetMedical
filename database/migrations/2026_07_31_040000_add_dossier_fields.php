<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // État civil extras + antécédents + "important à signaler" on the patient.
        Schema::table('patients', function (Blueprint $table) {
            $table->string('marital_status', 30)->nullable()->after('gender');
            $table->string('profession', 100)->nullable()->after('marital_status');
            $table->string('smoking_status', 30)->nullable()->after('profession');
            $table->string('referred_by', 150)->nullable()->after('smoking_status');
            $table->text('allergies')->nullable()->after('blood_group');
            $table->text('antecedents_medical')->nullable()->after('allergies');
            $table->text('antecedents_surgical')->nullable()->after('antecedents_medical');
            $table->text('antecedents_family')->nullable()->after('antecedents_surgical');
            $table->text('antecedents_gyneco')->nullable()->after('antecedents_family');
            $table->text('antecedents_other')->nullable()->after('antecedents_gyneco');
        });

        // État du patient (vitals) + règlement (payment) on the consultation.
        Schema::table('consultations', function (Blueprint $table) {
            $table->decimal('weight_kg', 5, 2)->nullable()->after('notes');
            $table->decimal('height_cm', 5, 2)->nullable()->after('weight_kg');
            $table->decimal('temperature_c', 4, 1)->nullable()->after('height_cm');
            $table->string('blood_pressure', 20)->nullable()->after('temperature_c');
            $table->unsignedBigInteger('payment_amount_minor')->nullable()->after('blood_pressure');
            $table->string('payment_method', 50)->nullable()->after('payment_amount_minor');
            $table->boolean('is_paid')->default(false)->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('consultations', function (Blueprint $table) {
            $table->dropColumn([
                'weight_kg', 'height_cm', 'temperature_c', 'blood_pressure',
                'payment_amount_minor', 'payment_method', 'is_paid',
            ]);
        });

        Schema::table('patients', function (Blueprint $table) {
            $table->dropColumn([
                'marital_status', 'profession', 'smoking_status', 'referred_by', 'allergies',
                'antecedents_medical', 'antecedents_surgical', 'antecedents_family',
                'antecedents_gyneco', 'antecedents_other',
            ]);
        });
    }
};
