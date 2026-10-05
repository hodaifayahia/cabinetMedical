<?php

namespace App\Http\Controllers\Configuration;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DocumentTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

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
     * @return array{title: string, category: string, group: string|null, paper_size: string, body: string, is_active: bool}
     */
    private function validated(Request $request): array
    {
        /** @var array{title: string, category: string, group?: string|null, paper_size: string, body: string, is_active?: bool} $data */
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'category' => ['required', Rule::in(self::CATEGORIES)],
            'group' => ['nullable', 'string', 'max:120'],
            'paper_size' => ['required', Rule::in(self::PAPER_SIZES)],
            'body' => ['required', 'string', 'max:20000'],
            'is_active' => ['boolean'],
        ], [
            'title.required' => 'Donnez un nom au modèle.',
            'category.in' => 'Choisissez un type de document valide.',
            'paper_size.in' => 'Choisissez un format de page valide.',
            'body.required' => 'Le contenu du modèle ne peut pas être vide.',
        ]);

        return [
            'title' => $data['title'],
            'category' => $data['category'],
            'group' => ($data['group'] ?? null) === '' ? null : ($data['group'] ?? null),
            'paper_size' => $data['paper_size'],
            'body' => $data['body'],
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
