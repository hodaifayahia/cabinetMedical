<?php

namespace App\ClinicalDocuments;

use App\Models\BilanType;
use App\Models\CabinetSetting;
use App\Models\Consultation;
use App\Models\Document;
use App\Models\Exam;
use App\Models\Patient;
use App\Models\User;
use App\Services\DocumentBrandingService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class ClinicalDocumentManager
{
    public function __construct(
        private readonly ClinicalDocumentTemplateCatalog $catalog,
        private readonly DocxDocumentBuilder $builder,
        private readonly DocumentBrandingService $documentBranding,
    ) {}

    /**
     * @param  array{
     *     source: string,
     *     category: string,
     *     paper_size: string,
     *     template_key?: string|null,
     *     title?: string|null
     * }  $selection
     * @param  array<string, mixed>  $context
     */
    public function create(
        Consultation $consultation,
        User $user,
        array $selection,
        array $context = [],
    ): Document {
        $consultation->loadMissing('patient');

        /** @var Patient $patient */
        $patient = $consultation->patient;
        $category = $selection['category'];
        $paperSize = strtoupper($selection['paper_size']) === 'A5' ? 'A5' : 'A4';
        $source = $selection['source'];
        $templateKey = null;
        $body = '';

        $template = $this->catalog->find((string) ($selection['template_key'] ?? ''));

        if ($source !== 'built_in' || $template === null || $template['category'] !== $category) {
            throw new RuntimeException('The selected clinical document template is invalid.');
        }

        $title = trim((string) ($selection['title'] ?? '')) ?: $template['title'];
        $templateKey = $template['key'];
        $body = $template['body'];

        $filename = (Str::slug($title) ?: 'document').'-'.now()->format('Ymd-His').'.docx';
        $path = 'clinical-documents/'.$patient->getKey().'/'.Str::uuid().'.docx';
        $variables = $this->variables($consultation, $context);

        Storage::makeDirectory(dirname($path));

        try {
            $this->builder->build(
                Storage::path($path),
                $title,
                $body,
                $variables,
                $paperSize,
            );

            return Document::query()->create([
                'patient_id' => $patient->getKey(),
                'consultation_id' => $consultation->getKey(),
                'category' => $category,
                'title' => $title,
                'template_key' => $templateKey,
                'paper_size' => $paperSize,
                'content' => null,
                'file_path' => $path,
                'original_filename' => $filename,
                'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'file_size' => Storage::size($path),
                'file_version' => 1,
                'created_by' => $user->getKey(),
            ]);
        } catch (Throwable $exception) {
            Storage::delete($path);

            throw $exception;
        }
    }

    public function convertLegacy(
        Document $document,
        Consultation $consultation,
        string $paperSize,
    ): Document {
        if ($document->file_path !== null) {
            return $document;
        }

        $path = 'clinical-documents/'.$document->patient_id.'/'.Str::uuid().'.docx';
        $html = (string) $document->content;
        $html = (string) preg_replace('/<(br|\/p|\/div|\/h[1-6])\b[^>]*>/i', "\n", $html);
        $body = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $paperSize = strtoupper($paperSize) === 'A5' ? 'A5' : 'A4';

        Storage::makeDirectory(dirname($path));

        try {
            $this->builder->build(
                Storage::path($path),
                $document->title,
                $body,
                $this->variables($consultation),
                $paperSize,
            );

            $document->update([
                'paper_size' => $paperSize,
                'file_path' => $path,
                'original_filename' => (Str::slug($document->title) ?: 'document').'.docx',
                'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'file_size' => Storage::size($path),
                'file_version' => 1,
            ]);

            return $document->refresh();
        } catch (Throwable $exception) {
            Storage::delete($path);

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, string>
     */
    private function variables(Consultation $consultation, array $context = []): array
    {
        $consultation->loadMissing('patient');

        /** @var Patient $patient */
        $patient = $consultation->patient;
        $cabinet = CabinetSetting::current();
        $branding = $this->documentBranding->identity(cabinet: $cabinet);
        $age = $patient->date_of_birth?->age;
        $rawPrescriptionItems = $context['prescription_items'] ?? [];
        /** @var array<array-key, mixed> $prescriptionItemRows */
        $prescriptionItemRows = is_array($rawPrescriptionItems) ? $rawPrescriptionItems : [];
        $prescriptionItems = collect($prescriptionItemRows)
            ->values()
            ->map(function (mixed $item, int $index): string {
                if (! is_array($item)) {
                    return '';
                }

                $parts = array_filter([
                    trim((string) ($item['medication'] ?? '')),
                    trim((string) ($item['dosage'] ?? '')),
                    trim((string) ($item['duration'] ?? '')),
                ]);
                $line = ($index + 1).'. '.implode(' — ', $parts);
                $instructions = trim((string) ($item['instructions'] ?? ''));

                return $instructions === '' ? $line : $line."\n   ".$instructions;
            })
            ->filter()
            ->implode("\n\n");

        $variables = [
            'patient.full_name' => $patient->full_name,
            'patient.first_name' => (string) $patient->first_name,
            'patient.last_name' => (string) $patient->last_name,
            'patient.patient_number' => (string) $patient->patient_number,
            'patient.date_of_birth' => $patient->date_of_birth?->format('d/m/Y') ?? '',
            'patient.age' => $age === null ? '' : (string) $age,
            'patient.gender' => $patient->gender->value ?? '',
            'patient.marital_status' => (string) ($patient->marital_status ?? ''),
            'patient.profession' => (string) ($patient->profession ?? ''),
            'patient.phone' => (string) ($patient->phone ?? ''),
            'patient.secondary_phone' => (string) ($patient->secondary_phone ?? ''),
            'patient.email' => (string) ($patient->email ?? ''),
            'patient.address' => (string) ($patient->address ?? ''),
            'patient.city' => (string) ($patient->city ?? ''),
            'patient.blood_group' => $patient->blood_group->value ?? '',
            'patient.allergies' => (string) ($patient->allergies ?? ''),
            'patient.notes' => (string) ($patient->notes ?? ''),
            'cabinet.name' => $branding['clinic_name'],
            'cabinet.address' => $branding['full_address'],
            'cabinet.city' => $branding['city'],
            'cabinet.phone' => $branding['phone'],
            'cabinet.email' => $branding['email'],
            'cabinet.logo_path' => (string) ($branding['logo_path'] ?? ''),
            'cabinet.footer_extra_line' => $branding['footer_extra_line'],
            'cabinet.footer' => $branding['footer'],
            'cabinet.currency_code' => (string) $cabinet->currency_code,
            'cabinet.timezone' => (string) $cabinet->timezone,
            'cabinet.default_appointment_duration' => (string) $cabinet->default_appointment_duration,
            'cabinet.default_consultation_fee' => number_format($cabinet->default_consultation_fee_minor / 100, 2, '.', ''),
            'cabinet.receipt_footer' => $branding['receipt_footer'],
            'cabinet.prescription_footer' => $branding['footer_extra_line'],
            'doctor.name' => $branding['doctor_name'],
            'doctor.specialty' => $branding['specialty'],
            'doctor.order_number' => $branding['medical_order_number'],
            'consultation.motif' => (string) ($consultation->motif ?? ''),
            'consultation.examens' => (string) ($consultation->examens ?? ''),
            'consultation.diagnostic' => (string) ($consultation->diagnostic ?? ''),
            'consultation.traitement' => (string) ($consultation->traitement ?? ''),
            'consultation.notes' => (string) ($consultation->notes ?? ''),
            'document.date' => now()->format('d/m/Y'),
            'document.date_long' => now()->translatedFormat('d F Y'),
            'prescription.items' => $prescriptionItems,
            'prescription.notes' => trim((string) ($context['prescription_notes'] ?? '')),
        ];

        BilanType::query()->get()->each(function (BilanType $type) use (&$variables): void {
            $variables['lab_report_types.'.$type->getKey()] = $type->name;
        });
        Exam::query()->get()->each(function (Exam $exam) use (&$variables): void {
            $variables['exams.'.$exam->getKey()] = $exam->name;
        });

        return $variables;
    }
}
