<?php

namespace Tests\Feature\Desktop;

use App\Models\DesktopRelease;
use App\Models\User;
use App\Services\DesktopDownloadService;
use App\Services\DesktopReleaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Publishing a build and getting it to every installed copy.
 *
 * The contract under test is the one shipped shells already depend on: they
 * poll a fixed URL, expect Tauri's manifest shape, and refuse anything whose
 * signature does not verify. None of that can be changed after a build is in
 * someone's hands, so these tests pin it.
 */
class DesktopUpdateDistributionTest extends TestCase
{
    use RefreshDatabase;

    private string $releasesRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->releasesRoot = storage_path('app/private/desktop/releases');
    }

    protected function tearDown(): void
    {
        // These tests write real files, so leave the tree as we found it.
        $this->deleteDirectory($this->releasesRoot.DIRECTORY_SEPARATOR.'9.9.9');
        $this->deleteDirectory($this->releasesRoot.DIRECTORY_SEPARATOR.'9.9.10');

        parent::tearDown();
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach ((array) glob($path.DIRECTORY_SEPARATOR.'*') as $file) {
            if (is_string($file) && is_file($file)) {
                @unlink($file);
            }
        }

        @rmdir($path);
    }

    private function publish(string $version = '9.9.9', string $signature = 'test-signature'): DesktopRelease
    {
        $publisher = User::factory()->create(['is_platform_admin' => true]);

        return app(DesktopReleaseService::class)->publish(
            UploadedFile::fake()->create('Drclick_'.$version.'_x64-setup.nsis.zip', 16),
            $signature,
            $version,
            'Corrections et améliorations.',
            $publisher,
        );
    }

    public function test_the_manifest_reports_no_update_before_anything_is_published(): void
    {
        // 204 is how tauri-plugin-updater reads "you are already current".
        $this->get('/desktop-updates')->assertNoContent();
    }

    public function test_the_manifest_matches_the_shape_the_shell_expects(): void
    {
        $release = $this->publish();

        $response = $this->get('/desktop-updates')->assertOk();

        $response->assertJsonPath('version', '9.9.9');
        $response->assertJsonPath('notes', 'Corrections et améliorations.');
        $response->assertJsonPath('platforms.windows-x86_64.signature', 'test-signature');
        $response->assertJsonPath(
            'platforms.windows-x86_64.url',
            route('desktop.updates.artifact', ['release' => $release->getKey()]),
        );
        $this->assertIsString($response->json('pub_date'));
    }

    public function test_the_endpoint_shipped_builds_poll_still_matches(): void
    {
        // Shipped shells have the trailing slash compiled in and cannot be
        // told to look anywhere else, so this path must keep working.
        $this->publish();

        $this->get('/desktop-updates/')->assertOk();
    }

    public function test_the_newest_published_release_wins(): void
    {
        $this->publish('9.9.9');
        $newer = $this->publish('9.9.10');

        $this->get('/desktop-updates')->assertOk()->assertJsonPath('version', '9.9.10');

        $this->assertSame('9.9.10', app(DesktopReleaseService::class)->current()?->version);
        $this->assertNotNull($newer->published_at);
    }

    public function test_an_unpublished_release_is_never_offered_or_downloadable(): void
    {
        $release = $this->publish();
        $release->forceFill(['published_at' => null])->save();

        $this->get('/desktop-updates')->assertNoContent();
        $this->get(route('desktop.updates.artifact', ['release' => $release->getKey()]))
            ->assertNotFound();
    }

    public function test_the_artifact_downloads_and_is_the_file_that_was_published(): void
    {
        $release = $this->publish();

        $response = $this->get(route('desktop.updates.artifact', ['release' => $release->getKey()]))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertSame(
            $release->installer_sha256,
            hash('sha256', $response->streamedContent()),
            'the bytes served must be the bytes that were checksummed at publish time',
        );
    }

    public function test_a_fresh_download_gets_the_published_release(): void
    {
        // The whole point of one source of truth: someone downloading for the
        // first time must not receive an older build than the updater offers.
        $release = $this->publish();

        $this->assertSame(
            realpath($release->installerFullPath()),
            app(DesktopDownloadService::class)->localInstallerPath(),
        );
    }

    public function test_publishing_records_the_signature_and_checksum(): void
    {
        $release = $this->publish(signature: 'dW50cnVzdGVkIGNvbW1lbnQ6IHNpZ25hdHVyZQ==');

        $this->assertSame('dW50cnVzdGVkIGNvbW1lbnQ6IHNpZ25hdHVyZQ==', $release->signature);
        $this->assertSame(64, strlen($release->installer_sha256));
        $this->assertFileExists($release->installerFullPath());
        $this->assertSame(
            hash_file('sha256', $release->installerFullPath()),
            $release->installer_sha256,
        );
    }

    public function test_a_hostile_filename_cannot_escape_the_releases_directory(): void
    {
        $publisher = User::factory()->create(['is_platform_admin' => true]);

        $release = app(DesktopReleaseService::class)->publish(
            UploadedFile::fake()->create('../../evil.exe', 4),
            'sig',
            '9.9.9',
            null,
            $publisher,
        );

        $this->assertStringNotContainsString('..', $release->installer_path);
        $this->assertStringStartsWith(
            realpath($this->releasesRoot).DIRECTORY_SEPARATOR,
            (string) realpath($release->installerFullPath()),
        );
    }
}
