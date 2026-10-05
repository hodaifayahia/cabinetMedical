<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\EcgSafetyRules;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The deterministic ECG checks that keep the AI reading honest: the software
 * measures the trace, and every disagreement reaches the doctor.
 */
class EcgSafetyRulesTest extends TestCase
{
    private EcgSafetyRules $rules;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rules = new EcgSafetyRules;
    }

    /**
     * @param  list<array{level: string, code: string, message: string}>  $flags
     * @return list<string>
     */
    private function codes(array $flags): array
    {
        return array_column($flags, 'code');
    }

    /**
     * @param  list<array{level: string, code: string, message: string}>  $flags
     */
    private function levelOf(array $flags, string $code): ?string
    {
        foreach ($flags as $flag) {
            if ($flag['code'] === $code) {
                return $flag['level'];
            }
        }

        return null;
    }

    // ----- normalise() --------------------------------------------------

    public function test_empty_input_gives_safe_defaults(): void
    {
        $this->assertSame([
            'source' => 'auto',
            'paper_speed_mm_s' => 25,
            'px_per_mm' => null,
            'duration_s' => null,
            'beat_count' => 0,
            'rr_ms' => [],
            'heart_rate_bpm' => null,
            'rr_mean_ms' => null,
            'rr_min_ms' => null,
            'rr_max_ms' => null,
            'rr_cv_percent' => null,
            'calipers' => [],
            'qtc_ms' => null,
        ], $this->rules->normalise([]));
    }

    public function test_a_steady_rhythm_is_measured_on_the_server(): void
    {
        $m = $this->rules->normalise(['rr_ms' => [1000, 1000, 1000, 1000]]);

        $this->assertSame(5, $m['beat_count']);
        $this->assertSame(60, $m['heart_rate_bpm']);
        $this->assertSame(1000, $m['rr_mean_ms']);
        $this->assertSame(1000, $m['rr_min_ms']);
        $this->assertSame(1000, $m['rr_max_ms']);
        $this->assertSame(0.0, $m['rr_cv_percent']);
    }

    public function test_rate_and_variability_are_derived_from_the_intervals(): void
    {
        $m = $this->rules->normalise(['rr_ms' => [800, 1200]]);

        $this->assertSame(1000, $m['rr_mean_ms']);
        $this->assertSame(60, $m['heart_rate_bpm']);
        $this->assertSame(800, $m['rr_min_ms']);
        $this->assertSame(1200, $m['rr_max_ms']);
        // Population SD 200 / mean 1000.
        $this->assertSame(20.0, $m['rr_cv_percent']);
    }

    public function test_a_single_interval_has_no_variability(): void
    {
        $m = $this->rules->normalise(['rr_ms' => [750]]);

        $this->assertSame(2, $m['beat_count']);
        $this->assertSame(80, $m['heart_rate_bpm']);
        $this->assertNull($m['rr_cv_percent']);
    }

    public function test_impossible_intervals_are_discarded(): void
    {
        $m = $this->rules->normalise(['rr_ms' => [199, 200, 'abc', 3000, 3001, -500, 0, 1000]]);

        $this->assertSame([200, 3000, 1000], $m['rr_ms']);
    }

    public function test_fractional_intervals_are_rounded(): void
    {
        $m = $this->rules->normalise(['rr_ms' => [799.6, '800.4']]);

        $this->assertSame([800, 800], $m['rr_ms']);
    }

    public function test_only_the_first_300_intervals_are_kept(): void
    {
        $m = $this->rules->normalise(['rr_ms' => array_fill(0, 500, 900)]);

        $this->assertCount(300, $m['rr_ms']);
        $this->assertSame(301, $m['beat_count']);
    }

    public function test_intervals_that_are_not_a_list_are_ignored(): void
    {
        $this->assertSame([], $this->rules->normalise(['rr_ms' => '1000,1000'])['rr_ms']);
    }

    public function test_calipers_are_cleaned(): void
    {
        $m = $this->rules->normalise(['calipers' => [
            ['label' => ' qt ', 'ms' => 399.7],
            ['label' => '', 'ms' => 100],
            ['ms' => 90],
            ['label' => 'PR', 'ms' => 0],
            ['label' => 'PR', 'ms' => 3001],
            ['label' => str_repeat('x', 40), 'ms' => 50],
            'not a caliper',
        ]]);

        $this->assertSame([
            ['label' => 'QT', 'ms' => 400],
            ['label' => 'MESURE', 'ms' => 100],
            ['label' => 'MESURE', 'ms' => 90],
            ['label' => str_repeat('X', 20), 'ms' => 50],
        ], $m['calipers']);
    }

    public function test_only_the_first_20_calipers_are_read(): void
    {
        $m = $this->rules->normalise(['calipers' => array_fill(0, 30, ['label' => 'QRS', 'ms' => 90])]);

        $this->assertCount(20, $m['calipers']);
    }

    public function test_source_and_paper_speed_are_whitelisted(): void
    {
        $this->assertSame('manual', $this->rules->normalise(['source' => 'manual'])['source']);
        $this->assertSame('auto', $this->rules->normalise(['source' => 'ai'])['source']);
        $this->assertSame(50, $this->rules->normalise(['paper_speed_mm_s' => 50])['paper_speed_mm_s']);
        $this->assertSame(50, $this->rules->normalise(['paper_speed_mm_s' => '50'])['paper_speed_mm_s']);
        $this->assertSame(25, $this->rules->normalise(['paper_speed_mm_s' => 100])['paper_speed_mm_s']);
    }

    public function test_calibration_values_are_rounded(): void
    {
        $m = $this->rules->normalise(['px_per_mm' => '11.81234', 'duration_s' => 10.0049]);

        $this->assertSame(11.812, $m['px_per_mm']);
        $this->assertSame(10.0, $m['duration_s']);
    }

    public function test_qtc_uses_bazett_with_the_measured_mean_rr(): void
    {
        $atSixty = $this->rules->normalise(['rr_ms' => [1000, 1000], 'calipers' => [['label' => 'QT', 'ms' => 400]]]);
        $faster = $this->rules->normalise(['rr_ms' => [640, 640], 'calipers' => [['label' => 'qt', 'ms' => 400]]]);

        $this->assertSame(400, $atSixty['qtc_ms']);
        $this->assertSame(500, $faster['qtc_ms']);
    }

    public function test_qtc_needs_both_a_qt_caliper_and_intervals(): void
    {
        $this->assertNull($this->rules->normalise(['calipers' => [['label' => 'QT', 'ms' => 400]]])['qtc_ms']);
        $this->assertNull($this->rules->normalise(['rr_ms' => [1000, 1000], 'calipers' => [['label' => 'QRS', 'ms' => 90]]])['qtc_ms']);
    }

    // ----- flags() ------------------------------------------------------

    public function test_nothing_measured_and_nothing_read_raises_no_flag(): void
    {
        $this->assertSame([], $this->rules->flags(null, null));
        $this->assertSame([], $this->rules->flags($this->rules->normalise([]), null));
    }

    public function test_too_few_beats_suggests_a_longer_lead(): void
    {
        $flags = $this->rules->flags($this->rules->normalise(['rr_ms' => [1000, 1000]]), null);

        $this->assertSame('info', $this->levelOf($flags, 'few_beats'));
    }

    public function test_four_beats_are_enough(): void
    {
        $flags = $this->rules->flags($this->rules->normalise(['rr_ms' => [1000, 1000, 1000]]), null);

        $this->assertNotContains('few_beats', $this->codes($flags));
    }

    /**
     * @return array<string, array{0: int, 1: string|null, 2: string|null}>
     */
    public static function rates(): array
    {
        return [
            'marked tachycardia at 150' => [150, 'rate_very_high', 'critical'],
            'marked tachycardia at 200' => [200, 'rate_very_high', 'critical'],
            'tachycardia at 149' => [149, 'rate_high', 'warning'],
            'tachycardia at 101' => [101, 'rate_high', 'warning'],
            'upper normal 100' => [100, null, null],
            'normal 72' => [72, null, null],
            'lower normal 50' => [50, null, null],
            'bradycardia at 49' => [49, 'rate_low', 'warning'],
            'bradycardia at 40' => [40, 'rate_low', 'warning'],
            'marked bradycardia at 39' => [39, 'rate_very_low', 'critical'],
            'marked bradycardia at 20' => [20, 'rate_very_low', 'critical'],
        ];
    }

    #[DataProvider('rates')]
    public function test_heart_rate_thresholds(int $rate, ?string $code, ?string $level): void
    {
        $flags = $this->rules->flags(['heart_rate_bpm' => $rate, 'beat_count' => 10], null);
        $rateCodes = array_values(array_filter($this->codes($flags), static fn (string $c): bool => str_starts_with($c, 'rate_')));

        if ($code === null) {
            $this->assertSame([], $rateCodes);

            return;
        }

        $this->assertSame([$code], $rateCodes);
        $this->assertSame($level, $this->levelOf($flags, $code));
    }

    public function test_an_irregular_rhythm_is_flagged(): void
    {
        $flags = $this->rules->flags(['rr_cv_percent' => 18.4, 'beat_count' => 10], null);

        $this->assertSame('warning', $this->levelOf($flags, 'rr_irregular'));
    }

    public function test_variability_at_the_threshold_is_not_irregular(): void
    {
        $flags = $this->rules->flags(['rr_cv_percent' => EcgSafetyRules::IRREGULAR_CV_PERCENT, 'beat_count' => 10], null);

        $this->assertNotContains('rr_irregular', $this->codes($flags));
    }

    public function test_irregularity_needs_at_least_six_beats(): void
    {
        $flags = $this->rules->flags(['rr_cv_percent' => 30.0, 'beat_count' => 5], null);

        $this->assertNotContains('rr_irregular', $this->codes($flags));
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: string|null, 3: string|null}>
     */
    public static function calipers(): array
    {
        return [
            'wide QRS at 120' => ['QRS', 120, 'qrs_wide', 'warning'],
            'narrow QRS at 119' => ['QRS', 119, null, null],
            'long PR at 201' => ['PR', 201, 'pr_long', 'warning'],
            'normal PR at 200' => ['PR', 200, null, null],
            'normal PR at 120' => ['PR', 120, null, null],
            'short PR at 119' => ['PR', 119, 'pr_short', 'info'],
            'unrelated caliper' => ['MESURE', 900, null, null],
        ];
    }

    #[DataProvider('calipers')]
    public function test_interval_calipers(string $label, int $ms, ?string $code, ?string $level): void
    {
        $flags = $this->rules->flags(['calipers' => [['label' => $label, 'ms' => $ms]]], null);

        if ($code === null) {
            $this->assertSame([], $flags);

            return;
        }

        $this->assertSame([$code], $this->codes($flags));
        $this->assertSame($level, $this->levelOf($flags, $code));
    }

    /**
     * @return array<string, array{0: int, 1: string|null, 2: string|null}>
     */
    public static function qtcValues(): array
    {
        return [
            'normal man 450' => [450, 'male', null],
            'long man 451' => [451, 'male', 'qtc_long'],
            'woman 460 is still normal' => [460, 'female', null],
            'woman 470 is still normal' => [470, 'female', null],
            'long woman 471' => [471, 'female', 'qtc_long'],
            'unknown sex uses the stricter limit' => [460, null, 'qtc_long'],
            'very long 500' => [500, 'female', 'qtc_very_long'],
            'very long 560' => [560, 'male', 'qtc_very_long'],
        ];
    }

    #[DataProvider('qtcValues')]
    public function test_qtc_limits_depend_on_the_patients_sex(int $qtc, ?string $gender, ?string $code): void
    {
        $flags = $this->rules->flags(['qtc_ms' => $qtc], null, $gender);

        $this->assertSame($code === null ? [] : [$code], $this->codes($flags));
    }

    public function test_a_very_long_qtc_is_critical(): void
    {
        $this->assertSame('critical', $this->levelOf($this->rules->flags(['qtc_ms' => 520], null), 'qtc_very_long'));
    }

    public function test_an_ai_calling_an_irregular_trace_regular_is_a_critical_conflict(): void
    {
        $flags = $this->rules->flags(
            ['rr_cv_percent' => 20.0, 'beat_count' => 12, 'heart_rate_bpm' => 90],
            ['regularity' => 'régulier', 'rhythm' => 'Rythme sinusal', 'primary_statement' => 'ECG normal', 'rate_bpm' => 90],
        );

        $this->assertSame('critical', $this->levelOf($flags, 'conflict_rhythm'));
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function irregularReadings(): array
    {
        return [
            'regularity field' => [['regularity' => 'irrégulier']],
            'fibrillation in the rhythm' => [['rhythm' => 'Fibrillation atriale']],
            'irregular wording in the statement' => [['primary_statement' => 'Rythme irrégulièrement irrégulier']],
            'uppercase wording' => [['rhythm' => 'FIBRILLATION AURICULAIRE']],
        ];
    }

    /**
     * @param  array<string, mixed>  $analysis
     */
    #[DataProvider('irregularReadings')]
    public function test_an_ai_that_recognises_irregularity_raises_no_rhythm_conflict(array $analysis): void
    {
        $flags = $this->rules->flags(['rr_cv_percent' => 20.0, 'beat_count' => 12], $analysis);

        $this->assertNotContains('conflict_rhythm', $this->codes($flags));
        $this->assertContains('rr_irregular', $this->codes($flags));
    }

    public function test_an_ai_calling_a_regular_trace_irregular_is_a_warning_conflict(): void
    {
        $flags = $this->rules->flags(
            ['rr_cv_percent' => 2.0, 'beat_count' => 12],
            ['regularity' => 'irrégulier'],
        );

        $this->assertSame('warning', $this->levelOf($flags, 'conflict_rhythm'));
    }

    public function test_borderline_variability_raises_no_rhythm_conflict_either_way(): void
    {
        foreach (['régulier', 'irrégulier'] as $regularity) {
            $flags = $this->rules->flags(['rr_cv_percent' => 9.0, 'beat_count' => 12], ['regularity' => $regularity]);

            $this->assertNotContains('conflict_rhythm', $this->codes($flags), $regularity);
        }
    }

    public function test_a_rate_disagreement_above_15_percent_is_flagged(): void
    {
        $flags = $this->rules->flags(['heart_rate_bpm' => 100, 'beat_count' => 10], ['rate_bpm' => 120]);

        $this->assertSame('warning', $this->levelOf($flags, 'conflict_rate'));
        $this->assertStringContainsString('mesurée 100/min', $flags[array_search('conflict_rate', $this->codes($flags), true)]['message']);
    }

    public function test_a_rate_within_15_percent_is_accepted(): void
    {
        $flags = $this->rules->flags(['heart_rate_bpm' => 100, 'beat_count' => 10], ['rate_bpm' => 115]);

        $this->assertNotContains('conflict_rate', $this->codes($flags));
    }

    public function test_an_ai_without_a_rate_raises_no_rate_conflict(): void
    {
        $flags = $this->rules->flags(['heart_rate_bpm' => 80, 'beat_count' => 10], ['rate_bpm' => null]);

        $this->assertNotContains('conflict_rate', $this->codes($flags));
    }

    public function test_a_poor_quality_trace_is_flagged(): void
    {
        $flags = $this->rules->flags(null, ['quality' => ['rating' => 'mauvaise']]);

        $this->assertSame(['poor_quality'], $this->codes($flags));
    }

    public function test_an_ai_reading_without_measurements_raises_no_conflict(): void
    {
        $this->assertSame([], $this->rules->flags(null, ['regularity' => 'irrégulier', 'rate_bpm' => 180]));
    }

    public function test_malformed_ai_fields_do_not_break_the_checks(): void
    {
        $flags = $this->rules->flags(
            ['heart_rate_bpm' => 80, 'rr_cv_percent' => 3.0, 'beat_count' => 10],
            ['regularity' => ['irrégulier'], 'rhythm' => 42, 'primary_statement' => null, 'rate_bpm' => 'rapide', 'quality' => 'bonne'],
        );

        $this->assertSame([], $flags);
    }

    public function test_every_flag_has_a_level_a_code_and_a_message(): void
    {
        $flags = $this->rules->flags(
            ['heart_rate_bpm' => 160, 'rr_cv_percent' => 25.0, 'beat_count' => 3, 'qtc_ms' => 510, 'calipers' => [['label' => 'QRS', 'ms' => 140]]],
            ['regularity' => 'régulier', 'rate_bpm' => 80, 'quality' => ['rating' => 'mauvaise']],
            'male',
        );

        $this->assertNotEmpty($flags);

        foreach ($flags as $flag) {
            $this->assertContains($flag['level'], ['info', 'warning', 'critical']);
            $this->assertNotSame('', $flag['code']);
            $this->assertNotSame('', $flag['message']);
        }
    }

    // ----- urgency() ----------------------------------------------------

    public function test_no_reading_and_no_flag_has_no_urgency(): void
    {
        $this->assertNull($this->rules->urgency(null, []));
    }

    public function test_the_ai_urgency_is_used_when_nothing_is_flagged(): void
    {
        $this->assertSame('normal', $this->rules->urgency(['urgency' => 'normal'], []));
        $this->assertSame('anormal', $this->rules->urgency(['urgency' => 'anormal'], []));
        $this->assertSame('critique', $this->rules->urgency(['urgency' => 'critique'], []));
    }

    public function test_a_missing_or_unknown_ai_urgency_counts_as_abnormal(): void
    {
        $this->assertSame('anormal', $this->rules->urgency([], []));
        $this->assertSame('anormal', $this->rules->urgency(['urgency' => 'urgent'], []));
    }

    public function test_flags_raise_the_urgency_but_never_lower_it(): void
    {
        $this->assertSame('anormal', $this->rules->urgency(['urgency' => 'normal'], [['level' => 'warning']]));
        $this->assertSame('critique', $this->rules->urgency(['urgency' => 'normal'], [['level' => 'critical']]));
        $this->assertSame('critique', $this->rules->urgency(['urgency' => 'critique'], [['level' => 'info']]));
        $this->assertSame('normal', $this->rules->urgency(['urgency' => 'normal'], [['level' => 'info']]));
    }

    public function test_flags_alone_give_an_urgency_without_an_ai_reading(): void
    {
        $this->assertSame('normal', $this->rules->urgency(null, [['level' => 'info']]));
        $this->assertSame('anormal', $this->rules->urgency(null, [['level' => 'info'], ['level' => 'warning']]));
        $this->assertSame('critique', $this->rules->urgency(null, [['level' => 'warning'], ['level' => 'critical']]));
    }
}
