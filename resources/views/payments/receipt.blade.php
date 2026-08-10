<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reçu de paiement n° {{ $payment['id'] }}</title>
    <style nonce="{{ Vite::cspNonce() }}">
        * { box-sizing: border-box; }
        body { margin: 0; background: #eef7f5; color: #073d42; font-family: Arial, sans-serif; }
        .receipt { width: min(720px, calc(100% - 32px)); margin: 32px auto; background: white; padding: 38px; box-shadow: 0 16px 50px rgba(15, 35, 55, .12); }
        .document-branding-header { display: flex; justify-content: space-between; gap: 24px; border-bottom: 2px solid #00666f; padding-bottom: 18px; }
        .document-branding-identity { display: flex; min-width: 0; align-items: flex-start; gap: 14px; }
        .document-branding-logo { width: 78px; max-height: 62px; object-fit: contain; }
        .document-branding-copy { min-width: 0; }
        .document-branding-copy h1 { margin: 0; font-size: 24px; }
        .document-branding-doctor { margin: 5px 0 0; font-size: 13px; font-weight: 700; }
        .document-branding-meta { margin: 3px 0 0; color: #667085; font-size: 11px; }
        .document-branding-document { flex: 0 0 auto; text-align: right; }
        .document-branding-footer { margin-top: 24px; border-top: 1px dashed #9ca9b5; padding-top: 10px; text-align: center; color: #667085; font-size: 11px; }
        .document-branding-footer p { margin: 3px 0; }
        .muted { color: #667085; font-size: 13px; }
        .paid { display: inline-block; margin: 24px 0; border-radius: 999px; padding: 7px 12px; background: {{ $payment['is_paid'] ? '#dcfce7' : '#fef3c7' }}; color: {{ $payment['is_paid'] ? '#166534' : '#92400e' }}; font-size: 12px; font-weight: 800; text-transform: uppercase; }
        dl { display: grid; grid-template-columns: 1fr 1fr; gap: 0; border: 1px solid #d5dde5; border-radius: 12px; overflow: hidden; }
        div.row { padding: 14px; border-bottom: 1px solid #d5dde5; }
        div.row:nth-last-child(-n+2) { border-bottom: 0; }
        dt { color: #667085; font-size: 11px; text-transform: uppercase; }
        dd { margin: 5px 0 0; font-size: 15px; font-weight: 700; }
        .total { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1px; margin-top: 20px; border: 1px solid #bdd8d5; border-radius: 12px; overflow: hidden; background: #bdd8d5; }
        .total div { background: #eef8f5; padding: 16px; text-align: right; }
        .total strong { display: block; margin-top: 4px; font-size: 20px; }
        .installments { margin-top: 20px; }
        .installments table { width: 100%; border-collapse: collapse; font-size: 12px; }
        .installments th, .installments td { border-bottom: 1px solid #d5e4e2; padding: 8px; text-align: left; }
        .installments th { color: #45686b; font-size: 10px; text-transform: uppercase; }
        .actions { margin-top: 24px; text-align: right; }
        button { border: 0; border-radius: 10px; background: #00666f; color: white; padding: 12px 18px; font-size: 14px; font-weight: 700; cursor: pointer; }
        @media print {
            @page { size: A5 portrait; margin: 12mm; }
            body { background: white; }
            .receipt { width: 100%; margin: 0; padding: 0; box-shadow: none; }
            .actions { display: none; }
        }
    </style>
</head>
<body>
    @php
        $status = $payment['status'] ?? (($payment['is_paid'] ?? false) ? 'paid' : 'unpaid');
        $paid = $payment['paid'] ?? (($payment['is_paid'] ?? false) ? ($payment['amount'] ?? 0) : 0);
        $adjustment = $payment['adjustment'] ?? 0;
        $outstanding = $payment['outstanding'] ?? max(0, ($payment['amount'] ?? 0) - $paid - $adjustment);
        $installments = $payment['installments'] ?? [];
    @endphp
    <main class="receipt">
        <x-document-branding-header :branding="$branding">
            <div>
                <strong>REÇU DE PAIEMENT</strong>
                <p class="muted">Reçu n° {{ $payment['id'] }}</p>
            </div>
        </x-document-branding-header>

        <span class="paid">{{ $status === 'paid' ? 'Payé' : ($status === 'partial' ? 'Paiement partiel' : 'À régler') }}</span>

        <dl>
            <div class="row"><dt>Patient</dt><dd>{{ $payment['patient_name'] }}</dd></div>
            <div class="row"><dt>Dossier patient</dt><dd>{{ $payment['patient_number'] ?: '—' }}</dd></div>
            <div class="row"><dt>Prestation</dt><dd>{{ $payment['service'] }}</dd></div>
            <div class="row"><dt>Date</dt><dd>{{ $payment['date_label'] ?: '—' }}</dd></div>
            <div class="row"><dt>Mode de paiement</dt><dd>{{ $payment['method'] ?: '—' }}</dd></div>
            <div class="row"><dt>Enregistré par</dt><dd>{{ $payment['user_name'] ?: '—' }}</dd></div>
        </dl>

        <div class="total">
            <div>
                <span class="muted">FACTURÉ</span>
                <strong>{{ number_format($payment['amount'], 2) }} {{ $currency }}</strong>
            </div>
            <div>
                <span class="muted">ENCAISSÉ</span>
                <strong>{{ number_format($paid, 2) }} {{ $currency }}</strong>
            </div>
            <div>
                <span class="muted">RESTE DÛ</span>
                <strong>{{ number_format($outstanding, 2) }} {{ $currency }}</strong>
            </div>
        </div>

        @if($adjustment > 0)
            <p class="muted">Remise documentée : <strong>{{ number_format($adjustment, 2) }} {{ $currency }}</strong>{{ ! empty($payment['notes']) ? ' — '.$payment['notes'] : '' }}</p>
        @endif

        @if(count($installments) > 0)
            <section class="installments">
                <strong>Historique des versements</strong>
                <table>
                    <thead><tr><th>Date</th><th>Montant</th><th>Mode</th><th>Enregistré par</th></tr></thead>
                    <tbody>
                        @foreach($installments as $installment)
                            <tr>
                                <td>{{ $installment['received_at'] ? \Illuminate\Support\Carbon::parse($installment['received_at'])->format('d/m/Y H:i') : '—' }}</td>
                                <td>{{ number_format($installment['amount'], 2) }} {{ $currency }}</td>
                                <td>{{ $installment['method'] ?: '—' }}</td>
                                <td>{{ $installment['received_by'] ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
        @endif

        <x-document-branding-footer
            :branding="$branding"
            :include-receipt-footer="true"
        />

        <div class="actions"><button id="print-document" type="button">Imprimer le reçu</button></div>
    </main>

    <script nonce="{{ Vite::cspNonce() }}">
        document.getElementById('print-document')?.addEventListener('click', () => window.print());
    </script>
</body>
</html>
