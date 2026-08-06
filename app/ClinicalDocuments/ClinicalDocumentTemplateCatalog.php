<?php

namespace App\ClinicalDocuments;

use App\Models\BilanType;
use App\Models\Exam;

final class ClinicalDocumentTemplateCatalog
{
    /**
     * @return list<array{
     *     key: string,
     *     category: string,
     *     group: string,
     *     title: string,
     *     body: string,
     *     default_paper_size: string
     * }>
     */
    public function templates(?string $category = null): array
    {
        $templates = [...$this->builtIns(), ...$this->configuredBilans()];

        if ($category === null) {
            return $templates;
        }

        return array_values(array_filter(
            $templates,
            static fn (array $template): bool => $template['category'] === $category,
        ));
    }

    /**
     * @return array{
     *     key: string,
     *     category: string,
     *     group: string,
     *     title: string,
     *     body: string,
     *     default_paper_size: string
     * }|null
     */
    public function find(string $key): ?array
    {
        foreach ($this->templates() as $template) {
            if ($template['key'] === $key) {
                return $template;
            }
        }

        return null;
    }

    /**
     * @return list<array{
     *     key: string,
     *     category: string,
     *     group: string,
     *     title: string,
     *     body: string,
     *     default_paper_size: string
     * }>
     */
    private function builtIns(): array
    {
        return [
            $this->template(
                'ordonnance',
                'ordonnance',
                'Ordonnances',
                'Ordonnance',
                <<<'TEXT'
## Traitement prescrit
{{prescription.items}}

## Notes
{{prescription.notes}}
TEXT,
                'A5',
            ),
            $this->template(
                'bilan',
                'bilan',
                'Bilans',
                'Bilan',
                <<<'TEXT'
## Examens demandés
{{consultation.examens}}

## Renseignements cliniques
{{consultation.diagnostic}}
TEXT,
            ),
            $this->template(
                'arret-travail',
                'courrier',
                'Arrêt de travail',
                'Arrêt de travail',
                <<<'TEXT'
Je soussigné(e), Dr {{doctor.name}}, {{doctor.specialty}}, certifie avoir examiné ce jour {{patient.full_name}}, né(e) le {{patient.date_of_birth}}.

Son état de santé nécessite un arrêt de travail de ______ jour(s), à compter du {{document.date}}.

Motif médical : {{consultation.diagnostic}}

Certificat établi à la demande de l'intéressé(e) pour servir et valoir ce que de droit.
TEXT,
            ),
            $this->template(
                'certificat-aptitude',
                'courrier',
                'Certificats',
                "Certificat d'aptitude",
                <<<'TEXT'
Je soussigné(e), Dr {{doctor.name}}, {{doctor.specialty}}, certifie avoir examiné ce jour {{patient.full_name}}, né(e) le {{patient.date_of_birth}}, âgé(e) de {{patient.age}} ans.

À l'issue de cet examen, je certifie que l'intéressé(e) est apte à : ________________________________

Certificat établi à la demande de l'intéressé(e) pour servir et valoir ce que de droit.
TEXT,
            ),
            $this->template(
                'certificat-bonne-sante',
                'courrier',
                'Certificats',
                'Certificat de bonne santé',
                <<<'TEXT'
Je soussigné(e), Dr {{doctor.name}}, {{doctor.specialty}}, certifie avoir examiné ce jour {{patient.full_name}}, né(e) le {{patient.date_of_birth}}, âgé(e) de {{patient.age}} ans.

À l'issue de cet examen clinique, je certifie que l'intéressé(e) est en bonne santé apparente et ne présente pas de contre-indication médicale connue.

Certificat établi à la demande de l'intéressé(e) pour servir et valoir ce que de droit.
TEXT,
            ),
            $this->template(
                'certificat-dispense-sportive',
                'courrier',
                'Certificats',
                'Certificat de dispense sportive',
                <<<'TEXT'
Je soussigné(e), Dr {{doctor.name}}, {{doctor.specialty}}, certifie que {{patient.full_name}}, né(e) le {{patient.date_of_birth}}, présente un état de santé nécessitant une dispense de la pratique sportive.

Durée de la dispense : ________________________________

Certificat délivré à la demande de l'intéressé(e) pour servir et valoir ce que de droit.
TEXT,
            ),
            $this->template(
                'certificat-grossesse',
                'courrier',
                'Certificats',
                'Certificat de grossesse',
                <<<'TEXT'
Je soussigné(e), Dr {{doctor.name}}, {{doctor.specialty}}, certifie que {{patient.full_name}}, née le {{patient.date_of_birth}}, est enceinte de ______ semaines d'aménorrhée.

Date présumée d'accouchement : ________________________________

Certificat établi à la demande de l'intéressée pour servir et valoir ce que de droit.
TEXT,
            ),
            $this->template(
                'certificat-non-contre-indication',
                'courrier',
                'Certificats',
                'Certificat de non contre-indication',
                <<<'TEXT'
Je soussigné(e), Dr {{doctor.name}}, {{doctor.specialty}}, certifie avoir examiné ce jour {{patient.full_name}}, né(e) le {{patient.date_of_birth}}.

Je n'ai constaté aucun signe clinique apparent contre-indiquant la pratique de l'activité physique et sportive.

Ce certificat est valable pour une durée de ________________________________.
TEXT,
            ),
            $this->template(
                'certificat-reprise',
                'courrier',
                'Certificats',
                'Certificat de reprise',
                <<<'TEXT'
Je soussigné(e), Dr {{doctor.name}}, {{doctor.specialty}}, certifie que l'état de santé de {{patient.full_name}}, âgé(e) de {{patient.age}} ans, lui permet de reprendre son activité professionnelle, scolaire ou sportive à compter du {{document.date}}.

Certificat établi à la demande de l'intéressé(e) pour servir et valoir ce que de droit.
TEXT,
            ),
            $this->template(
                'certificat-medical-simple',
                'courrier',
                'Certificats',
                'Certificat médical simple',
                <<<'TEXT'
Je soussigné(e), Dr {{doctor.name}}, {{doctor.specialty}}, certifie avoir examiné ce jour {{patient.full_name}}, né(e) le {{patient.date_of_birth}}, âgé(e) de {{patient.age}} ans.

Constatations : {{consultation.diagnostic}}

Certificat délivré à l'intéressé(e) pour servir et valoir ce que de droit.
TEXT,
            ),
            $this->template(
                'compte-rendu-consultation',
                'courrier',
                'Comptes rendus',
                'Compte rendu de consultation',
                <<<'TEXT'
Patient : {{patient.full_name}} — {{patient.age}} ans — né(e) le {{patient.date_of_birth}}

## Motif de consultation
{{consultation.motif}}

## Examen clinique
{{consultation.examens}}

## Diagnostic
{{consultation.diagnostic}}

## Traitement et conduite à tenir
{{consultation.traitement}}
TEXT,
            ),
            $this->template(
                'courrier-medical',
                'courrier',
                'Courrier libre',
                'Courrier médical',
                <<<'TEXT'
Cher(e) Confrère / Consœur,

Je vous adresse {{patient.full_name}}, âgé(e) de {{patient.age}} ans, pour avis et prise en charge.

## Motif
{{consultation.motif}}

## Diagnostic
{{consultation.diagnostic}}

Confraternellement,

Dr {{doctor.name}}
TEXT,
            ),
            $this->template(
                'lettre-confrere',
                'courrier',
                'Courrier libre',
                'Lettre au confrère',
                <<<'TEXT'
Cher Confrère,

Je vous adresse {{patient.full_name}}, âgé(e) de {{patient.age}} ans, pour avis et prise en charge concernant :

{{consultation.diagnostic}}

## Traitement actuel
{{consultation.traitement}}

En espérant votre bienveillant concours, je vous adresse mes cordiales salutations confraternelles.

Dr {{doctor.name}}
TEXT,
            ),
            $this->template(
                'lettre-orientation',
                'courrier',
                "Lettre d'orientation",
                "Lettre d'orientation",
                <<<'TEXT'
Cher(e) Confrère / Consœur,

Je vous adresse {{patient.full_name}}, né(e) le {{patient.date_of_birth}}, âgé(e) de {{patient.age}} ans, pour une prise en charge spécialisée.

## Motif d'orientation
{{consultation.diagnostic}}

## Antécédents et traitement en cours
{{consultation.traitement}}

Confraternellement,

Dr {{doctor.name}}
TEXT,
            ),
            $this->template(
                'compte-rendu-cardio',
                'courrier',
                'Comptes rendus',
                'Compte rendu cardiologique',
                <<<'TEXT'
## Motif de consultation
{{consultation.motif}}

## Examen clinique et examens complémentaires
{{consultation.examens}}

## Diagnostic
{{consultation.diagnostic}}

## Conclusion et conduite à tenir
{{consultation.traitement}}
TEXT,
            ),
            $this->template(
                'lettre-hospitalisation',
                'courrier',
                'Comptes rendus',
                "Lettre d'hospitalisation",
                <<<'TEXT'
Cher(e) Confrère / Consœur,

Je vous adresse {{patient.full_name}}, âgé(e) de {{patient.age}} ans, pour hospitalisation et prise en charge de :

{{consultation.diagnostic}}

Traitement actuel : {{consultation.traitement}}
TEXT,
            ),
            $this->template(
                'rapport-ecg',
                'courrier',
                'Comptes rendus',
                'Rapport ECG',
                <<<'TEXT'
## Indication
{{consultation.motif}}

## Interprétation
{{consultation.examens}}

## Conclusion
{{consultation.diagnostic}}
TEXT,
            ),
            $this->template(
                'rapport-echocardiographie',
                'courrier',
                'Comptes rendus',
                'Rapport échocardiographie',
                <<<'TEXT'
## Indication
{{consultation.motif}}

## Résultats
{{consultation.examens}}

## Conclusion
{{consultation.diagnostic}}
TEXT,
            ),
        ];
    }

    /**
     * @return list<array{
     *     key: string,
     *     category: string,
     *     group: string,
     *     title: string,
     *     body: string,
     *     default_paper_size: string
     * }>
     */
    private function configuredBilans(): array
    {
        $bilanTypes = BilanType::query()
            ->where('is_active', true)
            ->orderBy('category')
            ->orderBy('name')
            ->get()
            ->map(fn (BilanType $type): array => $this->template(
                'bilan-type-'.$type->getKey(),
                'bilan',
                $type->category ?: 'Types de bilans',
                $type->name,
                "## Type de bilan\n{$type->name}\n\n## Renseignements cliniques\n{{consultation.diagnostic}}",
            ));

        $exams = Exam::query()
            ->where('is_active', true)
            ->orderBy('category')
            ->orderBy('name')
            ->get()
            ->map(fn (Exam $exam): array => $this->template(
                'exam-'.$exam->getKey(),
                'bilan',
                $exam->category ?: 'Examens',
                $exam->name,
                "## Examen demandé\n{$exam->name}\n\n## Renseignements cliniques\n{{consultation.diagnostic}}",
            ));

        return [...$bilanTypes->all(), ...$exams->all()];
    }

    /**
     * @return array{
     *     key: string,
     *     category: string,
     *     group: string,
     *     title: string,
     *     body: string,
     *     default_paper_size: string
     * }
     */
    private function template(
        string $key,
        string $category,
        string $group,
        string $title,
        string $body,
        string $paperSize = 'A4',
    ): array {
        return [
            'key' => $key,
            'category' => $category,
            'group' => $group,
            'title' => $title,
            'body' => $body,
            'default_paper_size' => $paperSize,
        ];
    }
}
