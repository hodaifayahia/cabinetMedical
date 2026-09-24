@php
    $money = static fn (int|float|null $value): string => number_format((float) $value, 2, ',', ' ').' '.$currency;
    $percent = static fn (int|float|null $value): string => $value === null ? '—' : (($value >= 0 ? '+' : '').number_format((float) $value, 1, ',', ' ').' %');
    $period = $report['period'];
    $kpis = $report['kpis'];
    $previous = $report['previous'];
    $isMonth = $period['month'] !== null;
    $monthNames = [1 => 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
    $summary = [
        ['Encaissé (remboursements déduits)', $money($kpis['collected']), $money($previous['collected']), $percent($report['changes']['collected'])],
        ['Facturé', $money($kpis['billed']), $money($previous['billed']), $percent($report['changes']['billed'])],
        ['Remises accordées', $money($kpis['discounts']), $money($previous['discounts']), '—'],
        ['Remboursements', $money($kpis['refunds']), $money($previous['refunds']), '—'],
        ['Reste à encaisser sur la période', $money($kpis['outstanding']), $money($previous['outstanding']), '—'],
        ['Taux de recouvrement', $kpis['collection_rate'] === null ? '—' : number_format((float) $kpis['collection_rate'], 1, ',', ' ').' %', $previous['collection_rate'] === null ? '—' : number_format((float) $previous['collection_rate'], 1, ',', ' ').' %', '—'],
        ['Consultations facturées', (string) $kpis['consultations'], (string) $previous['consultations'], $percent($report['changes']['consultations'])],
        ['Panier moyen', $money($kpis['average_ticket']), $money($previous['average_ticket']), $percent($report['changes']['average_ticket'])],
        ['Versements reçus', (string) $kpis['transactions'], (string) $previous['transactions'], '—'],
    ];
    if ($profit !== null) {
        $summary[] = ['Charges du cabinet', $money($profit['expenses']), $money($profit['previous_expenses']), $percent($profit['changes']['expenses'])];
        $summary[] = ['Bénéfice net (encaissé − charges)', $money($profit['net']), $money($profit['previous_net']), $percent($profit['changes']['net'])];
        $summary[] = ['Marge nette', $profit['margin'] === null ? '—' : number_format((float) $profit['margin'], 1, ',', ' ').' %', '—', '—'];
    }
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Rapport financier · {{ $period['label'] }}</title>
    <style nonce="{{ Vite::cspNonce() }}">
        * { box-sizing: border-box; }
        body { margin: 0; background: #eef7f5; color: #073d42; font-family: Arial, sans-serif; }
        .sheet { width: min(980px, calc(100% - 32px)); margin: 24px auto; background: white; padding: 38px; box-shadow: 0 16px 50px rgba(15, 35, 55, .12); }
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
        .muted { color: #667085; font-size: 12px; }
        .title { margin: 26px 0 6px; padding: 14px; background: #e7f5f1; text-align: center; font-size: 22px; font-weight: 800; }
        .subtitle { margin: 0 0 18px; text-align: center; color: #667085; font-size: 12px; }
        .hero { display: flex; justify-content: space-between; gap: 16px; margin-bottom: 18px; }
        .hero div { flex: 1; border: 1px solid #cfe3df; border-radius: 10px; padding: 12px 14px; }
        .hero span { display: block; color: #667085; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; }
        .hero strong { display: block; margin-top: 4px; font-size: 20px; }
        h2 { margin: 24px 0 8px; font-size: 15px; text-transform: uppercase; letter-spacing: .04em; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th, td { border: 1px solid #c5d2da; padding: 7px 8px; vertical-align: top; }
        th { background: #eef8f5; font-weight: 800; text-align: left; }
        td.number, th.number { text-align: right; white-space: nowrap; }
        tr.future td { color: #a0aab4; }
        tfoot td { background: #f5f7f9; font-weight: 800; }
        .columns { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
        .actions { position: fixed; right: 24px; bottom: 24px; }
        button { border: 0; border-radius: 10px; background: #00666f; color: white; padding: 12px 18px; font-size: 14px; font-weight: 700; cursor: pointer; }
        .note { margin-top: 16px; color: #667085; font-size: 11px; line-height: 1.5; }
        @media print {
            @page { size: A4 portrait; margin: 12mm; }
            body { background: white; }
            .sheet { width: 100%; margin: 0; padding: 0; box-shadow: none; }
            .actions { display: none; }
            h2, table { break-inside: avoid; }
        }
    </style>
</head>
<body>
    <main class="sheet">
        <x-document-branding-header :branding="$branding">
            <div>
                <strong>RAPPORT FINANCIER</strong>
                <p class="muted">Édité le {{ $generatedAt }}</p>
            </div>
        </x-document-branding-header>

        <div class="title">{{ mb_strtoupper($period['label']) }}</div>
        <p class="subtitle">
            Du {{ \Illuminate\Support\Carbon::parse($period['from'])->format('d/m/Y') }}
            au {{ \Illuminate\Support\Carbon::parse($period['to'])->format('d/m/Y') }}
            · comparé à {{ $report['comparison']['label'] }}@if($report['comparison']['partial']) (même durée écoulée)@endif
        </p>

        <section class="hero">
            <div><span>Encaissé</span><strong>{{ $money($kpis['collected']) }}</strong></div>
            <div><span>Facturé</span><strong>{{ $money($kpis['billed']) }}</strong></div>
            @if($profit !== null)
                <div><span>Bénéfice net</span><strong>{{ $money($profit['net']) }}</strong></div>
            @else
                <div><span>Dettes patients (à ce jour)</span><strong>{{ $money($receivables['total']) }}</strong></div>
            @endif
        </section>

        <h2>Synthèse</h2>
        <table>
            <thead>
                <tr><th>Indicateur</th><th class="number">{{ $period['label'] }}</th><th class="number">{{ $report['comparison']['label'] }}</th><th class="number">Évolution</th></tr>
            </thead>
            <tbody>
                @foreach($summary as [$label, $current, $before, $change])
                    <tr><td>{{ $label }}</td><td class="number">{{ $current }}</td><td class="number">{{ $before }}</td><td class="number">{{ $change }}</td></tr>
                @endforeach
            </tbody>
        </table>

        <h2>{{ $isMonth ? 'Détail jour par jour' : 'Détail mois par mois' }}</h2>
        <table>
            <thead>
                <tr>
                    <th>{{ $isMonth ? 'Jour' : 'Mois' }}</th>
                    <th class="number">Facturé</th>
                    <th class="number">Encaissé</th>
                    <th class="number">Remboursé</th>
                    <th class="number">Consult.</th>
                    @unless($isMonth)<th class="number">Encaissé {{ $period['year'] - 1 }}</th>@endunless
                </tr>
            </thead>
            <tbody>
                @foreach($report['timeline'] as $row)
                    @continue($isMonth && $row['billed'] == 0 && $row['collected'] == 0 && $row['refunds'] == 0)
                    <tr @class(['future' => $row['future']])>
                        <td>{{ $isMonth ? $row['weekday'].' '.$row['day'] : $monthNames[$row['month']] }}</td>
                        <td class="number">{{ $money($row['billed']) }}</td>
                        <td class="number">{{ $money($row['collected']) }}</td>
                        <td class="number">{{ $row['refunds'] > 0 ? $money($row['refunds']) : '—' }}</td>
                        <td class="number">{{ $row['consultations'] }}</td>
                        @unless($isMonth)<td class="number">{{ $money($row['previous_collected']) }}</td>@endunless
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td>Total</td>
                    <td class="number">{{ $money($kpis['billed']) }}</td>
                    <td class="number">{{ $money($kpis['collected']) }}</td>
                    <td class="number">{{ $money($kpis['refunds']) }}</td>
                    <td class="number">{{ $kpis['consultations'] }}</td>
                    @unless($isMonth)<td class="number">{{ $money(collect($report['timeline'])->sum('previous_collected')) }}</td>@endunless
                </tr>
            </tfoot>
        </table>

        <div class="columns">
            <div>
                <h2>Modes de paiement</h2>
                <table>
                    <thead><tr><th>Mode</th><th class="number">Part</th><th class="number">Montant</th></tr></thead>
                    <tbody>
                        @forelse($report['methods'] as $method)
                            <tr><td>{{ $method['label'] }}</td><td class="number">{{ number_format((float) $method['share'], 1, ',', ' ') }} %</td><td class="number">{{ $money($method['value']) }}</td></tr>
                        @empty
                            <tr><td colspan="3" class="muted">Aucun encaissement.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div>
                <h2>Encaissements par utilisateur</h2>
                <table>
                    <thead><tr><th>Utilisateur</th><th class="number">Versements</th><th class="number">Montant</th></tr></thead>
                    <tbody>
                        @forelse($report['practitioners'] as $row)
                            <tr><td>{{ $row['label'] }}</td><td class="number">{{ $row['count'] }}</td><td class="number">{{ $money($row['value']) }}</td></tr>
                        @empty
                            <tr><td colspan="3" class="muted">Aucun encaissement.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if($profit !== null && count($profit['categories']) > 0)
            <h2>Charges par catégorie</h2>
            <table>
                <thead><tr><th>Catégorie</th><th class="number">Part</th><th class="number">Montant</th></tr></thead>
                <tbody>
                    @foreach($profit['categories'] as $category)
                        <tr><td>{{ $category['label'] }}</td><td class="number">{{ number_format((float) $category['share'], 1, ',', ' ') }} %</td><td class="number">{{ $money($category['value']) }}</td></tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr><td>Total</td><td></td><td class="number">{{ $money($profit['expenses']) }}</td></tr>
                </tfoot>
            </table>
        @endif

        <h2>Prestations facturées</h2>
        <table>
            <thead><tr><th>Prestation</th><th class="number">Nombre</th><th class="number">Facturé</th><th class="number">Déjà payé</th></tr></thead>
            <tbody>
                @forelse($report['services'] as $service)
                    <tr><td>{{ $service['label'] }}</td><td class="number">{{ $service['count'] }}</td><td class="number">{{ $money($service['value']) }}</td><td class="number">{{ $money($service['collected']) }}</td></tr>
                @empty
                    <tr><td colspan="4" class="muted">Aucune prestation facturée.</td></tr>
                @endforelse
            </tbody>
        </table>

        <h2>Dettes patients à ce jour</h2>
        <table>
            <thead><tr><th>Ancienneté</th><th class="number">Consultations</th><th class="number">Montant dû</th></tr></thead>
            <tbody>
                @foreach($receivables['buckets'] as $bucket)
                    <tr><td>{{ $bucket['label'] }}</td><td class="number">{{ $bucket['count'] }}</td><td class="number">{{ $money($bucket['amount']) }}</td></tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr><td>Total</td><td class="number">{{ $receivables['count'] }}</td><td class="number">{{ $money($receivables['total']) }}</td></tr>
            </tfoot>
        </table>

        <p class="note">
            « Encaissé » additionne l’argent reçu pendant la période (date du versement), remboursements déduits,
            y compris pour des consultations plus anciennes. « Facturé » additionne les prestations des consultations
            de la période (date de consultation). Les dettes sont calculées à la date d’édition, toutes périodes confondues.
        </p>

        <x-document-branding-footer :branding="$branding" />
    </main>

    <div class="actions"><button id="print-document" type="button">Imprimer le rapport</button></div>

    <script nonce="{{ Vite::cspNonce() }}">
        document.getElementById('print-document')?.addEventListener('click', () => window.print());
    </script>
</body>
</html>
