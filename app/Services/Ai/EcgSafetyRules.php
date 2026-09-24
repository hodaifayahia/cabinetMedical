<?php

namespace App\Services\Ai;

/**
 * Deterministic ECG checks. General vision models misread rhythm on ECG images
 * (in our own tests every model called atrial fibrillation "sinus rhythm"), so
 * rate and regularity come from the software measuring the trace, and any
 * disagreement from the AI is surfaced to the doctor instead of being hidden.
 */
final class EcgSafetyRules
{
    /** RR variability above which the rhythm is treated as irregular. */
    public const IRREGULAR_CV_PERCENT = 12.0;

    /** Below this the rhythm is clearly regular. */
    public const REGULAR_CV_PERCENT = 6.0;

    /**
     * Validate and recompute the measurements sent by the screen's trace
     * reader, so derived values never depend on client-side arithmetic.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function normalise(array $input): array
    {
        $rr = array_values(array_filter(
            array_map(static fn ($value): int => (int) round((float) $value), is_array($input['rr_ms'] ?? null) ? array_slice($input['rr_ms'], 0, 300) : []),
            static fn (int $value): bool => $value >= 200 && $value <= 3000,
        ));

        $calipers = [];

        foreach (is_array($input['calipers'] ?? null) ? array_slice($input['calipers'], 0, 20) : [] as $caliper) {
            if (! is_array($caliper)) {
                continue;
            }

            $ms = (int) round((float) ($caliper['ms'] ?? 0));
            $label = strtoupper(trim((string) ($caliper['label'] ?? '')));

            if ($ms > 0 && $ms <= 3000) {
                $calipers[] = ['label' => mb_substr($label !== '' ? $label : 'MESURE', 0, 20), 'ms' => $ms];
            }
        }

        $stats = $this->rrStats($rr);

        return [
            'source' => in_array($input['source'] ?? null, ['auto', 'manual'], true) ? $input['source'] : 'auto',
            'paper_speed_mm_s' => in_array((int) ($input['paper_speed_mm_s'] ?? 25), [25, 50], true) ? (int) ($input['paper_speed_mm_s'] ?? 25) : 25,
            'px_per_mm' => isset($input['px_per_mm']) ? round((float) $input['px_per_mm'], 3) : null,
            'duration_s' => isset($input['duration_s']) ? round((float) $input['duration_s'], 2) : null,
            'beat_count' => count($rr) > 0 ? count($rr) + 1 : 0,
            'rr_ms' => $rr,
            ...$stats,
            'calipers' => $calipers,
            'qtc_ms' => $this->qtc($calipers, $stats['rr_mean_ms']),
        ];
    }

    /**
     * @param  list<int>  $rr
     * @return array{heart_rate_bpm: int|null, rr_mean_ms: int|null, rr_min_ms: int|null, rr_max_ms: int|null, rr_cv_percent: float|null}
     */
    private function rrStats(array $rr): array
    {
        if ($rr === []) {
            return ['heart_rate_bpm' => null, 'rr_mean_ms' => null, 'rr_min_ms' => null, 'rr_max_ms' => null, 'rr_cv_percent' => null];
        }

        $mean = array_sum($rr) / count($rr);
        $variance = array_sum(array_map(static fn (int $value): float => ($value - $mean) ** 2, $rr)) / count($rr);

        return [
            'heart_rate_bpm' => (int) round(60000 / $mean),
            'rr_mean_ms' => (int) round($mean),
            'rr_min_ms' => min($rr),
            'rr_max_ms' => max($rr),
            'rr_cv_percent' => count($rr) >= 2 ? round(sqrt($variance) / $mean * 100, 1) : null,
        ];
    }

    /**
     * Bazett's QTc from a "QT" caliper and the measured mean RR.
     *
     * @param  list<array{label: string, ms: int}>  $calipers
     */
    private function qtc(array $calipers, ?int $rrMean): ?int
    {
        $qt = collect($calipers)->first(fn (array $caliper): bool => $caliper['label'] === 'QT');

        if ($qt === null || $rrMean === null || $rrMean <= 0) {
            return null;
        }

        return (int) round($qt['ms'] / sqrt($rrMean / 1000));
    }

    /**
     * Flags raised by the measurements alone, and by disagreements between
     * the measurements and the AI reading.
     *
     * @param  array<string, mixed>|null  $measurements
     * @param  array<string, mixed>|null  $analysis
     * @return list<array{level: 'info'|'warning'|'critical', code: string, message: string}>
     */
    public function flags(?array $measurements, ?array $analysis, ?string $gender = null): array
    {
        $flags = [];
        $m = $measurements ?? [];
        $rate = $m['heart_rate_bpm'] ?? null;
        $cv = $m['rr_cv_percent'] ?? null;
        $beats = (int) ($m['beat_count'] ?? 0);

        if ($measurements !== null && $beats > 0 && $beats < 4) {
            $flags[] = $this->flag('info', 'few_beats', 'Trop peu de battements mesurés ('.$beats.') : sélectionnez une dérivation longue (DII) pour une mesure fiable.');
        }

        if (is_numeric($rate)) {
            if ($rate >= 150) {
                $flags[] = $this->flag('critical', 'rate_very_high', "Fréquence mesurée {$rate}/min : tachycardie marquée.");
            } elseif ($rate > 100) {
                $flags[] = $this->flag('warning', 'rate_high', "Fréquence mesurée {$rate}/min : tachycardie.");
            } elseif ($rate < 40) {
                $flags[] = $this->flag('critical', 'rate_very_low', "Fréquence mesurée {$rate}/min : bradycardie marquée.");
            } elseif ($rate < 50) {
                $flags[] = $this->flag('warning', 'rate_low', "Fréquence mesurée {$rate}/min : bradycardie.");
            }
        }

        $irregular = is_numeric($cv) && $beats >= 6 && $cv > self::IRREGULAR_CV_PERCENT;
        $regular = is_numeric($cv) && $beats >= 6 && $cv < self::REGULAR_CV_PERCENT;

        if ($irregular) {
            $flags[] = $this->flag('warning', 'rr_irregular', "Rythme irrégulier (variabilité RR {$cv} %) : rechercher une fibrillation atriale ou des extrasystoles.");
        }

        foreach ($m['calipers'] ?? [] as $caliper) {
            $ms = (int) $caliper['ms'];

            if ($caliper['label'] === 'QRS' && $ms >= 120) {
                $flags[] = $this->flag('warning', 'qrs_wide', "QRS mesuré {$ms} ms : QRS large (bloc de branche, rythme ventriculaire ?).");
            } elseif ($caliper['label'] === 'PR' && $ms > 200) {
                $flags[] = $this->flag('warning', 'pr_long', "PR mesuré {$ms} ms : allongé (BAV du 1er degré ?).");
            } elseif ($caliper['label'] === 'PR' && $ms < 120) {
                $flags[] = $this->flag('info', 'pr_short', "PR mesuré {$ms} ms : court (pré-excitation ?).");
            }
        }

        $qtc = $m['qtc_ms'] ?? null;
        $qtcLimit = $gender === 'female' ? 470 : 450;

        if (is_numeric($qtc)) {
            if ($qtc >= 500) {
                $flags[] = $this->flag('critical', 'qtc_very_long', "QTc (Bazett) {$qtc} ms : très allongé, risque de torsade de pointes.");
            } elseif ($qtc > $qtcLimit) {
                $flags[] = $this->flag('warning', 'qtc_long', "QTc (Bazett) {$qtc} ms : allongé. Vérifier les médicaments allongeant le QT.");
            }
        }

        if ($analysis !== null) {
            $flags = [...$flags, ...$this->conflicts($analysis, $rate, $irregular, $regular)];

            if (($analysis['quality']['rating'] ?? null) === 'mauvaise') {
                $flags[] = $this->flag('warning', 'poor_quality', 'Qualité du tracé jugée mauvaise : la lecture de l’IA est peu fiable.');
            }
        }

        return $flags;
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @return list<array{level: 'info'|'warning'|'critical', code: string, message: string}>
     */
    private function conflicts(array $analysis, mixed $rate, bool $irregular, bool $regular): array
    {
        $flags = [];
        $aiText = mb_strtolower(implode(' ', array_filter([
            $analysis['rhythm'] ?? null,
            $analysis['regularity'] ?? null,
            $analysis['primary_statement'] ?? null,
        ], 'is_string')));
        $aiSaysIrregular = ($analysis['regularity'] ?? null) === 'irrégulier'
            || str_contains($aiText, 'fibrillation')
            || str_contains($aiText, 'irrégul');

        if ($irregular && ! $aiSaysIrregular) {
            $flags[] = $this->flag('critical', 'conflict_rhythm', 'Désaccord : les mesures montrent un rythme irrégulier mais l’IA le décrit comme régulier. Vérifiez sur le tracé (FA possible).');
        }

        if ($regular && $aiSaysIrregular) {
            $flags[] = $this->flag('warning', 'conflict_rhythm', 'Désaccord : les mesures montrent un rythme régulier mais l’IA le décrit comme irrégulier. Vérifiez sur le tracé.');
        }

        $aiRate = $analysis['rate_bpm'] ?? null;

        if (is_numeric($rate) && is_numeric($aiRate) && $rate > 0 && abs($aiRate - $rate) / $rate > 0.15) {
            $flags[] = $this->flag('warning', 'conflict_rate', "Désaccord de fréquence : mesurée {$rate}/min, lue par l’IA ".((int) $aiRate).'/min. La mesure du logiciel fait foi.');
        }

        return $flags;
    }

    /**
     * Overall urgency: the most serious of the AI's and the software's.
     *
     * @param  list<array{level: string}>  $flags
     */
    public function urgency(?array $analysis, array $flags): ?string
    {
        $levels = ['normal' => 0, 'anormal' => 1, 'critique' => 2];
        $score = $analysis !== null ? ($levels[$analysis['urgency'] ?? 'anormal'] ?? 1) : null;

        foreach ($flags as $flag) {
            $score = max($score ?? 0, $flag['level'] === 'critical' ? 2 : ($flag['level'] === 'warning' ? 1 : 0));
        }

        return $score === null ? null : array_search($score, $levels, true);
    }

    /**
     * @param  'info'|'warning'|'critical'  $level
     * @return array{level: 'info'|'warning'|'critical', code: string, message: string}
     */
    private function flag(string $level, string $code, string $message): array
    {
        return ['level' => $level, 'code' => $code, 'message' => $message];
    }
}
