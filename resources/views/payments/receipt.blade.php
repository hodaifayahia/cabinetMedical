<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reçu de paiement n° {{ $payment['id'] }}</title>
    <style nonce="{{ Vite::cspNonce() }}">
        * { box-sizing: border-box; }
        body { margin: 0; background: #edf2f6; color: #172033; font-family: Arial, sans-serif; }
        .receipt { width: min(720px, calc(100% - 32px)); margin: 32px auto; background: white; padding: 38px; box-shadow: 0 16px 50px rgba(15, 35, 55, .12); }
        .document-branding-header { display: flex; justify-content: space-between; gap: 24px; border-bottom: 2px solid #1d5d8f; padding-bottom: 18px; }
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
        .total { margin-top: 20px; border-radius: 12px; background: #edf5fb; padding: 18px; text-align: right; }
        .total strong { display: block; font-size: 28px; }
        .actions { margin-top: 24px; text-align: right; }
        button { border: 0; border-radius: 10px; background: #087f4f; color: white; padding: 12px 18px; font-size: 14px; font-weight: 700; cursor: pointer; }
        @media print {
            @page { size: A5 portrait; margin: 12mm; }
            body { background: white; }
            .receipt { width: 100%; margin: 0; padding: 0; box-shadow: none; }
            .actions { display: none; }
        }
    </style>
</head>
<body>
    <main class="receipt">
        <x-document-branding-header :branding="$branding">
            <div>
                <strong>REÇU DE PAIEMENT</strong>
                <p class="muted">Reçu n° {{ $payment['id'] }}</p>
            </div>
        </x-document-branding-header>

        <span class="paid">{{ $payment['is_paid'] ? 'Payé' : 'À régler' }}</span>

        <dl>
            <div class="row"><dt>Patient</dt><dd>{{ $payment['patient_name'] }}</dd></div>
            <div class="row"><dt>Dossier patient</dt><dd>{{ $payment['patient_number'] ?: '—' }}</dd></div>
            <div class="row"><dt>Prestation</dt><dd>{{ $payment['service'] }}</dd></div>
            <div class="row"><dt>Date</dt><dd>{{ $payment['date_label'] ?: '—' }}</dd></div>
            <div class="row"><dt>Mode de paiement</dt><dd>{{ $payment['method'] ?: '—' }}</dd></div>
            <div class="row"><dt>Enregistré par</dt><dd>{{ $payment['user_name'] ?: '—' }}</dd></div>
        </dl>

        <div class="total">
            <span class="muted">MONTANT TOTAL</span>
            <strong>{{ number_format($payment['amount'], 2) }} {{ $currency }}</strong>
        </div>

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
