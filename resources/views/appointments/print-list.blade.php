<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Liste des rendez-vous</title>
    <style nonce="{{ Vite::cspNonce() }}">
        * { box-sizing: border-box; }
        body { margin: 0; background: #eef7f5; color: #00424a; font-family: Arial, sans-serif; }
        .sheet { width: min(1120px, calc(100% - 32px)); margin: 24px auto; background: white; padding: 38px; box-shadow: 0 16px 50px rgba(15, 35, 55, .12); }
        .document-branding-header { display: flex; justify-content: space-between; gap: 24px; padding-bottom: 20px; border-bottom: 2px solid #00666f; }
        .document-branding-identity { display: flex; min-width: 0; align-items: flex-start; gap: 14px; }
        .document-branding-logo { width: 86px; max-height: 66px; object-fit: contain; }
        .document-branding-copy { min-width: 0; }
        .document-branding-copy h1 { margin: 0; font-size: 25px; letter-spacing: -.02em; }
        .document-branding-doctor { margin: 5px 0 0; font-size: 13px; font-weight: 700; }
        .document-branding-meta { margin: 3px 0 0; color: #667085; font-size: 11px; }
        .document-branding-document { flex: 0 0 auto; text-align: right; }
        .document-branding-footer { margin-top: 18px; border-top: 1px dashed #9ca9b5; padding-top: 9px; text-align: center; color: #667085; font-size: 10px; }
        .document-branding-footer p { margin: 2px 0; }
        .muted { margin: 4px 0 0; color: #667085; font-size: 12px; }
        .title { margin: 26px 0 12px; font-size: 21px; font-weight: 800; }
        .filters { margin-bottom: 16px; border-radius: 10px; background: #e7f5f1; padding: 11px 14px; font-size: 12px; }
        .warning { margin-bottom: 14px; border: 1px solid #f0b429; border-radius: 8px; background: #fff8e6; padding: 9px 12px; font-size: 11px; }
        table { width: 100%; border-collapse: collapse; font-size: 11px; }
        th, td { border: 1px solid #aab5c0; padding: 8px 7px; vertical-align: top; }
        th { background: #eef8f5; font-weight: 800; text-align: left; text-transform: uppercase; }
        td.nowrap { white-space: nowrap; }
        .empty { padding: 28px; text-align: center; color: #667085; }
        .actions { position: fixed; right: 24px; bottom: 24px; }
        button { border: 0; border-radius: 10px; background: #00666f; color: white; padding: 12px 18px; font-size: 14px; font-weight: 700; cursor: pointer; }
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
                <strong>LISTE DES RENDEZ-VOUS</strong>
                <p class="muted">Générée le {{ $generatedAt }}</p>
            </div>
        </x-document-branding-header>

        <h2 class="title">Rendez-vous</h2>
        <div class="filters">
            @if ($search !== '')
                Recherche : <strong>{{ $search }}</strong>
            @else
                Date : <strong>{{ $filterDate }}</strong>
            @endif
            <span> · État : <strong>{{ $status }}</strong></span>
        </div>

        @if ($truncated)
            <p class="warning">Le document est limité aux 1 000 premiers résultats. Affinez la recherche avant de réimprimer.</p>
        @endif

        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Heure</th>
                    <th>Dossier</th>
                    <th>Patient</th>
                    <th>Prestation / motif</th>
                    <th>État</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($appointments as $appointment)
                    <tr>
                        <td class="nowrap">{{ $appointment['date'] ?: '—' }}</td>
                        <td class="nowrap">{{ $appointment['time'] ?: '—' }}</td>
                        <td>{{ $appointment['patient_number'] ?: '—' }}</td>
                        <td>{{ $appointment['patient_name'] ?: '—' }}</td>
                        <td>
                            {{ $appointment['prestation'] ?: ($appointment['reason'] ?: '—') }}
                        </td>
                        <td class="nowrap">{{ $appointment['status'] }}</td>
                    </tr>
                @empty
                    <tr><td class="empty" colspan="6">Aucun rendez-vous ne correspond à ces filtres.</td></tr>
                @endforelse
            </tbody>
        </table>

        <x-document-branding-footer :branding="$branding" />
    </main>

    <div class="actions"><button id="print-document" type="button">Imprimer la liste</button></div>

    <script nonce="{{ Vite::cspNonce() }}">
        document.getElementById('print-document')?.addEventListener('click', () => window.print());
    </script>
</body>
</html>
