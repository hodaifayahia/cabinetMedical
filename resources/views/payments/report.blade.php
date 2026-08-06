<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Rapport des paiements</title>
    <style nonce="{{ Vite::cspNonce() }}">
        * { box-sizing: border-box; }
        body { margin: 0; background: #eef3f7; color: #172033; font-family: Arial, sans-serif; }
        .sheet { width: min(1120px, calc(100% - 32px)); margin: 24px auto; background: white; padding: 38px; box-shadow: 0 16px 50px rgba(15, 35, 55, .12); }
        .document-branding-header { display: flex; justify-content: space-between; gap: 24px; padding-bottom: 20px; border-bottom: 2px solid #173f67; }
        .document-branding-identity { display: flex; min-width: 0; align-items: flex-start; gap: 14px; }
        .document-branding-logo { width: 86px; max-height: 66px; object-fit: contain; }
        .document-branding-copy { min-width: 0; }
        .document-branding-copy h1 { margin: 0; font-size: 25px; letter-spacing: -.02em; }
        .document-branding-doctor { margin: 5px 0 0; font-size: 13px; font-weight: 700; }
        .document-branding-meta { margin: 3px 0 0; color: #667085; font-size: 11px; }
        .document-branding-document { flex: 0 0 auto; text-align: right; }
        .document-branding-footer { margin-top: 18px; border-top: 1px dashed #9ca9b5; padding-top: 9px; text-align: center; color: #667085; font-size: 10px; }
        .document-branding-footer p { margin: 2px 0; }
        .muted { color: #667085; font-size: 13px; }
        .title { margin: 28px 0 14px; padding: 14px; background: #edf5fb; text-align: center; font-size: 22px; font-weight: 800; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th, td { border: 1px solid #9ca9b5; padding: 9px 8px; vertical-align: top; }
        th { background: #e7edf2; font-weight: 800; text-transform: uppercase; }
        td.number, th.number { text-align: right; white-space: nowrap; }
        tfoot td { background: #f5f7f9; font-weight: 800; }
        .actions { position: fixed; right: 24px; bottom: 24px; }
        button { border: 0; border-radius: 10px; background: #087f4f; color: white; padding: 12px 18px; font-size: 14px; font-weight: 700; cursor: pointer; }
        @media print {
            @page { size: A4 landscape; margin: 12mm; }
            body { background: white; }
            .sheet { width: 100%; margin: 0; padding: 0; box-shadow: none; }
            .actions { display: none; }
        }
    </style>
</head>
<body>
    <main class="sheet">
        <x-document-branding-header :branding="$branding">
            <div>
                <strong>RAPPORT DES PAIEMENTS</strong>
                <p class="muted">{{ $filters['from'] }} — {{ $filters['to'] }}</p>
            </div>
        </x-document-branding-header>

        <div class="title">SUIVI DES PAIEMENTS</div>

        <table>
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Patient</th>
                    <th>Prestations</th>
                    <th>Date</th>
                    <th>Mode</th>
                    <th class="number">Montant</th>
                    <th class="number">Payé</th>
                    <th class="number">Reste dû</th>
                </tr>
            </thead>
            <tbody>
                @forelse($payments as $payment)
                    <tr>
                        <td>{{ $payment['patient_number'] ?: '—' }}</td>
                        <td>{{ $payment['patient_name'] }}</td>
                        <td>{{ $payment['service'] }}</td>
                        <td>{{ $payment['date_label'] ?: '—' }}</td>
                        <td>{{ $payment['method'] ?: '—' }}</td>
                        <td class="number">{{ number_format($payment['amount'], 2) }} {{ $currency }}</td>
                        <td class="number">{{ number_format($payment['paid'], 2) }} {{ $currency }}</td>
                        <td class="number">{{ number_format($payment['outstanding'], 2) }} {{ $currency }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" style="text-align:center;padding:30px">Aucun paiement ne correspond à ces filtres.</td></tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="5" class="number">TOTAL</td>
                    <td class="number">{{ number_format($totals['amount'], 2) }} {{ $currency }}</td>
                    <td class="number">{{ number_format($totals['paid'], 2) }} {{ $currency }}</td>
                    <td class="number">{{ number_format($totals['outstanding'], 2) }} {{ $currency }}</td>
                </tr>
            </tfoot>
        </table>

        <x-document-branding-footer :branding="$branding" />
    </main>

    <div class="actions"><button id="print-document" type="button">Imprimer le rapport</button></div>

    <script nonce="{{ Vite::cspNonce() }}">
        document.getElementById('print-document')?.addEventListener('click', () => window.print());
    </script>
</body>
</html>
