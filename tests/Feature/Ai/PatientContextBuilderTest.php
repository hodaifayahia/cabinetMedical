<?php

namespace Tests\Feature\Ai;

use App\Actions\Patients\LinkPatientRelativeAction;
use App\Enums\PatientRelation;
use App\Models\AiInsight;
use App\Models\Consultation;
use App\Models\Document;
use App\Models\EcgRecord;
use App\Models\Patient;
use App\Models\PatientMeasurement;
use App\Models\Prescription;
use App\Services\Ai\PatientContextBuilder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithAi;
use Tests\TestCase;

/**
 * The dossier text the model reads. Identity never leaves the cabinet, and a
 * dossier of any size fits in the prompt.
 */
class PatientContextBuilderTest extends TestCase
{
    use InteractsWithAi;
    use RefreshDatabase;

    private Patient $patient;

    private Consultation $consultation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        [, , $this->consultation, $this->patient] = $this->aiConsultation([
            'gender' => 'female',
            'date_of_birth' => now()->subYears(42)->subMonths(3),
            'blood_group' => null,
            'profession' => 'Enseignante',
            'allergies' => 'Pénicilline',
            'antecedents_medical' => 'HTA depuis 2019',
        ]);
    }

    private function builder(): PatientContextBuilder
    {
        return app(PatientContextBuilder::class);
    }

    public function test_identity_never_reaches_the_prompt(): void
    {
        $text = $this->builder()->build($this->patient, $this->consultation, full: true);

        foreach (['Yasmine', 'Benali', '0555123456', 'yasmine@example.test', '12 rue des Oliviers', $this->patient->patient_number] as $private) {
            $this->assertStringNotContainsString((string) $private, $text);
        }
    }

    public function test_clinical_identity_is_kept(): void
    {
        $text = $this->builder()->build($this->patient);

        $this->assertStringContainsString("PATIENT\nSexe : Femme", $text);
        $this->assertStringContainsString('Âge : 42 ans', $text);
        $this->assertStringContainsString('Profession : Enseignante', $text);
        $this->assertStringNotContainsString('Groupe sanguin', $text);
    }

    public function test_history_is_included_and_empty_fields_are_left_out(): void
    {
        $text = $this->builder()->build($this->patient);

        $this->assertStringContainsString('ANTÉCÉDENTS', $text);
        $this->assertStringContainsString('Allergies et réactions connues : Pénicilline', $text);
        $this->assertStringContainsString('Maladies chroniques / antécédents médicaux : HTA depuis 2019', $text);
        $this->assertStringNotContainsString('Chirurgicaux', $text);
    }

    public function test_relatives_findings_are_included_without_their_identity(): void
    {
        $brother = Patient::factory()->create([
            'first_name' => 'Karim',
            'last_name' => 'Zerrouki',
            'gender' => 'male',
            'date_of_birth' => now()->subYears(50)->subMonth(),
            'antecedents_medical' => 'Diabète type 2',
            'allergies' => null,
        ]);
        app(LinkPatientRelativeAction::class)->link($this->patient, $brother, PatientRelation::BROTHER);

        $text = $this->builder()->build($this->patient);

        $this->assertStringContainsString("ANTÉCÉDENTS DES PROCHES (dossiers liés)\n- Frère (50 ans) : Diabète type 2", $text);
        $this->assertStringNotContainsString('Karim', $text);
        $this->assertStringNotContainsString('Zerrouki', $text);
        $this->assertStringNotContainsString((string) $brother->patient_number, $text);
    }

    public function test_without_relatives_there_is_no_relatives_section(): void
    {
        $this->assertStringNotContainsString('ANTÉCÉDENTS DES PROCHES', $this->builder()->build($this->patient));
    }

    public function test_the_unsaved_draft_wins_over_the_stored_visit(): void
    {
        $text = $this->builder()->build($this->patient, $this->consultation, ['motif' => 'Douleur thoracique', 'diagnostic' => 'Angor ?']);

        $this->assertStringContainsString('Motif : Douleur thoracique', $text);
        $this->assertStringContainsString('Diagnostic : Angor ?', $text);
        $this->assertStringNotContainsString('Fièvre et toux', $text);
    }

    public function test_a_draft_can_clear_a_stored_field(): void
    {
        $text = $this->builder()->build($this->patient, $this->consultation, ['motif' => null]);

        $this->assertStringNotContainsString('Fièvre et toux', $text);
        $this->assertStringContainsString('(rien de saisi pour l’instant)', $text);
    }

    public function test_the_current_visit_is_always_announced_even_when_empty(): void
    {
        $this->consultation->update(['motif' => null]);

        $text = $this->builder()->build($this->patient, $this->consultation->fresh());

        $this->assertStringContainsString('CONSULTATION ACTUELLE ('.now()->format('d/m/Y').")\n(rien de saisi pour l’instant)", $text);
    }

    public function test_without_a_current_visit_there_is_no_current_section(): void
    {
        $this->assertStringNotContainsString('CONSULTATION ACTUELLE', $this->builder()->build($this->patient));
    }

    public function test_previous_visits_exclude_the_current_one_and_are_limited(): void
    {
        foreach (range(1, 20) as $i) {
            Consultation::query()->create([
                'patient_id' => $this->patient->getKey(),
                'consulted_at' => now()->subDays($i),
                'status' => 'completed',
                'motif' => 'Visite '.$i,
                'diagnostic' => 'Diag '.$i,
            ]);
        }

        $short = $this->builder()->build($this->patient, $this->consultation);
        $full = $this->builder()->build($this->patient, $this->consultation, full: true);

        $this->assertStringContainsString('CONSULTATIONS PRÉCÉDENTES', $short);
        $this->assertSame(6, substr_count($short, 'Motif : Visite '));
        $this->assertSame(15, substr_count($full, 'Motif : Visite '));
        // Most recent first.
        $this->assertLessThan(strpos($short, 'Visite 2 '), strpos($short, 'Visite 1 '));
        // The visit being written is described once, as the current one.
        $this->assertSame(1, substr_count($short, 'Fièvre et toux'));
    }

    public function test_prescriptions_list_their_medications_and_dosage(): void
    {
        Prescription::query()->create([
            'patient_id' => $this->patient->getKey(),
            'prescribed_at' => now()->subDays(10),
            'items' => [
                ['medication' => 'Amlor 5 mg', 'dosage' => '1 cp/j'],
                ['medication' => 'Doliprane 1 g', 'dosage' => ''],
                ['dosage' => 'orphan'],
            ],
        ]);
        Prescription::query()->create([
            'patient_id' => $this->patient->getKey(),
            'prescribed_at' => now()->subDays(20),
            'items' => [],
        ]);

        $text = $this->builder()->build($this->patient);

        $this->assertStringContainsString('ORDONNANCES RÉCENTES', $text);
        $this->assertStringContainsString('Amlor 5 mg (1 cp/j), Doliprane 1 g, (orphan)', $text);
        $this->assertStringContainsString(now()->subDays(20)->format('d/m/Y').' : —', $text);
    }

    public function test_measurements_show_only_recorded_values(): void
    {
        PatientMeasurement::query()->create([
            'patient_id' => $this->patient->getKey(),
            'measured_at' => now()->subDay(),
            'weight_kg' => 70.5,
            'height_cm' => null,
            'bmi' => 24.1,
        ]);

        $text = $this->builder()->build($this->patient);

        $this->assertStringContainsString('MESURES', $text);
        $this->assertMatchesRegularExpression('/poids 70\.50? kg/', $text);
        $this->assertMatchesRegularExpression('/IMC 24\.10?/', $text);
        $this->assertStringNotContainsString('taille', $text);
    }

    public function test_only_a_validated_ecg_conclusion_counts_as_a_finding(): void
    {
        EcgRecord::query()->create([
            'patient_id' => $this->patient->getKey(),
            'title' => 'ECG signé',
            'recorded_at' => now()->subDays(2),
            'file_path' => 'x.png',
            'status' => EcgRecord::STATUS_VALIDATED,
            'doctor_conclusion' => '<p>Rythme sinusal normal</p>',
            'measurements' => ['heart_rate_bpm' => 72],
        ]);
        EcgRecord::query()->create([
            'patient_id' => $this->patient->getKey(),
            'title' => 'ECG brouillon',
            'recorded_at' => now()->subDays(1),
            'file_path' => 'y.png',
            'status' => EcgRecord::STATUS_DRAFT,
            'analysis' => ['primary_statement' => 'Fibrillation atriale probable'],
        ]);
        EcgRecord::query()->create([
            'patient_id' => $this->patient->getKey(),
            'title' => 'ECG vierge',
            'recorded_at' => now()->subDays(3),
            'file_path' => 'z.png',
            'status' => EcgRecord::STATUS_DRAFT,
        ]);

        $text = $this->builder()->build($this->patient);

        $this->assertStringContainsString('ECG signé (FC mesurée 72/min) — conclusion du médecin : Rythme sinusal normal', $text);
        $this->assertStringContainsString('ECG brouillon — lecture IA non validée : Fibrillation atriale probable', $text);
        $this->assertStringContainsString('ECG vierge — non interprété', $text);
    }

    public function test_documents_prefer_their_ai_analysis_summary(): void
    {
        $analysed = Document::query()->create([
            'patient_id' => $this->patient->getKey(),
            'category' => 'uploaded',
            'title' => 'Bilan sanguin',
        ]);
        AiInsight::query()->create([
            'patient_id' => $this->patient->getKey(),
            'document_id' => $analysed->getKey(),
            'kind' => AiInsight::KIND_DOCUMENT_ANALYSIS,
            'content' => ['summary' => 'Anémie modérée'],
        ]);
        Document::query()->create([
            'patient_id' => $this->patient->getKey(),
            'category' => 'certificate',
            'title' => 'Certificat',
            'content' => '<p>Apte au sport</p>',
        ]);
        Document::query()->create([
            'patient_id' => $this->patient->getKey(),
            'category' => 'uploaded',
            'title' => 'Radio thorax',
        ]);

        $text = $this->builder()->build($this->patient);

        $this->assertStringContainsString('DOCUMENTS DU DOSSIER', $text);
        $this->assertStringContainsString('[uploaded] Bilan sanguin — analyse : Anémie modérée', $text);
        $this->assertStringContainsString('[certificate] Certificat — Apte au sport', $text);
        $this->assertMatchesRegularExpression('/\[uploaded\] Radio thorax$/m', $text);
    }

    public function test_another_patients_records_are_never_included(): void
    {
        $other = Patient::factory()->create();
        Consultation::query()->create(['patient_id' => $other->getKey(), 'consulted_at' => now()->subDay(), 'status' => 'completed', 'motif' => 'Secret voisin']);
        Prescription::query()->create(['patient_id' => $other->getKey(), 'prescribed_at' => now(), 'items' => [['medication' => 'Médicament voisin']]]);

        $text = $this->builder()->build($this->patient, full: true);

        $this->assertStringNotContainsString('Secret voisin', $text);
        $this->assertStringNotContainsString('Médicament voisin', $text);
    }

    public function test_a_huge_dossier_is_truncated_to_fit_the_prompt(): void
    {
        foreach (range(1, 15) as $i) {
            Consultation::query()->create([
                'patient_id' => $this->patient->getKey(),
                'consulted_at' => now()->subDays($i),
                'status' => 'completed',
                'motif' => str_repeat('motif long ', 200),
                'diagnostic' => str_repeat('diagnostic long ', 200),
                'traitement' => str_repeat('traitement long ', 200),
            ]);
        }
        $this->patient->update(array_fill_keys(['antecedents_medical', 'antecedents_surgical', 'antecedents_family', 'antecedents_other'], str_repeat('antécédent ', 500)));

        $text = $this->builder()->build($this->patient->fresh(), $this->consultation, full: true);

        $this->assertLessThanOrEqual(14000 + mb_strlen("\n[…dossier tronqué]"), mb_strlen($text));
        $this->assertStringEndsWith('[…dossier tronqué]', $text);
    }

    public function test_plain_strips_html_and_normalises_whitespace(): void
    {
        $this->assertSame(
            "Ligne 1\nLigne 2\n\nLigne 3 & co",
            $this->builder()->plain("<p>Ligne   1</p><p>Ligne\t2</p>\n\n\n<ul><li>Ligne 3 &amp; co</li></ul>"),
        );
        $this->assertSame("a\nb", $this->builder()->plain('a<br>b'));
        $this->assertSame('', $this->builder()->plain('<p> </p>'));
    }

    public function test_a_soft_deleted_patient_can_still_be_described(): void
    {
        $this->patient->delete();

        $text = $this->builder()->build(Patient::withTrashed()->findOrFail($this->patient->getKey()));

        $this->assertStringContainsString('PATIENT', $text);
    }
}
