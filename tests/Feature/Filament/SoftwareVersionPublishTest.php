<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\SoftwareVersion;
use App\Models\DesktopRelease;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\DesktopDownloadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Livewire;
use Tests\TestCase;

class SoftwareVersionPublishTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $directory = storage_path('app/private/desktop/releases/9.8.7');

        foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);

        parent::tearDown();
    }

    public function test_the_back_office_accepts_an_installer_much_larger_than_livewire_s_default(): void
    {
        $this->assertContains(
            'max:'.AppServiceProvider::BACK_OFFICE_UPLOAD_MAX_KILOBYTES,
            FileUploadConfiguration::rules(),
        );
        $this->assertGreaterThan(100 * 1024, AppServiceProvider::BACK_OFFICE_UPLOAD_MAX_KILOBYTES);
    }

    public function test_the_version_is_read_from_a_build_s_installer_name(): void
    {
        $this->assertSame('0.4.7', SoftwareVersion::versionInInstallerName('Drclick_0.4.7_x64-setup.exe'));
        $this->assertSame('1.2.0-beta.1', SoftwareVersion::versionInInstallerName('Drclick_1.2.0-beta.1_x64-setup.exe'));
        $this->assertNull(SoftwareVersion::versionInInstallerName('setup.exe'));
    }

    public function test_a_version_number_that_differs_from_the_installer_is_refused(): void
    {
        $this->actingAs(User::factory()->create(['is_platform_admin' => true]));

        Livewire::test(SoftwareVersion::class)
            ->callAction('publish', [
                'version' => '1.4.7',
                'installer' => UploadedFile::fake()->create('Drclick_9.8.7_x64-setup.exe', 64),
                'signature' => 'dW50cnVzdGVkIGNvbW1lbnQ6IHNpZ25hdHVyZQ==',
            ])
            ->assertNotified('Numéro de version différent de l’installateur');

        $this->assertSame(0, DesktopRelease::query()->count());
    }

    public function test_a_published_installer_keeps_its_name_and_becomes_the_website_download(): void
    {
        $this->actingAs(User::factory()->create(['is_platform_admin' => true]));

        Livewire::test(SoftwareVersion::class)
            ->callAction('publish', [
                'version' => '9.8.7',
                'installer' => UploadedFile::fake()->create('Drclick_9.8.7_x64-setup.exe', 64),
                'signature' => 'dW50cnVzdGVkIGNvbW1lbnQ6IHNpZ25hdHVyZQ==',
            ])
            ->assertHasNoActionErrors();

        $release = DesktopRelease::query()->sole();
        $this->assertSame('Drclick_9.8.7_x64-setup.exe', $release->installer_name);
        $this->assertSame(
            'Drclick_9.8.7_x64-setup.exe',
            basename((string) app(DesktopDownloadService::class)->localInstallerPath()),
        );
    }
}
