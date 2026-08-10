<?php

namespace App\Http\Controllers\Consultations;

use App\ClinicalDocuments\ClinicalDocumentManager;
use App\ClinicalDocuments\ClinicalDocumentOnlyOffice;
use App\Http\Controllers\Controller;
use App\Models\Consultation;
use App\Models\Document;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClinicalDocumentController extends Controller
{
    public function store(
        Request $request,
        Consultation $consultation,
        ClinicalDocumentManager $manager,
    ): RedirectResponse {
        $data = $request->validate([
            'source' => ['required', Rule::in(['built_in'])],
            'category' => ['required', Rule::in(['ordonnance', 'bilan', 'courrier'])],
            'paper_size' => ['required', Rule::in(['A4', 'A5'])],
            'template_key' => ['nullable', 'required_if:source,built_in', 'string', 'max:120'],
            'title' => ['nullable', 'string', 'max:200'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $manager->create($consultation, $user, $data);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Word document created. Opening in ONLYOFFICE.'),
        ]);

        return back();
    }

    public function convert(
        Request $request,
        Consultation $consultation,
        Document $document,
        ClinicalDocumentManager $manager,
    ): RedirectResponse {
        abort_if(
            (int) $document->patient_id !== (int) $consultation->patient_id
            || (int) $document->consultation_id !== (int) $consultation->getKey(),
            404,
        );

        $data = $request->validate([
            'paper_size' => ['required', Rule::in(['A4', 'A5'])],
        ]);

        $manager->convertLegacy($document, $consultation, $data['paper_size']);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Document converted to Word.'),
        ]);

        return back();
    }

    public function file(Request $request, Document $document): StreamedResponse
    {
        abort_unless($request->hasValidRelativeSignature(), 401);
        abort_if($document->file_path === null || ! Storage::exists($document->file_path), 404);

        return Storage::response(
            $document->file_path,
            $document->original_filename ?? 'document.docx',
            [
                'Content-Type' => $document->mime_type ?? 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, max-age=300',
            ],
            'inline',
        );
    }

    public function callback(
        Request $request,
        Document $document,
        ClinicalDocumentOnlyOffice $onlyOffice,
    ): JsonResponse {
        return $onlyOffice->callback($request, $document);
    }
}
