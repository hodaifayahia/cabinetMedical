<?php

namespace Tests\Feature\Configuration;

use App\ClinicalDocuments\ClinicalDocumentManager;
use App\Enums\RoleName;
use App\Models\CabinetSetting;
use App\Models\Consultation;
use App\Models\DoctorProfile;
use App\Models\Document;
use App\Models\Patient;
use App\Models\User;
use App\Services\DocumentBrandingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use ZipArchive;

/**
 * Replacing the clinic logo in Configuration must reach every sheet the
 * cabinet prints — the ordonnance and the other consultation documents, the
 * payment receipt and report, the appointment list, and newly generated Word
 * files — without anybody clearing a cache or restarting the desktop app.
 */
class ClinicLogoPropagationTest extends TestCase
{
    use RefreshDatabase;

    public function test_replacing_the_clinic_logo_reaches_every_printable_surface(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
        Storage::fake('public');

        $user = User::factory()->create(['name' => 'Dr Nadia Amrane']);
        $user->assignRole(RoleName::ADMINISTRATOR->value);
        DoctorProfile::factory()->for($user)->create([
            'specialty' => 'Cardiologie',
            'professional_identifier' => 'ORD-2048',
        ]);
        $this->actingAs($user);

        $patient = Patient::factory()->create();
        $consultation = Consultation::query()->create([
            'patient_id' => $patient->getKey(),
            'consulted_at' => now(),
            'status' => 'in_progress',
            'created_by' => $user->getKey(),
        ]);

        $firstLogoPath = $this->uploadLogo('first-logo.png', 200, 100);
        $firstLogoUrl = Storage::disk('public')->url($firstLogoPath);
        $firstOrdonnance = $this->createOrdonnance($consultation, $user);

        $this->assertPrintableSurfacesShow($consultation, $firstLogoUrl);
        $this->assertSame(
            Storage::disk('public')->get($firstLogoPath),
            $this->wordLogo($firstOrdonnance, 'png'),
        );

        // The doctor changes the logo in Configuration.
        $secondLogoPath = $this->uploadLogo('second-logo.jpg', 120, 180);
        $secondLogoUrl = Storage::disk('public')->url($secondLogoPath);
        $this->assertNotSame($firstLogoPath, $secondLogoPath);

        $this->assertPrintableSurfacesShow($consultation, $secondLogoUrl, $firstLogoUrl);
        $this->assertSame(
            Storage::disk('public')->get($secondLogoPath),
            $this->wordLogo($this->createOrdonnance($consultation, $user), 'jpg'),
        );

        // Removing the logo falls back to the packaged mark everywhere.
        $this->delete(route('app.configuration.identity.logo.destroy'))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertPrintableSurfacesShow(
            $consultation,
            DocumentBrandingService::DEFAULT_LOGO_URL,
            $secondLogoUrl,
        );
    }

    private function uploadLogo(string $name, int $width, int $height): string
    {
        $this->post(route('app.configuration.identity.update'), [
            'clinic_name' => 'Clinique Atlas',
            'logo' => UploadedFile::fake()->image($name, $width, $height),
        ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $path = CabinetSetting::current()->refresh()->logo_path;
        $this->assertIsString($path);
        Storage::disk('public')->assertExists($path);

        return $path;
    }

    private function assertPrintableSurfacesShow(
        Consultation $consultation,
        string $expectedUrl,
        ?string $staleUrl = null,
    ): void {
        // Ordonnance / bilan / courrier editors on the consultation workspace.
        $this->get(route('app.consultations.show', $consultation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('cabinet.logo_url', $expectedUrl));

        $printViews = [
            route('app.payments.receipt', $consultation),
            route('app.payments.print'),
            route('app.appointments.print'),
        ];

        foreach ($printViews as $url) {
            $response = $this->get($url)->assertOk()->assertSee($expectedUrl, false);

            if ($staleUrl !== null) {
                $response->assertDontSee($staleUrl, false);
            }
        }

        // The Configuration screen echoes the same logo back, and the shared
        // app chrome resolves the very same file (it shows nothing at all
        // rather than the packaged mark once the custom logo is removed).
        $chromeUrl = $expectedUrl === DocumentBrandingService::DEFAULT_LOGO_URL
            ? null
            : $expectedUrl;

        $this->get(route('app.configuration.identity.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('identity.logo_url', $expectedUrl)
                ->where('cabinet.logo_url', $chromeUrl));
    }

    private function createOrdonnance(Consultation $consultation, User $user): Document
    {
        return app(ClinicalDocumentManager::class)->create($consultation, $user, [
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

    private function wordLogo(Document $document, string $extension): string
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::path((string) $document->file_path)) === true);
        $bytes = $zip->getFromName('word/media/clinic-logo.'.$extension);
        $zip->close();
        $this->assertIsString($bytes);

        return $bytes;
    }
}
