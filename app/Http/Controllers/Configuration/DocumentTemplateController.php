<?php

namespace App\Http\Controllers\Configuration;

use App\ClinicalDocuments\ClinicalHtmlSanitizer;
use App\ClinicalDocuments\DocxDocumentBuilder;
use App\ClinicalDocuments\TemplateBody;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DocumentTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Manage cabinet-authored consultation document templates ("modèles").
 *
 * Templates are cabinet-scoped through the model's global scope, so a cabinet
 * only ever sees and edits its own. Active rows are merged into the consultation
 * document picker by ClinicalDocumentTemplateCatalog.
 */
class DocumentTemplateController extends Controller
{
    /** The three categories the clinical document creator accepts. */
    private const CATEGORIES = ['ordonnance', 'bilan', 'courrier'];

    private const PAPER_SIZES = ['A4', 'A5'];

    private const BODY_FORMATS = [TemplateBody::FORMAT_TEXT, TemplateBody::FORMAT_HTML];

    public function __construct(private readonly ClinicalHtmlSanitizer $sanitizer) {}

    public function index(Request $request): Response
    {
        $search = trim((string) $request->string('search'));

        $templates = DocumentTemplate::query()
            ->when($search !== '', fn (Builder $query): Builder => $query->where(
                fn (Builder $inner): Builder => $inner
                    ->where('title', 'like', '%'.$search.'%')
                    ->orWhere('group', 'like', '%'.$search.'%'),
            ))
            ->orderBy('category')
            ->orderBy('title')
            ->get()
            ->map(fn (DocumentTemplate $template): array => $this->transform($template))
            ->values()
            ->all();

        return Inertia::render('configuration/DocumentTemplates', [
            'templates' => $templates,
            'filters' => ['search' => $search],
            'options' => [
                'categories' => [
                    ['value' => 'ordonnance', 'label' => 'Ordonnance'],
                    ['value' => 'bilan', 'label' => 'Bilan / demande d’examen'],
                    ['value' => 'courrier', 'label' => 'Courrier / certificat'],
                ],
                'paperSizes' => [
                    ['value' => 'A4', 'label' => 'A4 (210 × 297 mm)'],
                    ['value' => 'A5', 'label' => 'A5 (148 × 210 mm)'],
                ],
                'placeholders' => $this->placeholders(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        /** @var User $user */
        $user = $request->user();

        DB::transaction(function () use ($data, $user): void {
            $template = DocumentTemplate::query()->create([
                ...$data,
                'created_by' => $user->getKey(),
            ]);

            AuditLog::record('document_template.created', $template, [
                'category' => $template->category,
            ], $user->getKey());
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Modèle enregistré.']);

        return back();
    }

    public function update(Request $request, DocumentTemplate $documentTemplate): RedirectResponse
    {
        $data = $this->validated($request);

        /** @var User $user */
        $user = $request->user();

        DB::transaction(function () use ($documentTemplate, $data, $user): void {
            $documentTemplate->update($data);

            AuditLog::record('document_template.updated', $documentTemplate, [
                'category' => $documentTemplate->category,
            ], $user->getKey());
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Modifications enregistrées.']);

        return back();
    }

    public function destroy(Request $request, DocumentTemplate $documentTemplate): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        AuditLog::record('document_template.removed', $documentTemplate, [
            'title' => $documentTemplate->title,
        ], $user->getKey());

        $documentTemplate->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Modèle supprimé.']);

        return back();
    }

    /**
     * Download the template being edited as a Word (.docx) file. The body is
     * posted (not read from the database) so unsaved edits are exported too;
     * {{variables}} are kept as-is so the file can be edited in Word and
     * imported back.
     */
    public function exportDocx(Request $request, DocxDocumentBuilder $builder): BinaryFileResponse
    {
        /** @var array{title?: string|null, paper_size: string, body: string, body_format?: string|null} $data */
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:200'],
            'paper_size' => ['required', Rule::in(self::PAPER_SIZES)],
            'body' => ['required', 'string', 'max:'.TemplateBody::MAX_LENGTH],
            'body_format' => ['nullable', Rule::in(self::BODY_FORMATS)],
        ], [
            'body.required' => 'Le contenu du modèle ne peut pas être vide.',
            'body.max' => 'Le modèle est trop volumineux (réduisez la taille ou le nombre d’images).',
        ]);

        $title = trim((string) ($data['title'] ?? '')) ?: 'Modèle de document';
        $format = TemplateBody::normalizeFormat($data['body_format'] ?? null);
        $body = $format === TemplateBody::FORMAT_HTML
            ? (string) $this->sanitizer->sanitize($data['body'])
            : $data['body'];

        $directory = storage_path('app/private/tmp');

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $path = $directory.'/template-'.Str::uuid().'.docx';

        try {
            $builder->buildTemplate($path, $title, $body, $format, $data['paper_size']);
        } catch (Throwable $exception) {
            @unlink($path);

            throw $exception;
        }

        return response()
            ->download($path, (Str::slug($title) ?: 'modele').'.docx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ])
            ->deleteFileAfterSend();
    }

    /**
     * @return array{title: string, category: string, group: string|null, paper_size: string, body: string, body_format: string, is_active: bool}
     */
    private function validated(Request $request): array
    {
        /** @var array{title: string, category: string, group?: string|null, paper_size: string, body: string, body_format?: string|null, is_active?: bool} $data */
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'category' => ['required', Rule::in(self::CATEGORIES)],
            'group' => ['nullable', 'string', 'max:120'],
            'paper_size' => ['required', Rule::in(self::PAPER_SIZES)],
            'body' => ['required', 'string', 'max:'.TemplateBody::MAX_LENGTH],
            'body_format' => ['nullable', Rule::in(self::BODY_FORMATS)],
            'is_active' => ['boolean'],
        ], [
            'title.required' => 'Donnez un nom au modèle.',
            'category.in' => 'Choisissez un type de document valide.',
            'paper_size.in' => 'Choisissez un format de page valide.',
            'body.required' => 'Le contenu du modèle ne peut pas être vide.',
            'body.max' => 'Le modèle est trop volumineux (réduisez la taille ou le nombre d’images).',
            'body_format.in' => 'Format de contenu invalide.',
        ]);

        $format = TemplateBody::normalizeFormat($data['body_format'] ?? null);
        $body = $data['body'];

        if ($format === TemplateBody::FORMAT_HTML) {
            // Rich bodies are stored only after the clinical HTML allowlist:
            // no scripts, event handlers, remote resources or unsafe links.
            $body = $this->sanitizer->sanitize($body);

            if ($body === null || trim(strip_tags($body, '<img><table><hr>')) === '') {
                throw ValidationException::withMessages([
                    'body' => 'Le contenu du modèle ne peut pas être vide.',
                ]);
            }
        }

        return [
            'title' => $data['title'],
            'category' => $data['category'],
            'group' => ($data['group'] ?? null) === '' ? null : ($data['group'] ?? null),
            'paper_size' => $data['paper_size'],
            'body' => $body,
            'body_format' => $format,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(DocumentTemplate $template): array
    {
        return [
            'id' => $template->getKey(),
            'template_key' => $template->template_key,
            'title' => $template->title,
            'category' => $template->category,
            'group' => $template->group,
            'paper_size' => $template->paper_size,
            'body' => $template->body,
            'body_format' => TemplateBody::normalizeFormat($template->body_format),
            'is_active' => $template->is_active,
            'updated_at' => $template->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The substitution tokens a template may use, surfaced as an in-form helper.
     *
     * @return list<array{token: string, label: string}>
     */
    private function placeholders(): array
    {
        return [
            ['token' => '{{patient.full_name}}', 'label' => 'Nom complet du patient'],
            ['token' => '{{patient.date_of_birth}}', 'label' => 'Date de naissance'],
            ['token' => '{{patient.age}}', 'label' => 'Âge du patient'],
            ['token' => '{{patient.allergies}}', 'label' => 'Allergies'],
            ['token' => '{{patient.antecedents_medical}}', 'label' => 'Antécédents médicaux'],
            ['token' => '{{patient.antecedents_surgical}}', 'label' => 'Antécédents chirurgicaux'],
            ['token' => '{{patient.antecedents_family}}', 'label' => 'Antécédents familiaux'],
            ['token' => '{{consultation.motif}}', 'label' => 'Motif de consultation'],
            ['token' => '{{consultation.examens}}', 'label' => 'Examens'],
            ['token' => '{{consultation.diagnostic}}', 'label' => 'Diagnostic'],
            ['token' => '{{consultation.traitement}}', 'label' => 'Traitement'],
            ['token' => '{{doctor.name}}', 'label' => 'Nom du médecin'],
            ['token' => '{{doctor.specialty}}', 'label' => 'Spécialité du médecin'],
            ['token' => '{{document.date}}', 'label' => 'Date du document'],
            ['token' => '{{cabinet.name}}', 'label' => 'Nom du cabinet'],
        ];
    }
}
