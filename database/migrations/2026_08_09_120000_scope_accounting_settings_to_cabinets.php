<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_settings', function (Blueprint $table): void {
            $table->foreignId('cabinet_id')
                ->nullable()
                ->after('id')
                ->constrained('cabinets')
                ->cascadeOnDelete();
            $table->unique('cabinet_id');
        });

        $legacy = DB::table('accounting_settings')->orderBy('id')->first();
        $cabinetIds = DB::table('cabinets')->orderBy('id')->pluck('id');

        if ($legacy === null || $cabinetIds->isEmpty()) {
            return;
        }

        $firstCabinetId = (int) $cabinetIds->shift();
        DB::table('accounting_settings')
            ->where('id', $legacy->id)
            ->update(['cabinet_id' => $firstCabinetId]);

        foreach ($cabinetIds as $cabinetId) {
            DB::table('accounting_settings')->insert([
                'cabinet_id' => $cabinetId,
                'currency' => $legacy->currency,
                'vat_rate' => $legacy->vat_rate,
                'default_consultation_fee_minor' => $legacy->default_consultation_fee_minor,
                'receipt_prefix' => $legacy->receipt_prefix,
                'fiscal_year_start' => $legacy->fiscal_year_start,
                'created_at' => $legacy->created_at,
                'updated_at' => $legacy->updated_at,
            ]);
        }
    }

    public function down(): void
    {
        $firstId = DB::table('accounting_settings')->orderBy('id')->value('id');

        if ($firstId !== null) {
            DB::table('accounting_settings')->where('id', '!=', $firstId)->delete();
        }

        Schema::table('accounting_settings', function (Blueprint $table): void {
            $table->dropUnique(['cabinet_id']);
            $table->dropConstrainedForeignId('cabinet_id');
        });
    }
};
