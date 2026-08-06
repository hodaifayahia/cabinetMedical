<?php

namespace Tests\Feature\Consultations;

use App\Enums\RoleName;
use App\Models\Consultation;
use App\Models\Document;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use ZipArchive;

class ClinicalDocumentControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Patient $patient;

    private Consultation $consultation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->user = User::factory()->create();
        $this->user->assignRole(RoleName::ADMINISTRATOR->value);
        $this->patient = Patient::factory()->create([
            'first_name' => 'Amine',
            'last_name' => 'Bensalem',
        ]);
        $this->consultation = Consultation::query()->create([
            'patient_id' => $this->patient->getKey(),
            'consulted_at' => now(),
            'status' => 'in_progress',
            'diagnostic' => 'Contrôle clinique',
            'created_by' => $this->user->getKey(),
        ]);

        Storage::fake('local');
        config()->set('onlyoffice.url', 'http://onlyoffice.test');
        config()->set('onlyoffice.internal_url', 'http://onlyoffice.test');
        config()->set('onlyoffice.app_url', 'http://app.test');
        config()->set('onlyoffice.jwt_secret', 'test-secret');
    }

    public function test_user_can_create_a_patient_word_document_in_a5(): void
    {
        $this->actingAs($this->user)
            ->post(route('app.consultations.word-documents.store', $this->consultation), [
                'source' => 'built_in',
                'category' => 'courrier',
                'template_key' => 'certificat-bonne-sante',
                'paper_size' => 'A5',
            ])
            ->assertRedirect();

        $document = Document::query()->sole();

        $this->assertSame('A5', $document->paper_size);
        $this->assertSame('courrier', $document->category);
        Storage::assertExists($document->file_path);

        $xml = $this->documentXml($document->file_path);
        $this->assertStringContainsString('Amine Bensalem', $xml);
        $this->assertStringContainsString('<w:pgSz w:w="8391" w:h="11906"/>', $xml);
    }

    public function test_saving_a_prescription_creates_a_word_document_with_medications(): void
    {
        $this->actingAs($this->user)
            ->post(route('app.consultations.prescriptions.store', $this->consultation), [
                'prescribed_at' => now()->toDateString(),
                'paper_size' => 'A5',
                'source' => 'built_in',
                'template_key' => 'ordonnance',
                'items' => [[
                    'medication' => 'Paracétamol 500 mg',
                    'dosage' => '1 comprimé x 3/j',
                    'duration' => '5 jours',
                    'instructions' => 'Après les repas',
                ]],
            ])
            ->assertRedirect();

        $prescription = Prescription::query()->sole();
        $document = Document::query()->findOrFail($prescription->document_id);
        $xml = $this->documentXml($document->file_path);

        $this->assertSame('ordonnance', $document->category);
        $this->assertStringContainsString('Paracétamol 500 mg', $xml);
        $this->assertStringContainsString('Après les repas', $xml);
    }

    public function test_onlyoffice_callback_saves_a_clinical_document(): void
    {
        config()->set('onlyoffice.jwt_secret', '');
        $this->actingAs($this->user)
            ->post(route('app.consultations.word-documents.store', $this->consultation), [
                'source' => 'built_in',
                'category' => 'bilan',
                'template_key' => 'bilan',
                'paper_size' => 'A4',
            ]);
        $document = Document::query()->sole();
        $callback = URL::temporarySignedRoute(
            'clinical-documents.callback',
            now()->addHour(),
            ['document' => $document],
            false,
        );

        Http::fake(['http://onlyoffice.test/*' => Http::response('updated-docx', 200)]);

        $this->postJson($callback, [
            'key' => 'clinical-document-'.$document->id.'-v1',
            'status' => 2,
            'url' => 'http://onlyoffice.test/cache/updated.docx',
        ])->assertOk()->assertExactJson(['error' => 0]);

        $this->assertSame('updated-docx', Storage::get($document->file_path));
        $this->assertSame(2, $document->fresh()->file_version);
    }

    public function test_onlyoffice_clinical_callback_is_excluded_from_csrf_protection(): void
    {
        $this->get('/')->assertOk();

        $middleware = new class($this->app, $this->app->make(Encrypter::class)) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        };
        $request = Request::create('/app/clinical-documents/1/callback', 'POST');
        $request->setLaravelSession($this->app->make('session')->driver());

        $response = $middleware->handle(
            $request,
            static fn () => response()->json(['ok' => true]),
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_onlyoffice_callback_rejects_untrusted_clinical_document_url(): void
    {
        config()->set('onlyoffice.jwt_secret', '');
        $this->actingAs($this->user)
            ->post(route('app.consultations.word-documents.store', $this->consultation), [
                'source' => 'built_in',
                'category' => 'bilan',
                'template_key' => 'bilan',
                'paper_size' => 'A4',
            ]);
        $document = Document::query()->sole();
        $callback = URL::temporarySignedRoute(
            'clinical-documents.callback',
            now()->addHour(),
            ['document' => $document],
            false,
        );

        Http::fake();

        $this->postJson($callback, [
            'key' => 'clinical-document-'.$document->id.'-v1',
            'status' => 2,
            'url' => 'http://169.254.169.254/latest/meta-data',
        ])->assertUnprocessable()->assertJson(['error' => 1]);

        Http::assertNothingSent();
    }

    public function test_user_can_upload_preview_and_remove_a_consultation_file(): void
    {
        $this->actingAs($this->user)
            ->post(route('app.consultations.uploaded-documents.store', $this->consultation), [
                'title' => 'Radiographie du pied',
                'file' => UploadedFile::fake()->image('foot-xray.png'),
            ])
            ->assertRedirect();

        $document = Document::query()->sole();

        $this->assertSame('uploaded', $document->category);
        $this->assertSame('Radiographie du pied', $document->title);
        $this->assertSame($this->consultation->patient_id, $document->patient_id);
        Storage::assertExists($document->file_path);

        $this->actingAs($this->user)
            ->get(route('app.consultations.show', $this->consultation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('uploadedFiles.0.id', $document->id)
                ->where('uploadedFiles.0.title', 'Radiographie du pied')
                ->where('uploadedFiles.0.mime_type', 'image/png')
                ->where(
                    'uploadedFiles.0.download_url',
                    fn (string $url): bool => str_starts_with(
                        $url,
                        '/app/clinical-documents/',
                    ),
                )
            );

        $url = URL::temporarySignedRoute(
            'clinical-documents.file',
            now()->addHour(),
            ['document' => $document],
            false,
        );

        $this->get($url)
            ->assertOk()
            ->assertHeader('content-type', 'image/png');

        $this->actingAs($this->user)
            ->delete(route('app.consultations.uploaded-documents.destroy', [
                'consultation' => $this->consultation,
                'document' => $document,
            ]))
            ->assertRedirect();

        $this->assertDatabaseMissing('documents', ['id' => $document->id]);
        Storage::assertMissing($document->file_path);
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
