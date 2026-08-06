<?php

namespace Tests\Feature\Consultations;

use App\ClinicalDocuments\ClinicalDocumentManager;
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
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use ZipArchive;

class DocumentBrandingIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_persisted_branding_flows_to_the_workspace_and_word_document(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create(['name' => 'Dr Nadia Amrane']);
        $user->assignRole(RoleName::ADMINISTRATOR->value);
        DoctorProfile::factory()->for($user)->create([
            'specialty' => 'Cardiologie',
            'professional_identifier' => 'ORD-2048',
        ]);

        $cabinet = CabinetSetting::current();
        $cabinet->update([
            'name' => 'Clinique Atlas الشفاء',
            'phone' => '021 11 22 33',
            'email' => 'contact@atlas.test',
            'address' => '12 rue Didouche Mourad — Liberté',
            'city' => 'الجزائر',
            'prescription_footer' => 'Sur rendez-vous — الاستقبال',
            'logo_path' => 'clinic/atlas-logo.png',
        ]);

        Storage::fake('local');
        Storage::fake('public');
        $logo = UploadedFile::fake()->image('atlas-logo.png', 200, 100)->getContent();
        Storage::disk('public')->put('clinic/atlas-logo.png', $logo);

        $patient = Patient::factory()->create();
        $consultation = Consultation::query()->create([
            'patient_id' => $patient->getKey(),
            'consulted_at' => now(),
            'status' => 'in_progress',
            'created_by' => $user->getKey(),
        ]);

        $this->actingAs($user)
            ->get(route('app.consultations.show', $consultation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('cabinet.doctor_name', 'Dr Nadia Amrane')
                ->where('cabinet.specialty', 'Cardiologie')
                ->where('cabinet.order_number', 'ORD-2048')
                ->where('cabinet.clinic_name', 'Clinique Atlas الشفاء')
                ->where('cabinet.phone', '021 11 22 33')
                ->where('cabinet.email', 'contact@atlas.test')
                ->where('cabinet.address', '12 rue Didouche Mourad — Liberté')
                ->where('cabinet.city', 'الجزائر')
                ->where('cabinet.logo_url', Storage::disk('public')->url('clinic/atlas-logo.png'))
                ->where(
                    'cabinet.footer',
                    'Tél. 021 11 22 33 | E-mail contact@atlas.test | Adresse : 12 rue Didouche Mourad — Liberté, الجزائر | Sur rendez-vous — الاستقبال',
                ),
            );

        $this->actingAs($user)
            ->post(route('app.consultations.word-documents.store', $consultation), [
                'source' => 'built_in',
                'category' => 'courrier',
                'template_key' => 'certificat-bonne-sante',
                'paper_size' => 'A4',
            ])
            ->assertRedirect();

        $document = Document::query()->sole();
        $documentXml = $this->wordPart($document, 'word/document.xml');
        $footerXml = $this->wordPart($document, 'word/footer1.xml');

        $this->assertStringContainsString('Dr Nadia Amrane', $documentXml);
        $this->assertStringContainsString('Cardiologie', $documentXml);
        $this->assertStringContainsString('ORD-2048', $documentXml);
        $this->assertStringContainsString('Clinique Atlas الشفاء', $documentXml);
        $this->assertStringContainsString('Clinique Atlas الشفاء', $footerXml);
        $this->assertStringContainsString('contact@atlas.test', $footerXml);
        $this->assertStringContainsString('12 rue Didouche Mourad — Liberté, الجزائر', $footerXml);
        $this->assertStringContainsString('Sur rendez-vous — الاستقبال', $footerXml);
        $this->assertSame($logo, $this->wordPart($document, 'word/media/clinic-logo.png'));
        $this->assertStringContainsString('rId3', $this->wordPart($document, 'word/_rels/document.xml.rels'));
    }

    public function test_one_identity_update_flows_to_every_new_clinical_document_without_rewriting_existing_files(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
        Storage::fake('public');

        $user = User::factory()->create(['name' => 'Dr Élodie Mansouri']);
        $user->assignRole(RoleName::ADMINISTRATOR->value);
        $doctor = DoctorProfile::factory()->for($user)->create([
            'specialty' => 'Médecine interne — طب داخلي',
            'professional_identifier' => 'ORD-ATLAS-100',
        ]);
        $cabinet = CabinetSetting::current();
        $firstLogo = UploadedFile::fake()->image('atlas.png', 180, 90)->getContent();
        Storage::disk('public')->put('cabinet/atlas.png', $firstLogo);
        $cabinet->update([
            'name' => 'Clinique Atlas Étoile',
            'phone' => '021 00 11 22',
            'email' => 'atlas@example.test',
            'address' => '4, boulevard de l’Indépendance',
            'city' => 'الجزائر',
            'prescription_footer' => 'Accueil — الطابق الأول',
            'logo_path' => 'cabinet/atlas.png',
        ]);

        $patient = Patient::factory()->create();
        $consultation = Consultation::query()->create([
            'patient_id' => $patient->getKey(),
            'consulted_at' => now(),
            'status' => 'in_progress',
            'created_by' => $user->getKey(),
        ]);
        $manager = app(ClinicalDocumentManager::class);
        $firstDocuments = $this->createEveryClinicalCategory($manager, $consultation, $user);

        foreach ($firstDocuments as $document) {
            $documentXml = $this->wordPart($document, 'word/document.xml');
            $footerXml = $this->wordPart($document, 'word/footer1.xml');

            $this->assertStringContainsString('Clinique Atlas Étoile', $documentXml);
            $this->assertStringContainsString('Dr Élodie Mansouri', $documentXml);
            $this->assertStringContainsString('Médecine interne — طب داخلي', $documentXml);
            $this->assertStringContainsString('ORD-ATLAS-100', $documentXml);
            $this->assertStringContainsString('الجزائر', $footerXml);
            $this->assertStringContainsString('Accueil — الطابق الأول', $footerXml);
            $this->assertSame($firstLogo, $this->wordPart($document, 'word/media/clinic-logo.png'));
        }

        $secondLogo = UploadedFile::fake()->image('nour.jpg', 120, 180)->getContent();
        Storage::disk('public')->put('cabinet/nour.jpg', $secondLogo);
        $user->update(['name' => 'Dr Leïla Haddad']);
        $doctor->correctLockedSpecialty('Cardiologie pédiatrique', 'cardiologie_pediatrique', $user);
        $doctor->update([
            'professional_identifier' => 'ORD-NOUR-200',
        ]);
        $cabinet->update([
            'name' => 'Cabinet Nour الشفاء',
            'phone' => '041 33 44 55',
            'email' => 'nour@example.test',
            'address' => '8 avenue des Oliviers',
            'city' => 'وهران',
            'prescription_footer' => 'Sur rendez-vous uniquement',
            'logo_path' => 'cabinet/nour.jpg',
        ]);

        $updatedDocuments = $this->createEveryClinicalCategory($manager, $consultation, $user);

        foreach ($updatedDocuments as $document) {
            $documentXml = $this->wordPart($document, 'word/document.xml');
            $footerXml = $this->wordPart($document, 'word/footer1.xml');

            $this->assertStringContainsString('Cabinet Nour الشفاء', $documentXml);
            $this->assertStringContainsString('Dr Leïla Haddad', $documentXml);
            $this->assertStringContainsString('Cardiologie pédiatrique', $documentXml);
            $this->assertStringContainsString('ORD-NOUR-200', $documentXml);
            $this->assertStringContainsString('8 avenue des Oliviers, وهران', $footerXml);
            $this->assertStringContainsString('Sur rendez-vous uniquement', $footerXml);
            $this->assertStringNotContainsString('Clinique Atlas Étoile', $documentXml);
            $this->assertSame($secondLogo, $this->wordPart($document, 'word/media/clinic-logo.jpg'));
        }

        foreach ($firstDocuments as $document) {
            $documentXml = $this->wordPart($document, 'word/document.xml');

            $this->assertStringContainsString('Clinique Atlas Étoile', $documentXml);
            $this->assertStringNotContainsString('Cabinet Nour الشفاء', $documentXml);
            $this->assertSame($firstLogo, $this->wordPart($document, 'word/media/clinic-logo.png'));
        }
    }

    /** @return list<Document> */
    private function createEveryClinicalCategory(
        ClinicalDocumentManager $manager,
        Consultation $consultation,
        User $user,
    ): array {
        $selections = [
            [
                'source' => 'built_in',
                'category' => 'ordonnance',
                'paper_size' => 'A5',
                'template_key' => 'ordonnance',
                'title' => 'Ordonnance',
            ],
            [
                'source' => 'built_in',
                'category' => 'bilan',
                'paper_size' => 'A4',
                'template_key' => 'bilan',
                'title' => 'Bilan biologique',
            ],
            [
                'source' => 'built_in',
                'category' => 'courrier',
                'paper_size' => 'A4',
                'template_key' => 'rapport-ecg',
                'title' => 'Rapport ECG',
            ],
        ];

        return array_map(
            fn (array $selection): Document => $manager->create(
                $consultation,
                $user,
                $selection,
                [
                    'prescription_items' => [[
                        'medication' => 'Paracétamol',
                        'dosage' => '500 mg',
                        'duration' => '3 jours',
                        'instructions' => 'Après le repas',
                    ]],
                    'prescription_notes' => 'Tolérance à surveiller',
                ],
            ),
            $selections,
        );
    }

    private function wordPart(Document $document, string $part): string
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::path($document->file_path)) === true);
        $xml = $zip->getFromName($part);
        $zip->close();
        $this->assertIsString($xml);

        return $xml;
    }
}
