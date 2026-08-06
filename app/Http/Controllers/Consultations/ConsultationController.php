<?php

namespace App\Http\Controllers\Consultations;

use App\Actions\Appointments\CreateAppointmentAction;
use App\ClinicalDocuments\ClinicalDocumentManager;
use App\ClinicalDocuments\ClinicalDocumentOnlyOffice;
use App\ClinicalDocuments\ClinicalDocumentTemplateCatalog;
use App\ClinicalDocuments\ClinicalHtmlSanitizer;
use App\Enums\AppointmentStatus;
use App\Enums\BloodGroup;
use App\Enums\Gender;
use App\Http\Controllers\Controller;
use App\Models\Act;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\BilanType;
use App\Models\Consultation;
use App\Models\ConsultationFee;
use App\Models\Document;
use App\Models\Exam;
use App\Models\Medication;
use App\Models\Patient;
use App\Models\PatientMeasurement;
use App\Models\PaymentMethod;
use App\Models\Prescription;
use App\Models\User;
use App\Services\DocumentBrandingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ConsultationController extends Controller
{
    /**
     * Today's patients: appointments scheduled for today, ready to be consulted.
     */
    public function index(Request $request): Response
    {
        $today = CarbonImmutable::now()->startOfDay();

        $appointments = Appointment::query()
            ->with('patient:id,first_name,last_name,patient_number')
            ->whereDate('appointment_date', $today->toDateString())
            ->where('status', '!=', AppointmentStatus::CANCELLED->value)
            ->orderBy('starts_at')
            ->get();

        $consultations = Consultation::query()
            ->whereIn('appointment_id', $appointments->pluck('id'))
            ->get()
            ->keyBy('appointment_id');

        return Inertia::render('consultations/Today', [
            'date' => $today->toDateString(),
            'appointments' => $appointments->map(fn (Appointment $appointment): array => [
                'id' => $appointment->id,
                'time' => $appointment->starts_at?->format('H:i'),
                'patient_name' => $appointment->patient?->full_name,
                'patient_number' => $appointment->patient?->patient_number,
                'status' => $appointment->status->value,
                'reason' => $appointment->reason,
                'consultation_id' => $consultations->get($appointment->id)?->id,
                'consultation_status' => $consultations->get($appointment->id)?->status,
            ])->all(),
            'canStart' => $request->user()?->can('consultations.create') ?? false,
        ]);
    }

    /**
     * Start (or resume) the consultation for an appointment.
     */
    public function start(Appointment $appointment): RedirectResponse
    {
        $consultation = Consultation::query()->firstOrCreate(
            ['appointment_id' => $appointment->getKey()],
            [
                'patient_id' => $appointment->patient_id,
                'consulted_at' => now(),
                'status' => 'in_progress',
                'created_by' => auth()->id(),
            ],
        );

        if (in_array($appointment->status, [AppointmentStatus::SCHEDULED, AppointmentStatus::CONFIRMED, AppointmentStatus::CHECKED_IN], true)) {
            $appointment->update([
                'status' => AppointmentStatus::IN_PROGRESS,
                'checked_in_at' => $appointment->checked_in_at ?? now(),
                'started_at' => $appointment->started_at ?? now(),
            ]);
        }

        return to_route('app.consultations.show', $consultation);
    }

    /**
     * The consultation workspace (full patient dossier) for a single visit.
     */
    public function show(
        Request $request,
        Consultation $consultation,
        ClinicalDocumentTemplateCatalog $templateCatalog,
        ClinicalDocumentOnlyOffice $onlyOffice,
        DocumentBrandingService $documentBranding,
    ): Response {
        $consultation->load('patient');

        /** @var Patient $patient */
        $patient = $consultation->patient;

        $history = Consultation::query()
            ->where('patient_id', $patient->getKey())
            ->whereKeyNot($consultation->getKey())
            ->orderByDesc('consulted_at')
            ->limit(30)
            ->get()
            ->map(fn (Consultation $item): array => [
                'id' => $item->id,
                'consulted_at' => $item->consulted_at?->toDateString(),
                'motif' => $item->motif,
                'diagnostic' => $item->diagnostic,
                'status' => $item->status,
            ])->all();

        $appointments = Appointment::query()
            ->where('patient_id', $patient->getKey())
            ->orderByDesc('starts_at')
            ->limit(30)
            ->get();

        $now = CarbonImmutable::now();
        $branding = $documentBranding->renderingIdentity();
        $canEdit = $request->user()?->can('consultations.update') ?? false;
        $documents = Document::query()
            ->where('patient_id', $patient->getKey())
            ->where('category', '!=', 'uploaded')
            ->orderByDesc('created_at')
            ->limit(80)
            ->get()
            ->map(fn (Document $document): array => $onlyOffice->payload($document, $request, $canEdit))
            ->all();
        $uploadedFiles = Document::query()
            ->where('patient_id', $patient->getKey())
            ->where('category', 'uploaded')
            ->orderByDesc('created_at')
            ->limit(80)
            ->get()
            ->map(fn (Document $document): array => [
                'id' => $document->getKey(),
                'title' => $document->title,
                'original_filename' => $document->original_filename,
                'mime_type' => $document->mime_type,
                'file_size' => $document->file_size,
                'created_at' => $document->created_at?->toIso8601String(),
                'download_url' => URL::temporarySignedRoute(
                    'clinical-documents.file',
                    now()->addDay(),
                    ['document' => $document->getKey()],
                    false,
                ),
            ])
            ->values()
            ->all();
        $builtInTemplates = collect($templateCatalog->templates())
            ->map(fn (array $template): array => [
                'source' => 'built_in',
                'key' => $template['key'],
                'category' => $template['category'],
                'group' => $template['group'],
                'title' => $template['title'],
                'description' => null,
                'body' => $template['body'],
                'default_paper_size' => $template['default_paper_size'],
            ]);
        $bilanCategories = BilanType::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['name', 'description', 'category']);
        $legacyCategoryNames = $bilanCategories
            ->filter(fn (BilanType $category): bool => filled($category->category))
            ->pluck('name', 'category');

        return Inertia::render('consultations/Workspace', [
            'consultation' => [
                'id' => $consultation->id,
                'status' => $consultation->status,
                'consulted_at' => $consultation->consulted_at?->toIso8601String(),
                'completed_at' => $consultation->completed_at?->toIso8601String(),
                'motif' => $consultation->motif,
                'examens' => $consultation->examens,
                'diagnostic' => $consultation->diagnostic,
                'traitement' => $consultation->traitement,
                'notes' => $consultation->notes,
                'weight_kg' => $consultation->weight_kg,
                'height_cm' => $consultation->height_cm,
                'temperature_c' => $consultation->temperature_c,
                'blood_pressure' => $consultation->blood_pressure,
                'payment_amount' => $consultation->payment_amount_minor !== null ? $consultation->payment_amount_minor / 100 : null,
                'payment_method' => $consultation->payment_method,
                'payment_service' => $consultation->payment_service,
                'is_paid' => $consultation->is_paid,
            ],
            'patient' => [
                'id' => $patient->id,
                'patient_number' => $patient->patient_number,
                'first_name' => $patient->first_name,
                'last_name' => $patient->last_name,
                'full_name' => $patient->full_name,
                'date_of_birth' => $patient->date_of_birth?->toDateString(),
                'gender' => $patient->gender?->value,
                'marital_status' => $patient->marital_status,
                'profession' => $patient->profession,
                'smoking_status' => $patient->smoking_status,
                'referred_by' => $patient->referred_by,
                'phone' => $patient->phone,
                'email' => $patient->email,
                'address' => $patient->address,
                'city' => $patient->city,
                'blood_group' => $patient->blood_group?->value,
                'allergies' => $patient->allergies,
                'antecedents_medical' => $patient->antecedents_medical,
                'antecedents_surgical' => $patient->antecedents_surgical,
                'antecedents_family' => $patient->antecedents_family,
                'antecedents_gyneco' => $patient->antecedents_gyneco,
                'antecedents_other' => $patient->antecedents_other,
            ],
            'options' => [
                'genders' => $this->genderOptions(),
                'bloodGroups' => $this->bloodGroupOptions(),
                'maritalStatuses' => $this->maritalOptions(),
                'smokingStatuses' => $this->smokingOptions(),
                'paymentMethods' => PaymentMethod::query()->orderBy('name')->pluck('name')->all(),
            ],
            'history' => $history,
            'upcoming' => $appointments
                ->filter(fn (Appointment $a): bool => $a->starts_at !== null && $a->starts_at->greaterThanOrEqualTo($now))
                ->map(fn (Appointment $a): array => $this->appointmentRow($a))
                ->values()
                ->all(),
            'past' => $appointments
                ->filter(fn (Appointment $a): bool => $a->starts_at === null || $a->starts_at->lessThan($now))
                ->map(fn (Appointment $a): array => $this->appointmentRow($a))
                ->values()
                ->all(),
            'measurements' => PatientMeasurement::query()
                ->where('patient_id', $patient->getKey())
                ->orderByDesc('measured_at')
                ->limit(60)
                ->get()
                ->map(fn (PatientMeasurement $m): array => [
                    'id' => $m->id,
                    'measured_at' => $m->measured_at?->toDateString(),
                    'weight_kg' => $m->weight_kg,
                    'height_cm' => $m->height_cm,
                    'bmi' => $m->bmi,
                    'waist_cm' => $m->waist_cm,
                    'head_cm' => $m->head_cm,
                    'notes' => $m->notes,
                ])->all(),
            'prescriptions' => Prescription::query()
                ->with('document:id,template_key')
                ->where('patient_id', $patient->getKey())
                ->orderByDesc('prescribed_at')
                ->limit(50)
                ->get()
                ->map(fn (Prescription $p): array => [
                    'id' => $p->id,
                    'document_id' => $p->document_id,
                    'template_id' => $p->document?->template_key !== null
                        ? 'built_in:'.$p->document->template_key
                        : null,
                    'prescribed_at' => $p->prescribed_at?->toDateString(),
                    'items' => $p->items ?? [],
                    'notes' => $p->notes,
                ])->all(),
            'documents' => $documents,
            'uploadedFiles' => $uploadedFiles,
            'documentTemplates' => $builtInTemplates->values()->all(),
            'onlyoffice' => [
                'url' => config('onlyoffice.url'),
                'warning' => config('onlyoffice.jwt_secret') === ''
                    ? __('ONLYOFFICE JWT is not configured.')
                    : null,
            ],
            'prestations' => $this->prestations(),
            'medications' => Medication::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->limit(500)
                ->get()
                ->map(fn (Medication $medication): array => [
                    'id' => $medication->getKey(),
                    'name' => $medication->name,
                    'dci' => $medication->dci,
                    'form' => $medication->form,
                    'dosage' => $medication->dosage,
                    'notes' => $medication->notes,
                ])
                ->all(),
            'exams' => Exam::query()
                ->where('is_active', true)
                ->orderBy('category')
                ->orderBy('name')
                ->get()
                ->map(fn (Exam $exam): array => [
                    'id' => $exam->getKey(),
                    'name' => $exam->name,
                    'category' => $legacyCategoryNames->get($exam->category, $exam->category),
                ])
                ->all(),
            'bilanCategories' => $bilanCategories
                ->map(fn (BilanType $category): array => [
                    'key' => $category->name,
                    'label' => $category->name,
                    'hint' => $category->description,
                ])
                ->values()
                ->all(),
            'cabinet' => [
                'doctor_name' => $branding['doctor_name'],
                'specialty' => $branding['specialty'],
                'order_number' => $branding['order_number'],
                'clinic_name' => $branding['clinic_name'],
                'phone' => $branding['phone'],
                'email' => $branding['email'],
                'address' => $branding['address'],
                'city' => $branding['city'],
                'footer' => $branding['footer'],
                'logo_url' => $branding['logo_url'],
            ],
            'stats' => [
                'consultations' => count($history) + 1,
                'appointments' => $appointments->count(),
            ],
            'canEdit' => $canEdit,
        ]);
    }

    /**
     * Persist the consultation fields (visite médicale, vitals, règlement).
     */
    public function update(Request $request, Consultation $consultation): RedirectResponse
    {
        $data = $request->validate([
            'motif' => ['nullable', 'string', 'max:5000'],
            'examens' => ['nullable', 'string', 'max:5000'],
            'diagnostic' => ['nullable', 'string', 'max:5000'],
            'traitement' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'weight_kg' => ['nullable', 'numeric', 'min:0', 'max:600'],
            'height_cm' => ['nullable', 'numeric', 'min:0', 'max:300'],
            'temperature_c' => ['nullable', 'numeric', 'min:0', 'max:60'],
            'blood_pressure' => ['nullable', 'string', 'max:20'],
            'payment_amount' => ['nullable', 'numeric', 'min:0'],
            'payment_method' => ['nullable', 'string', 'max:50'],
            'payment_service' => ['nullable', 'string', 'max:180'],
            'is_paid' => ['boolean'],
            'complete' => ['boolean'],
        ]);

        $amount = $data['payment_amount'] ?? null;

        $consultation->fill([
            'motif' => $data['motif'] ?? null,
            'examens' => $data['examens'] ?? null,
            'diagnostic' => $data['diagnostic'] ?? null,
            'traitement' => $data['traitement'] ?? null,
            'notes' => $data['notes'] ?? null,
            'weight_kg' => $data['weight_kg'] ?? null,
            'height_cm' => $data['height_cm'] ?? null,
            'temperature_c' => $data['temperature_c'] ?? null,
            'blood_pressure' => $data['blood_pressure'] ?? null,
            'payment_amount_minor' => $amount === null ? null : (int) round(((float) $amount) * 100),
            'payment_method' => $data['payment_method'] ?? null,
            'payment_service' => $data['payment_service'] ?? null,
            'is_paid' => (bool) ($data['is_paid'] ?? false),
        ]);

        if (! empty($data['complete'])) {
            $consultation->status = 'completed';
            $consultation->completed_at = now();

            if ($consultation->appointment_id !== null) {
                Appointment::query()->whereKey($consultation->appointment_id)->update([
                    'status' => AppointmentStatus::COMPLETED->value,
                    'completed_at' => now(),
                ]);
            }
        }

        $consultation->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Consultation saved.')]);

        return back();
    }

    /**
     * Persist the patient dossier (état civil, antécédents, allergies).
     */
    public function savePatient(Request $request, Consultation $consultation): RedirectResponse
    {
        // Consultations remain accessible when their patient dossier is archived.
        // Use the same withTrashed relationship as the workspace so saving the
        // visible dossier does not unexpectedly fail with a 404.
        $patient = $consultation->patient()->withTrashed()->firstOrFail();

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'date_of_birth' => ['nullable', 'date'],
            'gender' => ['nullable', Rule::in(Gender::values())],
            'marital_status' => ['nullable', 'string', 'max:30'],
            'profession' => ['nullable', 'string', 'max:100'],
            'smoking_status' => ['nullable', 'string', 'max:30'],
            'referred_by' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:190'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'blood_group' => ['nullable', Rule::in($this->bloodGroupValues())],
            'allergies' => ['nullable', 'string', 'max:2000'],
            'antecedents_medical' => ['nullable', 'string', 'max:5000'],
            'antecedents_surgical' => ['nullable', 'string', 'max:5000'],
            'antecedents_family' => ['nullable', 'string', 'max:5000'],
            'antecedents_gyneco' => ['nullable', 'string', 'max:5000'],
            'antecedents_other' => ['nullable', 'string', 'max:5000'],
        ]);

        $patient->update($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Patient record updated.')]);

        return back();
    }

    /**
     * Schedule the patient's next appointment straight from the consultation.
     */
    public function scheduleNext(Request $request, Consultation $consultation, CreateAppointmentAction $action): RedirectResponse
    {
        $data = $request->validate([
            'starts_at' => ['required', 'date'],
            'title' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $patient = Patient::query()->findOrFail($consultation->patient_id);
        $startsAt = CarbonImmutable::parse($data['starts_at']);
        $duration = (int) config('clinic.appointments.default_duration', 30);

        $action->handle($patient, $request->user(), [
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes($duration),
            'reason' => $data['title'] ?? null,
            'reception_notes' => $data['notes'] ?? null,
            'status' => 'scheduled',
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Next appointment scheduled.')]);

        return back();
    }

    /**
     * Record a growth / vitals measurement for the "Courbes" screen.
     */
    public function storeMeasurement(Request $request, Consultation $consultation): RedirectResponse
    {
        $data = $request->validate([
            'measured_at' => ['required', 'date'],
            'weight_kg' => ['nullable', 'numeric', 'min:0', 'max:600'],
            'height_cm' => ['nullable', 'numeric', 'min:0', 'max:300'],
            'waist_cm' => ['nullable', 'numeric', 'min:0', 'max:300'],
            'head_cm' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $bmi = null;
        $weight = $data['weight_kg'] ?? null;
        $height = $data['height_cm'] ?? null;

        if ($weight !== null && $height !== null && (float) $height > 0) {
            $metres = (float) $height / 100;
            $bmi = round(((float) $weight) / ($metres * $metres), 1);
        }

        PatientMeasurement::query()->create([
            'patient_id' => $consultation->patient_id,
            'measured_at' => CarbonImmutable::parse($data['measured_at']),
            'weight_kg' => $weight,
            'height_cm' => $height,
            'bmi' => $bmi,
            'waist_cm' => $data['waist_cm'] ?? null,
            'head_cm' => $data['head_cm'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => auth()->id(),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Measurement recorded.')]);

        return back();
    }

    /**
     * Remove a measurement.
     */
    public function deleteMeasurement(Consultation $consultation, PatientMeasurement $measurement): RedirectResponse
    {
        abort_if($measurement->patient_id !== $consultation->patient_id, 404);

        $measurement->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Measurement removed.')]);

        return back();
    }

    /**
     * Save an ordonnance (prescription) for the patient.
     */
    public function storePrescription(
        Request $request,
        Consultation $consultation,
        ClinicalDocumentManager $documentManager,
    ): RedirectResponse {
        $data = $request->validate([
            'prescribed_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.medication' => ['required', 'string', 'max:200'],
            'items.*.dosage' => ['nullable', 'string', 'max:200'],
            'items.*.duration' => ['nullable', 'string', 'max:100'],
            'items.*.instructions' => ['nullable', 'string', 'max:500'],
            'source' => ['nullable', Rule::in(['built_in'])],
            'template_key' => ['nullable', 'string', 'max:120'],
            'paper_size' => ['nullable', Rule::in(['A4', 'A5'])],
        ]);

        $items = array_values($data['items']);
        $prescribedAt = CarbonImmutable::parse($data['prescribed_at']);

        /** @var User $user */
        $user = $request->user();

        DB::transaction(function () use ($consultation, $data, $documentManager, $items, $prescribedAt, $user): void {
            $prescription = Prescription::query()->create([
                'patient_id' => $consultation->patient_id,
                'consultation_id' => $consultation->getKey(),
                'prescribed_at' => $prescribedAt,
                'items' => $items,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->getKey(),
            ]);

            $document = $documentManager->create(
                $consultation,
                $user,
                [
                    'source' => $data['source'] ?? 'built_in',
                    'category' => 'ordonnance',
                    'paper_size' => $data['paper_size'] ?? 'A5',
                    'template_key' => $data['template_key'] ?? 'ordonnance',
                    'title' => __('Prescription :date', ['date' => $prescribedAt->format('d/m/Y')]),
                ],
                [
                    'prescription_items' => $items,
                    'prescription_notes' => $data['notes'] ?? '',
                ],
            );

            $prescription->update(['document_id' => $document->getKey()]);
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Prescription saved. Opening in ONLYOFFICE.'),
        ]);

        return back();
    }

    public function createPrescriptionDocument(
        Request $request,
        Consultation $consultation,
        Prescription $prescription,
        ClinicalDocumentManager $documentManager,
    ): RedirectResponse {
        abort_if($prescription->patient_id !== $consultation->patient_id, 404);

        if ($prescription->document_id !== null) {
            return back();
        }

        /** @var array{source: string, template_key?: string|null, paper_size: string} $data */
        $data = $request->validate([
            'source' => ['required', Rule::in(['built_in'])],
            'template_key' => ['nullable', 'string', 'max:120'],
            'paper_size' => ['required', Rule::in(['A4', 'A5'])],
        ]);

        /** @var User $user */
        $user = $request->user();
        $document = $documentManager->create(
            $consultation,
            $user,
            [
                ...$data,
                'category' => 'ordonnance',
                'template_key' => $data['template_key'] ?? 'ordonnance',
                'title' => __('Prescription :date', [
                    'date' => $prescription->prescribed_at?->format('d/m/Y') ?? '',
                ]),
            ],
            [
                'prescription_items' => $prescription->items ?? [],
                'prescription_notes' => $prescription->notes ?? '',
            ],
        );
        $prescription->update(['document_id' => $document->getKey()]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Prescription converted to Word.'),
        ]);

        return back();
    }

    /**
     * Save a generated document (certificat, courrier, bilan…).
     */
    public function storeDocument(
        Request $request,
        Consultation $consultation,
        ClinicalHtmlSanitizer $sanitizer,
    ): RedirectResponse {
        $data = $request->validate([
            'category' => ['required', 'string', 'max:40'],
            'title' => ['required', 'string', 'max:200'],
            'content' => ['nullable', 'string', 'max:60000'],
        ]);
        $originalContent = $data['content'] ?? null;
        $content = $sanitizer->sanitize($originalContent);
        /** @var User $user */
        $user = $request->user();

        DB::transaction(function () use ($consultation, $data, $originalContent, $content, $user): void {
            $document = Document::query()->create([
                'patient_id' => $consultation->patient_id,
                'consultation_id' => $consultation->getKey(),
                'category' => $data['category'],
                'title' => $data['title'],
                'content' => $content,
                'created_by' => $user->getKey(),
            ]);

            AuditLog::record('clinical_document.created', $document, [
                'category' => $data['category'],
                'content_present' => $content !== null,
                'content_sanitized' => (string) $originalContent !== (string) $content,
            ], $user->getKey());
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Document saved.')]);

        return back();
    }

    /**
     * Upload a file directly to the current patient's consultation dossier.
     */
    public function uploadFile(Request $request, Consultation $consultation): RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx', 'max:20480'],
            'title' => ['nullable', 'string', 'max:200'],
        ]);

        $file = $data['file'];
        $path = $file->store('patient-documents/'.$consultation->patient_id);

        if ($path === false) {
            throw ValidationException::withMessages([
                'file' => __('The file could not be stored.'),
            ]);
        }

        $originalFilename = Str::limit(basename($file->getClientOriginalName()), 190, '');
        $title = trim((string) ($data['title'] ?? ''));

        Document::query()->create([
            'patient_id' => $consultation->patient_id,
            'consultation_id' => $consultation->getKey(),
            'category' => 'uploaded',
            'title' => Str::limit($title !== '' ? $title : pathinfo($originalFilename, PATHINFO_FILENAME), 200, ''),
            'original_filename' => $originalFilename,
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'file_path' => $path,
            'created_by' => auth()->id(),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('File uploaded.')]);

        return back();
    }

    /**
     * Remove an uploaded file from the current patient's dossier.
     */
    public function destroyUploadedFile(Consultation $consultation, Document $document): RedirectResponse
    {
        abort_if(
            $document->patient_id !== $consultation->patient_id || $document->category !== 'uploaded',
            404,
        );

        if ($document->file_path !== null) {
            Storage::delete($document->file_path);
        }

        $document->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('File removed.')]);

        return back();
    }

    /**
     * @return list<array{label: string, amount: float|null}>
     */
    private function prestations(): array
    {
        $fees = ConsultationFee::query()->where('is_active', true)->orderBy('label')->get()
            ->map(fn (ConsultationFee $f): array => ['label' => $f->label, 'amount' => $f->amount_minor !== null ? $f->amount_minor / 100 : null])
            ->all();

        $acts = Act::query()->where('is_active', true)->orderBy('name')->get()
            ->map(fn (Act $a): array => ['label' => $a->name, 'amount' => $a->price_minor !== null ? $a->price_minor / 100 : null])
            ->all();

        return array_merge($fees, $acts);
    }

    /**
     * @return array<string, mixed>
     */
    private function appointmentRow(Appointment $appointment): array
    {
        return [
            'id' => $appointment->id,
            'date' => $appointment->appointment_date?->toDateString(),
            'time' => $appointment->starts_at?->format('H:i'),
            'status' => $appointment->status->value,
            'reason' => $appointment->reason,
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function genderOptions(): array
    {
        return array_map(
            static fn (Gender $gender): array => ['value' => $gender->value, 'label' => $gender->label()],
            Gender::cases(),
        );
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function bloodGroupOptions(): array
    {
        return array_map(
            static fn (BloodGroup $group): array => ['value' => $group->value, 'label' => $group->value],
            BloodGroup::cases(),
        );
    }

    /**
     * @return list<string>
     */
    private function bloodGroupValues(): array
    {
        return array_map(static fn (BloodGroup $group): string => $group->value, BloodGroup::cases());
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function maritalOptions(): array
    {
        return [
            ['value' => 'single', 'label' => 'Single'],
            ['value' => 'married', 'label' => 'Married'],
            ['value' => 'divorced', 'label' => 'Divorced'],
            ['value' => 'widowed', 'label' => 'Widowed'],
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function smokingOptions(): array
    {
        return [
            ['value' => 'non_smoker', 'label' => 'Non-smoker'],
            ['value' => 'smoker', 'label' => 'Smoker'],
            ['value' => 'former_smoker', 'label' => 'Former smoker'],
        ];
    }
}
