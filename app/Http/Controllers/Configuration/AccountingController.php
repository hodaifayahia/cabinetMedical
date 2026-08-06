<?php

namespace App\Http\Controllers\Configuration;

use App\Http\Controllers\Controller;
use App\Models\AccountingSetting;
use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AccountingController extends Controller
{
    /**
     * Show the cabinet accounting settings form.
     */
    public function edit(): Response
    {
        $settings = AccountingSetting::current();

        return Inertia::render('configuration/Accounting', [
            'settings' => [
                'currency' => $settings->currency,
                'vat_rate' => $settings->vat_rate,
                'default_consultation_fee' => $settings->default_consultation_fee_minor !== null
                    ? $settings->default_consultation_fee_minor / 100
                    : null,
                'receipt_prefix' => $settings->receipt_prefix,
                'fiscal_year_start' => $settings->fiscal_year_start,
            ],
        ]);
    }

    /**
     * Persist the accounting settings.
     */
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'currency' => ['required', 'string', 'max:10'],
            'vat_rate' => ['required', 'integer', 'min:0', 'max:100'],
            'default_consultation_fee' => ['nullable', 'numeric', 'min:0'],
            'receipt_prefix' => ['nullable', 'string', 'max:20'],
            'fiscal_year_start' => ['required', 'string', 'max:5'],
        ]);

        $fee = $data['default_consultation_fee'] ?? null;

        DB::transaction(function () use ($data, $fee): void {
            $settings = AccountingSetting::current();
            $settings->update([
                'currency' => $data['currency'],
                'vat_rate' => (int) $data['vat_rate'],
                'default_consultation_fee_minor' => ($fee === null || $fee === '')
                    ? null
                    : (int) round(((float) $fee) * 100),
                'receipt_prefix' => $data['receipt_prefix'] ?? '',
                'fiscal_year_start' => $data['fiscal_year_start'],
            ]);
            AuditLog::record('configuration.accounting_updated', $settings, [
                'keys' => array_keys($data),
            ]);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Paramètres comptables enregistrés.']);

        return back();
    }
}
