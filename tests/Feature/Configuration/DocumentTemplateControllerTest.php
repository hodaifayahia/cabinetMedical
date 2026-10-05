<?php

namespace Tests\Feature\Configuration;

use App\ClinicalDocuments\ClinicalDocumentTemplateCatalog;
use App\Enums\RoleName;
use App\Models\Consultation;
use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Models\Patient;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use ZipArchive;

class DocumentTemplateControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function manager(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleName::ADMINISTRATOR->value);

        return $user;
    }

    public function test_index_lists_templates_and_picker_options(): void
    {
        $manager = $this->manager();
        DocumentTemplate::factory()->create([
            'title' => 'Certificat du cabinet',
            'category' => 'courrier',
        ]);

        $this->actingAs($manager)
            ->get(route('app.configuration.document-templates.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('configuration/DocumentTemplates')
                ->has('templates', 1)
                ->where('templates.0.title', 'Certificat du cabinet')
                ->has('options.categories', 3)
                ->has('options.paperSizes', 2)
                ->has('options.placeholders'),
            );
    }

    public function test_index_is_forbidden_without_configuration_manage(): void
    {
        // A freshly created user holds no configuration permission.
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('app.configuration.document-templates.index'))
            ->assertForbidden();
    }

    public function test_manager_can_create_a_template_that_enters_the_catalogue(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager)
            ->post(route('app.configuration.document-templates.store'), [
                'title' => 'Ordonnance type cabinet',
                'category' => 'ordonnance',
                'group' => 'Mes ordonnances',
                'paper_size' => 'A5',
                'body' => "## Traitement\n{{patient.full_name}}",
                'is_active' => true,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $template = DocumentTemplate::query()->sole();
        $this->assertSame('ordonnance', $template->category);
        $this->assertSame('A5', $template->paper_size);
        $this->assertStringStartsWith('custom-', $template->template_key);

        // It is now a first-class catalogue entry, usable in the consultation.
        $this->actingAs($manager);
        $entry = app(ClinicalDocumentTemplateCatalog::class)->find($template->template_key);
        $this->assertNotNull($entry);
        $this->assertSame('ordonnance', $entry['category']);
        $this->assertSame('A5', $entry['default_paper_size']);
    }

    public function test_store_rejects_an_unknown_category_and_paper_size(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager)
            ->from(route('app.configuration.document-templates.index'))
            ->post(route('app.configuration.document-templates.store'), [
                'title' => 'Mauvais modèle',
                'category' => 'facture',
                'paper_size' => 'A3',
                'body' => 'x',
            ])
            ->assertSessionHasErrors(['category', 'paper_size']);

        $this->assertDatabaseCount('document_templates', 0);
    }

    public function test_manager_can_update_and_deactivate_a_template(): void
    {
        $manager = $this->manager();
        $template = DocumentTemplate::factory()->create([
            'title' => 'Ancien titre',
            'category' => 'courrier',
            'is_active' => true,
        ]);

        $this->actingAs($manager)
            ->put(route('app.configuration.document-templates.update', $template), [
                'title' => 'Nouveau titre',
                'category' => 'courrier',
                'group' => 'Certificats',
                'paper_size' => 'A4',
                'body' => 'Contenu mis à jour',
                'is_active' => false,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $template->refresh();
        $this->assertSame('Nouveau titre', $template->title);
        $this->assertFalse($template->is_active);

        // An inactive template drops out of the consultation picker.
        $this->actingAs($manager);
        $this->assertNull(
            app(ClinicalDocumentTemplateCatalog::class)->find($template->template_key),
        );
    }

    public function test_manager_can_delete_a_template(): void
    {
        $manager = $this->manager();
        $template = DocumentTemplate::factory()->create();

        $this->actingAs($manager)
            ->delete(route('app.configuration.document-templates.destroy', $template))
            ->assertRedirect();

        $this->assertDatabaseMissing('document_templates', ['id' => $template->id]);
    }

    public function test_a_custom_template_builds_a_consultation_document_with_family_history(): void
    {
        Storage::fake('local');
        config()->set('onlyoffice.url', 'http://onlyoffice.test');
        config()->set('onlyoffice.jwt_secret', 'test-secret');

        $manager = $this->manager();
        $patient = Patient::factory()->create([
            'first_name' => 'Amine',
            'last_name' => 'Bensalem',
            'antecedents_family' => 'Frère diabétique',
        ]);
        $consultation = Consultation::query()->create([
            'patient_id' => $patient->getKey(),
            'consulted_at' => now(),
            'status' => 'in_progress',
            'created_by' => $manager->getKey(),
        ]);

        $template = DocumentTemplate::factory()->create([
            'title' => 'Courrier avec antécédents',
            'category' => 'courrier',
            'paper_size' => 'A4',
            'body' => "Patient : {{patient.full_name}}\n\n## Antécédents familiaux\n{{patient.antecedents_family}}",
            'is_active' => true,
        ]);

        $this->actingAs($manager)
            ->post(route('app.consultations.word-documents.store', $consultation), [
                'source' => 'built_in',
                'category' => 'courrier',
                'template_key' => $template->template_key,
                'paper_size' => 'A4',
            ])
            ->assertRedirect();

        $document = Document::query()->sole();
        $this->assertSame('courrier', $document->category);
        $this->assertSame($template->template_key, $document->template_key);

        $xml = $this->documentXml($document->file_path);
        $this->assertStringContainsString('Amine Bensalem', $xml);
        $this->assertStringContainsString('Frère diabétique', $xml);
    }

    private function documentXml(string $path): string
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::path($path)) === true);
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        $this->assertIsString($xml);

        return $xml;
    }
}
