<?php

namespace App\Http\Controllers\Consultations;

use App\Http\Controllers\Controller;
use App\Models\AccountingSetting;
use App\Models\Consultation;
use App\Models\Document;
use App\Models\Patient;
use App\Models\Prescription;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only "historique des consultations" for a patient (requirement #3).
 *
 * Everything here stays inside the cabinet global scope: the models use the
 * BelongsToCabinet trait and we never call withoutCabinetScope(), so tenancy is
 * preserved end to end.
 */
class ConsultationHistoryController extends Controller
{
    /**
     * Reverse-chronological timeline of every consultation for a patient.
     */
    public function index(Patient $patient): Response
    {
        $this->authorize('view', $patient);

        $currency = AccountingSetting::current()->currency ?? 'DA';

        $consultations = Consultation::query()
            ->with('createdBy:id,name')
            // Feeds collectedMinor()/outstandingMinor() from one query instead
            // of a payments round trip per row.
            ->withSum('payments', 'amount_minor')
            ->where('patient_id', $patient->getKey())
            ->orderByDesc('consulted_at')
            ->get();

        return Inertia::render('consultations/History', [
            'patient' => [
                'id' => $patient->id,
                'patient_number' => $patient->patient_number,
                'full_name' => $patient->full_name,
            ],
            'currency' => $currency,
            'summary' => $this->summary($consultations),
            'consultations' => $consultations
                ->map(fn (Consultation $consultation): array => $this->timelineRow($consultation))
                ->values()
                ->all(),
        ]);
    }

    /**
     * Full detail of a single consultation: filled clinical fields,
     * ordonnances, generated documents and the price breakdown.
     */
    public function show(Request $request, Consultation $consultation): Response
    {
        $consultation->load(['patient', 'createdBy:id,name']);

        /** @var Patient $patient */
        $patient = $consultation->patient;

        $this->authorize('view', $patient);

        $currency = AccountingSetting::current()->currency ?? 'DA';
        $canEdit = $request->user()?->can('consultations.update') ?? false;

        $prescriptions = Prescription::query()
            ->with('document:id,title,category')
            ->where('consultation_id', $consultation->getKey())
            ->orderByDesc('prescribed_at')
            ->get()
            ->map(fn (Prescription $prescription): array => [
                'id' => $prescription->id,
                'prescribed_at' => $prescription->prescribed_at?->toIso8601String(),
                'prescribed_at_label' => $prescription->prescribed_at?->format('d/m/Y'),
                'notes' => $prescription->notes,
                'items' => $prescription->items ?? [],
                'document_id' => $prescription->document_id,
                'document_title' => $prescription->document?->title,
                'download_url' => $this->documentDownloadUrl($prescription->document),
            ])
            ->values()
            ->all();

        // Generated clinical documents (certificats, courriers, bilans…) tied
        // to this visit, plus files uploaded during it. "uploaded" documents
        // are the scanned/imported files.
        $documents = Document::query()
            ->where('consultation_id', $consultation->getKey())
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Document $document): array => [
                'id' => $document->id,
                'category' => $document->category,
                'title' => $document->title,
                'original_filename' => $document->original_filename,
                'mime_type' => $document->mime_type,
                'file_size' => $document->file_size,
                'created_at' => $document->created_at?->toIso8601String(),
                'is_uploaded' => $document->category === 'uploaded',
                'download_url' => $this->documentDownloadUrl($document),
            ])
            ->values()
            ->all();

        $amount = $consultation->payment_amount_minor !== null
            ? $consultation->payment_amount_minor / 100
            : null;

        return Inertia::render('consultations/HistoryDetail', [
            'currency' => $currency,
            'canEdit' => $canEdit,
            'patient' => [
                'id' => $patient->id,
                'patient_number' => $patient->patient_number,
                'full_name' => $patient->full_name,
                'allergies' => $patient->allergies,
                'antecedents_medical' => $patient->antecedents_medical,
                'antecedents_surgical' => $patient->antecedents_surgical,
                'antecedents_family' => $patient->antecedents_family,
                'antecedents_other' => $patient->antecedents_other,
            ],
            'consultation' => [
                'id' => $consultation->id,
                'status' => $consultation->status,
                'consulted_at' => $consultation->consulted_at?->toIso8601String(),
                'consulted_at_label' => $consultation->consulted_at?->format('d/m/Y H:i'),
                'completed_at' => $consultation->completed_at?->toIso8601String(),
                'provider_name' => $consultation->createdBy?->name,
                // Filled clinical fields.
                'motif' => $consultation->motif,
                'examens' => $consultation->examens,
                'diagnostic' => $consultation->diagnostic,
                'traitement' => $consultation->traitement,
                'notes' => $consultation->notes,
                // Measurements recorded on the visit.
                'weight_kg' => $consultation->weight_kg,
                'height_cm' => $consultation->height_cm,
                'temperature_c' => $consultation->temperature_c,
                'blood_pressure' => $consultation->blood_pressure,
                // Price / payment breakdown.
                'payment_amount' => $amount,
                'payment_method' => $consultation->payment_method,
                'payment_service' => $consultation->payment_service,
                'is_paid' => $consultation->is_paid,
            ],
            'prescriptions' => $prescriptions,
            'documents' => $documents,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function timelineRow(Consultation $consultation): array
    {
        $amount = $consultation->payment_amount_minor !== null
            ? $consultation->payment_amount_minor / 100
            : null;

        return [
            'id' => $consultation->id,
            'consulted_at' => $consultation->consulted_at?->toIso8601String(),
            'date_key' => $consultation->consulted_at?->toDateString(),
            'time_label' => $consultation->consulted_at?->format('H:i'),
            'status' => $consultation->status,
            'provider_name' => $consultation->createdBy?->name,
            'motif' => $consultation->motif,
            'diagnostic' => $consultation->diagnostic,
            // Billed vs actually collected: `payment_amount` is the price, and
            // `payment_paid` the sum of the ledger entries behind it.
            'payment_amount' => $amount,
            'payment_paid' => $consultation->collectedMinor() / 100,
            'payment_outstanding' => $consultation->outstandingMinor() / 100,
            'payment_status' => $consultation->paymentStatus(),
            'payment_method' => $consultation->payment_method,
            'is_paid' => $consultation->is_paid,
        ];
    }

    /**
     * What the patient has been billed, has actually paid and still owes over
     * the whole timeline — the same billed/collected/outstanding definitions
     * the payments screen uses, so the two never disagree.
     *
     * @param  EloquentCollection<int, Consultation>  $consultations
     * @return array<string, mixed>
     */
    private function summary(EloquentCollection $consultations): array
    {
        return [
            'count' => $consultations->count(),
            'billed' => $consultations->sum(
                fn (Consultation $consultation): int => (int) ($consultation->payment_amount_minor ?? 0),
            ) / 100,
            'paid' => $consultations->sum(
                fn (Consultation $consultation): int => $consultation->collectedMinor(),
            ) / 100,
            'outstanding' => $consultations->sum(
                fn (Consultation $consultation): int => $consultation->outstandingMinor(),
            ) / 100,
            // The collection is already ordered newest first.
            'last_visit' => $consultations->first()?->consulted_at?->toIso8601String(),
        ];
    }

    private function documentDownloadUrl(?Document $document): ?string
    {
        if (! $document instanceof Document) {
            return null;
        }

        return URL::temporarySignedRoute(
            'clinical-documents.file',
            now()->addDay(),
            ['document' => $document->getKey()],
            false,
        );
    }
}
