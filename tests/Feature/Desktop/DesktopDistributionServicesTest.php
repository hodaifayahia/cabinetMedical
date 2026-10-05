<?php

namespace Tests\Feature\Desktop;

use App\Models\AuditLog;
use App\Models\DesktopRelease;
use App\Models\User;
use App\Services\DesktopDownloadService;
use App\Services\DesktopReleaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class DesktopDistributionServicesTest extends TestCase
{
    use RefreshDatabase;

    private string $suffix;

    /** @var list<string> */
    private array $createdPaths = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->suffix = bin2hex(random_bytes(6));
        config([
            'medismart.desktop_download.url' => null,
            'medismart.desktop_download.installer_path' => 'missing-'.$this->suffix.'.exe',
        ]);
        @mkdir(storage_path('app/private/desktop'), 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->createdPaths) as $path) {
            $this->remove($path);
        }

        parent::tearDown();
    }

    public function test_publishing_rejects_a_blank_signature_before_storing_anything(): void
    {
        $version = $this->version();

        try {
            $this->publish($version, signature: "  \n ");
            $this->fail('A release without a signature was published.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('signature', $exception->getMessage());
        }

        $this->assertDirectoryDoesNotExist(storage_path('app/private/desktop/releases/'.$version));
        $this->assertSame(0, DesktopRelease::query()->count());
    }

    public function test_a_version_that_reduces_to_nothing_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalide');

        $this->publish('..');
    }

    public function test_publishing_trims_input_and_audits_the_actor(): void
    {
        $version = $this->version();
        $actor = User::factory()->create(['is_platform_admin' => true]);

        $release = $this->publish('  '.$version.'  ', signature: '  sig-value  ', actor: $actor);

        $this->assertSame($version, $release->version);
        $this->assertSame('sig-value', $release->signature);
        $this->assertSame($actor->getKey(), $release->published_by_user_id);
        $this->assertSame(DesktopReleaseService::DEFAULT_PLATFORM, $release->platform);
        $this->assertSame(DesktopReleaseService::DEFAULT_CHANNEL, $release->channel);
        $this->assertSame((int) filesize($release->installerFullPath()), $release->installer_size);
        $audit = AuditLog::query()->where('action', 'desktop.release_published')->firstOrFail();
        $this->assertSame($actor->getKey(), $audit->user_id);
        $this->assertSame($release->installer_sha256, $audit->metadata['sha256'] ?? null);
    }

    public function test_republishing_the_same_version_replaces_the_row_instead_of_duplicating_it(): void
    {
        $version = $this->version();
        $first = $this->publish($version, signature: 'first');
        $second = $this->publish($version, signature: 'second', notes: 'Correctif');

        $this->assertTrue($first->is($second));
        $this->assertSame(1, DesktopRelease::query()->count());
        $this->assertSame('second', $second->fresh()->signature);
        $this->assertSame('Correctif', $second->fresh()->notes);
    }

    public function test_the_current_release_is_scoped_to_platform_and_channel(): void
    {
        $service = app(DesktopReleaseService::class);
        $beta = $this->publish($this->version(), channel: 'beta');

        $this->assertNull($service->current());
        $this->assertTrue($beta->is($service->current(DesktopReleaseService::DEFAULT_PLATFORM, 'beta')));
        $this->assertNull($service->current('macos-aarch64', 'beta'));
    }

    public function test_a_release_scheduled_in_the_future_is_not_current_yet(): void
    {
        $release = $this->publish($this->version());
        $release->forceFill(['published_at' => now()->addHour()])->save();

        $this->assertNull(app(DesktopReleaseService::class)->current());
        $this->assertFalse($release->fresh()->isPublished());

        $this->travel(2)->hours();

        $this->assertTrue($release->is(app(DesktopReleaseService::class)->current()));
    }

    public function test_the_manifest_follows_the_tauri_updater_shape(): void
    {
        $release = $this->publish($this->version(), notes: null);

        $manifest = app(DesktopReleaseService::class)->manifest($release);

        $this->assertSame($release->version, $manifest['version']);
        $this->assertSame('', $manifest['notes']);
        $this->assertSame($release->published_at->toIso8601String(), $manifest['pub_date']);
        $this->assertSame([DesktopReleaseService::DEFAULT_PLATFORM], array_keys($manifest['platforms']));
        $this->assertSame($release->signature, $manifest['platforms'][DesktopReleaseService::DEFAULT_PLATFORM]['signature']);
        $this->assertSame(
            route('desktop.updates.artifact', ['release' => $release->getKey()]),
            $manifest['platforms'][DesktopReleaseService::DEFAULT_PLATFORM]['url'],
        );
    }

    public function test_nothing_is_downloadable_without_a_url_or_installer(): void
    {
        $service = app(DesktopDownloadService::class);

        $this->assertFalse($service->hasDownload());
        $this->assertNull($service->externalUrl());
        $this->assertNull($service->localInstallerPath());
        $props = $service->sharedProps();
        $this->assertFalse($props['available']);
        $this->assertNull($props['url']);
        $this->assertNotNull($props['reason']);
    }

    /** @return array<string, array{mixed}> */
    public static function rejectedExternalUrls(): array
    {
        return [
            'whitespace' => ['   '],
            'ftp' => ['ftp://downloads.example.com/setup.exe'],
            'javascript' => ['javascript:alert(1)'],
            'relative' => ['/downloads/setup.exe'],
            'not a string' => [['https://example.com']],
        ];
    }

    #[DataProvider('rejectedExternalUrls')]
    public function test_unsafe_external_urls_are_ignored(mixed $url): void
    {
        config(['medismart.desktop_download.url' => $url]);

        $this->assertNull(app(DesktopDownloadService::class)->externalUrl());
        $this->assertFalse(app(DesktopDownloadService::class)->hasDownload());
    }

    public function test_a_valid_external_url_is_trimmed_and_makes_the_download_available(): void
    {
        config(['medismart.desktop_download.url' => '  https://downloads.example.com/Drclick-Setup.exe ']);
        $service = app(DesktopDownloadService::class);

        $this->assertSame('https://downloads.example.com/Drclick-Setup.exe', $service->externalUrl());
        $this->assertTrue($service->hasDownload());
        $this->assertTrue($service->sharedProps()['available']);
        $this->assertSame(route('desktop.download'), $service->sharedProps()['url']);
        $this->assertNull($service->sharedProps()['reason']);
    }

    /** @return array<string, array{string}> */
    public static function allowedExtensions(): array
    {
        return [
            'exe' => ['exe'],
            'msi' => ['msi'],
            'zip' => ['zip'],
            'dmg' => ['dmg'],
            'AppImage' => ['AppImage'],
        ];
    }

    #[DataProvider('allowedExtensions')]
    public function test_a_relative_installer_with_an_allowed_extension_is_served(string $extension): void
    {
        $name = 'installer-'.$this->suffix.'.'.$extension;
        $path = $this->file(storage_path('app/private/desktop/'.$name));
        config(['medismart.desktop_download.installer_path' => $name]);

        $this->assertSame(realpath($path), app(DesktopDownloadService::class)->localInstallerPath());
        $this->assertTrue(app(DesktopDownloadService::class)->hasDownload());
    }

    public function test_an_installer_with_another_extension_is_refused(): void
    {
        $name = 'installer-'.$this->suffix.'.sh';
        $this->file(storage_path('app/private/desktop/'.$name));
        config(['medismart.desktop_download.installer_path' => $name]);

        $this->assertNull(app(DesktopDownloadService::class)->localInstallerPath());
    }

    public function test_a_relative_path_cannot_climb_out_of_the_desktop_directory(): void
    {
        $outside = $this->file(storage_path('app/private/outside-'.$this->suffix.'.exe'));
        config(['medismart.desktop_download.installer_path' => '../'.basename($outside)]);

        $this->assertNull(app(DesktopDownloadService::class)->localInstallerPath());
    }

    public function test_an_absolute_installer_path_is_trusted_as_operator_configuration(): void
    {
        $absolute = $this->file(sys_get_temp_dir().'/medismart-installer-'.$this->suffix.'.msi');
        config(['medismart.desktop_download.installer_path' => $absolute]);

        $this->assertSame(realpath($absolute), app(DesktopDownloadService::class)->localInstallerPath());
    }

    public function test_an_absolute_installer_path_still_needs_an_allowed_extension(): void
    {
        $absolute = $this->file(sys_get_temp_dir().'/medismart-installer-'.$this->suffix.'.txt');
        config(['medismart.desktop_download.installer_path' => $absolute]);

        $this->assertNull(app(DesktopDownloadService::class)->localInstallerPath());
    }

    public function test_a_published_release_whose_file_disappeared_falls_back_to_the_configured_installer(): void
    {
        $release = $this->publish($this->version());
        unlink($release->installerFullPath());
        $name = 'fallback-'.$this->suffix.'.exe';
        $fallback = $this->file(storage_path('app/private/desktop/'.$name));
        config(['medismart.desktop_download.installer_path' => $name]);

        $this->assertSame(realpath($fallback), app(DesktopDownloadService::class)->localInstallerPath());
    }

    private function version(): string
    {
        $version = '0.0.'.random_int(1000, 999999).'-t'.$this->suffix;
        $this->createdPaths[] = storage_path('app/private/desktop/releases/'.$version);

        return $version;
    }

    private function publish(
        string $version,
        string $signature = 'sig',
        ?string $notes = 'Notes',
        ?User $actor = null,
        string $channel = DesktopReleaseService::DEFAULT_CHANNEL,
    ): DesktopRelease {
        return app(DesktopReleaseService::class)->publish(
            UploadedFile::fake()->create('Drclick_setup.exe', 8),
            $signature,
            $version,
            $notes,
            $actor ?? User::factory()->create(['is_platform_admin' => true]),
            channel: $channel,
        );
    }

    private function file(string $path): string
    {
        file_put_contents($path, 'installer-bytes');
        $this->createdPaths[] = $path;

        return $path;
    }

    private function remove(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach ((array) scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..' && is_string($entry)) {
                $this->remove($path.DIRECTORY_SEPARATOR.$entry);
            }
        }

        @rmdir($path);
    }
}
