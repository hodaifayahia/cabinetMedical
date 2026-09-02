<?php

namespace Tests\Feature\Consultations;

use App\ClinicalDocuments\ClinicalDocumentManager;
use App\ClinicalDocuments\DocxDocumentBuilder;
use App\Enums\RoleName;
use App\Models\CabinetSetting;
use App\Models\Consultation;
use App\Models\DoctorProfile;
use App\Models\Document;
use App\Models\Patient;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * A Word document generated before the clinic logo changed must print with
 * the logo configured today. Opening the consultation re-embeds it, while the
 * wording the doctor typed in ONLYOFFICE is left alone.
 */
class ClinicalDocumentLogoRefreshTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Consultation $consultation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
        Storage::fake('public');

        $this->user = User::factory()->create(['name' => 'Dr Nadia Amrane']);
        $this->user->assignRole(RoleName::ADMINISTRATOR->value);
        DoctorProfile::factory()->for($this->user)->create([
            'specialty' => 'Cardiologie',
            'professional_identifier' => 'ORD-2048',
        ]);

        $patient = Patient::factory()->create();
        $this->consultation = Consultation::query()->create([
            'patient_id' => $patient->getKey(),
            'consulted_at' => now(),
            'status' => 'in_progress',
            'created_by' => $this->user->getKey(),
        ]);
    }

    public function test_opening_a_consultation_re_embeds_the_replaced_logo_without_touching_the_body(): void
    {
        $firstLogo = $this->storeLogo('atlas.png', 200, 100);
        $document = $this->createOrdonnance();

        $this->assertSame($firstLogo, $this->part($document, 'word/media/clinic-logo.png'));
        $this->assertSame(1, $document->file_version);

        // The doctor uploads a differently shaped logo in a different format.
        $secondLogo = $this->storeLogo('nour.jpg', 120, 180);

        $this->actingAs($this->user)
            ->get(route('app.consultations.show', $this->consultation))
            ->assertOk();

        $document->refresh();
        $documentXml = $this->part($document, 'word/document.xml');

        $this->assertSame($secondLogo, $this->part($document, 'word/media/clinic-logo.jpg'));
        $this->assertFalse($this->has($document, 'word/media/clinic-logo.png'));
        $this->assertSame(2, $document->file_version, 'ONLYOFFICE only refetches when the key version moves.');
        $this->assertSame(Storage::size((string) $document->file_path), $document->file_size);

        // Relationships, content types and both drawings follow the new file.
        $this->assertStringContainsString(
            'Target="media/clinic-logo.jpg"',
            $this->part($document, 'word/_rels/document.xml.rels'),
        );
        $this->assertStringContainsString(
            'Target="media/clinic-logo.jpg"',
            $this->part($document, 'word/_rels/header1.xml.rels'),
        );
        $this->assertStringContainsString(
            '<Default Extension="jpg" ContentType="image/jpeg"/>',
            $this->part($document, '[Content_Types].xml'),
        );

        $logo = app(DocxDocumentBuilder::class)->resolveLogo(
            (string) CabinetSetting::current()->logo_path,
        );
        $this->assertIsArray($logo);
        [$watermarkWidth, $watermarkHeight] = app(DocxDocumentBuilder::class)->watermarkExtent($logo);

        // The portrait logo must not be stretched into the landscape box the
        // previous one occupied.
        $this->assertStringContainsString(
            '<wp:extent cx="'.$logo['width_emu'].'" cy="'.$logo['height_emu'].'"/>',
            $documentXml,
        );
        $this->assertStringContainsString(
            '<wp:extent cx="'.$watermarkWidth.'" cy="'.$watermarkHeight.'"/>',
            $this->part($document, 'word/header1.xml'),
        );

        // Everything the document said stays exactly as it was written.
        $this->assertStringContainsString('Paracétamol', $documentXml);
        $this->assertStringContainsString('Dr Nadia Amrane', $documentXml);
        $this->assertStringContainsString('ORD-2048', $documentXml);
    }

    public function test_reopening_an_up_to_date_document_leaves_the_file_untouched(): void
    {
        $this->storeLogo('atlas.png', 200, 100);
        $document = $this->createOrdonnance();
        $untouched = Storage::get((string) $document->file_path);

        foreach (range(1, 2) as $ignored) {
            $this->actingAs($this->user)
                ->get(route('app.consultations.show', $this->consultation))
                ->assertOk();
        }

        $this->assertSame(1, $document->refresh()->file_version);
        $this->assertSame($untouched, Storage::get((string) $document->file_path));
    }

    public function test_a_document_whose_media_was_renamed_is_left_alone(): void
    {
        $this->storeLogo('atlas.png', 200, 100);
        $document = $this->createOrdonnance();

        // Stand in for a package ONLYOFFICE re-serialised under its own names.
        $absolute = Storage::path((string) $document->file_path);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($absolute) === true);
        $zip->renameName('word/media/clinic-logo.png', 'word/media/image1.png');
        $zip->close();

        $this->storeLogo('nour.jpg', 120, 180);
        $untouched = Storage::get((string) $document->file_path);

        $this->actingAs($this->user)
            ->get(route('app.consultations.show', $this->consultation))
            ->assertOk();

        $this->assertSame(1, $document->refresh()->file_version);
        $this->assertSame($untouched, Storage::get((string) $document->file_path));
    }

    private function storeLogo(string $name, int $width, int $height): string
    {
        $bytes = UploadedFile::fake()->image($name, $width, $height)->getContent();
        $path = 'cabinet/'.$name;
        Storage::disk('public')->put($path, $bytes);
        CabinetSetting::current()->update(['logo_path' => $path]);
        DoctorProfile::query()->active()->first()?->update(['logo_path' => $path]);

        return $bytes;
    }

    private function createOrdonnance(): Document
    {
        return app(ClinicalDocumentManager::class)->create($this->consultation, $this->user, [
            'source' => 'built_in',
            'category' => 'ordonnance',
            'paper_size' => 'A5',
            'template_key' => 'ordonnance',
            'title' => 'Ordonnance',
        ], [
            'prescription_items' => [[
                'medication' => 'Paracétamol',
                'dosage' => '500 mg',
                'duration' => '3 jours',
            ]],
        ]);
    }

    private function part(Document $document, string $part): string
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::path((string) $document->file_path)) === true);
        $contents = $zip->getFromName($part);
        $zip->close();
        $this->assertIsString($contents, $part.' is missing from the document.');

        return $contents;
    }

    private function has(Document $document, string $part): bool
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::path((string) $document->file_path)) === true);
        $found = $zip->locateName($part) !== false;
        $zip->close();

        return $found;
    }
}
