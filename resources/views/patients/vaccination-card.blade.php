<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Carnet de vaccination · {{ $patient->full_name }}</title>
    <style nonce="{{ Vite::cspNonce() }}">
        * { box-sizing: border-box; }
        body { margin: 0; background: #eef7f5; color: #073d42; font-family: Arial, sans-serif; }
        .sheet { width: min(820px, calc(100% - 32px)); margin: 24px auto; background: white; padding: 36px; box-shadow: 0 16px 50px rgba(15, 35, 55, .12); }
        .document-branding-header { display: flex; justify-content: space-between; gap: 24px; padding-bottom: 18px; border-bottom: 2px solid #00666f; }
        .document-branding-identity { display: flex; min-width: 0; align-items: flex-start; gap: 14px; }
        .document-branding-logo { width: 78px; max-height: 62px; object-fit: contain; }
        .document-branding-copy { min-width: 0; }
        .document-branding-copy h1 { margin: 0; font-size: 22px; }
        .document-branding-doctor { margin: 5px 0 0; font-size: 13px; font-weight: 700; }
        .document-branding-meta { margin: 3px 0 0; color: #667085; font-size: 11px; }
        .document-branding-document { flex: 0 0 auto; text-align: right; }
        .document-branding-footer { margin-top: 20px; border-top: 1px dashed #9ca9b5; padding-top: 9px; text-align: center; color: #667085; font-size: 10px; }
        .document-branding-footer p { margin: 2px 0; }
        .muted { color: #667085; font-size: 12px; }
        .title { margin: 22px 0 6px; padding: 12px; background: #e7f5f1; text-align: center; font-size: 20px; font-weight: 800; }
        .identity { margin: 0 0 16px; text-align: center; font-size: 13px; }
        h2 { margin: 20px 0 8px; font-size: 14px; text-transform: uppercase; letter-spacing: .04em; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th, td { border: 1px solid #c5d2da; padding: 7px 8px; text-align: left; vertical-align: top; }
        th { background: #eef8f5; }
        .done { color: #166534; font-weight: 700; }
        .overdue { color: #b42318; font-weight: 700; }
        .actions { position: fixed; right: 24px; bottom: 24px; }
        button { border: 0; border-radius: 10px; background: #00666f; color: white; padding: 12px 18px; font-size: 14px; font-weight: 700; cursor: pointer; }
        @media print {
            @page { size: A4 portrait; margin: 12mm; }
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
                <strong>CARNET DE VACCINATION</strong>
                <p class="muted">Édité le {{ now()->format('d/m/Y') }}</p>
            </div>
        </x-document-branding-header>

        <div class="title">{{ $patient->full_name }}</div>
        <p class="identity">
            Dossier {{ $patient->patient_number }}
            @if($patient->date_of_birth) · né(e) le {{ $patient->date_of_birth->format('d/m/Y') }} @endif
        </p>

        <h2>Vaccins reçus</h2>
        <table>
            <thead><tr><th>Date</th><th>Vaccin</th><th>Dose</th><th>Lot</th><th>Remarque</th></tr></thead>
            <tbody>
                @forelse($card['records'] as $record)
                    <tr>
                        <td>{{ \Illuminate\Support\Carbon::parse($record['given_on'])->format('d/m/Y') }}</td>
                        <td>{{ $record['vaccine'] }}</td>
                        <td>{{ $record['dose'] ?? '—' }}</td>
                        <td>{{ $record['lot'] ?? '—' }}</td>
                        <td>{{ $record['notes'] ?? '' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">Aucune vaccination enregistrée.</td></tr>
                @endforelse
            </tbody>
        </table>

        @if(count($card['schedule']) > 0)
            <h2>Calendrier vaccinal de l’enfant</h2>
            <table>
                <thead><tr><th>Âge</th><th>Prévu le</th><th>Vaccins</th></tr></thead>
                <tbody>
                    @foreach($card['schedule'] as $slot)
                        <tr>
                            <td>{{ $slot['label'] }}</td>
                            <td>{{ \Illuminate\Support\Carbon::parse($slot['due_on'])->format('d/m/Y') }}</td>
                            <td>
                                @foreach($slot['doses'] as $dose)
                                    <div @class(['done' => $dose['status'] === 'done', 'overdue' => $dose['status'] === 'overdue'])>
                                        {{ $dose['status'] === 'done' ? '✓' : ($dose['status'] === 'overdue' ? '!' : '○') }} {{ $dose['label'] }}
                                    </div>
                                @endforeach
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p class="muted">Calendrier national indicatif (MSPRH, révision 2016). Se référer au calendrier officiel en vigueur.</p>
        @endif

        <x-document-branding-footer :branding="$branding" />
    </main>

    <div class="actions"><button id="print-document" type="button">Imprimer le carnet</button></div>

    <script nonce="{{ Vite::cspNonce() }}">
        document.getElementById('print-document')?.addEventListener('click', () => window.print());
    </script>
</body>
</html>
