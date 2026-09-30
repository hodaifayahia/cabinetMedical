<?php

namespace Tests\Feature\Backups;

use App\Enums\ServerBackupDriveStatus;
use App\Filament\Pages\ServerBackups;
use App\Models\ServerBackupRun;
use App\Models\ServerDriveConnection;
use App\Models\User;
use App\Services\Backups\ServerBackupDrive;
use App\Services\Backups\ServerBackupStatus;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The online service's own nightly backups: the cron script's commands, the
 * Google Drive copy, the PC copy, and the back-office page that says what is
 * missing.
 */
final class ServerBackupTest extends TestCase
{
    use RefreshDatabase;

    private const FILENAME = 'drclick-server-20260925-013000.sqlite.gz.enc';

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();

        $this->travelTo(CarbonImmutable::parse('2026-09-25 09:00:00'));
        $this->directory = storage_path('framework/testing/server-backups');
        File::ensureDirectoryExists($this->directory);
        config()->set('services.google.client_id', 'server-client.apps.googleusercontent.com');
        config()->set('services.google.client_secret', 'server-secret');
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_the_env_command_prints_the_sqlite_database_for_the_script(): void
    {
        [$status, $output] = $this->envCommandFor(['driver' => 'sqlite', 'database' => "/srv/it's/database.sqlite"]);

        $this->assertSame(0, $status);
        $this->assertSame("DRCLICK_DB_DRIVER='sqlite'\nDRCLICK_DB_DATABASE='/srv/it'\\''s/database.sqlite'\n", $output);
    }

    public function test_the_env_command_prints_mariadb_credentials_shell_escaped(): void
    {
        [$status, $output] = $this->envCommandFor([
            'driver' => 'mariadb',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'u1_drclick',
            'username' => 'u1_drclick',
            'password' => "p'a\$s",
        ]);

        $this->assertSame(0, $status);
        $this->assertStringContainsString("DRCLICK_DB_DRIVER='mariadb'", $output);
        $this->assertStringContainsString("DRCLICK_DB_PASSWORD='p'\\''a\$s'", $output);
        $this->assertStringContainsString("DRCLICK_DB_PORT='3306'", $output);
    }

    /**
     * The script evals this output and writes the password into mariadb-dump's
     * option file, so it must come out byte for byte, including text the
     * console would read as style tags or escapes.
     */
    public function test_the_env_command_prints_credentials_untouched_by_the_console_formatter(): void
    {
        foreach (['a<info>b</info>c', 'x</>y', 'p\\<x', 'm\\>n', 'P<fg=Zz>q', 'k<href=x>z', 'a\\\\<b</>c<info>d'] as $password) {
            [$status, $output] = $this->envCommandFor([
                'driver' => 'mariadb',
                'host' => '127.0.0.1',
                'port' => '3306',
                'database' => 'u1<comment>db',
                'username' => 'u1</>user',
                'password' => $password,
            ]);

            $this->assertSame(0, $status, $password);
            $this->assertStringContainsString('DRCLICK_DB_PASSWORD='.escapeshellarg($password)."\n", $output, $password);
            $this->assertStringContainsString("DRCLICK_DB_DATABASE='u1<comment>db'\n", $output);
            $this->assertStringContainsString("DRCLICK_DB_USERNAME='u1</>user'\n", $output);
        }
    }

    public function test_the_env_command_refuses_an_unsupported_driver(): void
    {
        [$status] = $this->envCommandFor(['driver' => 'pgsql']);

        $this->assertSame(1, $status);
    }

    public function test_a_backup_is_recorded_and_skipped_on_drive_when_no_account_is_connected(): void
    {
        Http::fake();
        $path = $this->backupFile();

        $this->artisan('drclick:server-backup:record', ['path' => $path])->assertSuccessful();

        $run = ServerBackupRun::query()->sole();
        $this->assertSame(self::FILENAME, $run->filename);
        $this->assertSame(realpath($path), $run->path);
        $this->assertSame(filesize($path), $run->size_bytes);
        $this->assertSame(hash_file('sha256', $path), $run->sha256);
        $this->assertSame(ServerBackupDriveStatus::SKIPPED, $run->drive_status);
        Http::assertNothingSent();
    }

    public function test_a_badly_named_or_missing_file_is_refused(): void
    {
        $other = $this->directory.'/notes.txt';
        File::put($other, 'x');

        $this->artisan('drclick:server-backup:record', ['path' => $other])->assertFailed();
        $this->artisan('drclick:server-backup:record', ['path' => $this->directory.'/'.self::FILENAME])->assertFailed();
        $this->assertSame(0, ServerBackupRun::query()->count());
    }

    public function test_a_backup_goes_to_the_connected_drive_and_old_copies_are_pruned(): void
    {
        $this->connectDrive(folderId: 'folder-1');
        $path = $this->backupFile();
        $deleted = [];

        Http::fake(function (Request $request) use (&$deleted) {
            $url = $request->url();

            return match (true) {
                $request->method() === 'GET' && str_starts_with($url, 'https://www.googleapis.com/drive/v3/files/folder-1') => Http::response(['id' => 'folder-1', 'trashed' => false]),
                $request->method() === 'POST' && str_contains($url, 'uploadType=resumable') => Http::response([], 200, ['Location' => 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&upload_id=abc']),
                $request->method() === 'PUT' && str_contains($url, 'upload_id=abc') => Http::response(['id' => 'drive-file-9']),
                $request->method() === 'GET' && str_starts_with($url, 'https://www.googleapis.com/drive/v3/files?') => Http::response([
                    'files' => array_map(fn (int $i): array => ['id' => 'old-'.$i], range(1, ServerBackupDrive::KEEP_ON_DRIVE + 2)),
                ]),
                $request->method() === 'DELETE' => (function () use ($url, &$deleted) {
                    $deleted[] = basename($url);

                    return Http::response(null, 204);
                })(),
                default => Http::response(['unexpected' => $url], 500),
            };
        });

        $this->artisan('drclick:server-backup:record', ['path' => $path])->assertSuccessful();

        $run = ServerBackupRun::query()->sole();
        $this->assertSame(ServerBackupDriveStatus::UPLOADED, $run->drive_status);
        $this->assertSame('drive-file-9', $run->drive_file_id);
        $this->assertNotNull($run->drive_uploaded_at);
        $this->assertSame(['old-31', 'old-32'], $deleted);

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && str_contains($request->url(), 'uploadType=resumable')
                && $request['name'] === self::FILENAME
                && $request['parents'] === ['folder-1']
                // Shown in Drive, for a restore from that copy alone.
                && str_contains($request['description'], hash('sha256', 'encrypted-bytes'))
                && $request->hasHeader('X-Upload-Content-Length', (string) strlen('encrypted-bytes'));
        });
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && $request->body() === 'encrypted-bytes');
        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'GET' || ! str_starts_with($request->url(), 'https://www.googleapis.com/drive/v3/files?')) {
                return false;
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return is_string($query['q'] ?? null)
                && str_contains($query['q'], "'folder-1' in parents")
                && str_contains($query['q'], "appProperties has { key='drclick_server_backup' and value='1' }")
                && ($query['orderBy'] ?? null) === 'createdTime desc';
        });
    }

    public function test_a_passing_drive_error_on_the_folder_lookup_fails_the_run_without_a_new_folder(): void
    {
        $connection = $this->connectDrive(folderId: 'folder-1');

        Http::fake(['https://www.googleapis.com/drive/v3/files/folder-1*' => Http::response(['error' => 'backendError'], 503)]);

        $this->artisan('drclick:server-backup:record', ['path' => $this->backupFile()])->assertFailed();

        $run = ServerBackupRun::query()->sole();
        $this->assertSame(ServerBackupDriveStatus::FAILED, $run->drive_status);
        $this->assertSame('Google Drive est momentanément indisponible. Réessayez avec « Envoyer la dernière sauvegarde ».', $run->drive_error);
        // The old folder, and the copies the prune keeps track of, stay in use.
        $this->assertSame('folder-1', $connection->refresh()->folder_id);
        Http::assertSentCount(1);
    }

    public function test_a_deleted_backup_folder_is_replaced(): void
    {
        $connection = $this->connectDrive(folderId: 'folder-1');

        Http::fake(function (Request $request) {
            $url = $request->url();

            return match (true) {
                str_starts_with($url, 'https://www.googleapis.com/drive/v3/files/folder-1') => Http::response(['error' => ['code' => 404]], 404),
                $request->method() === 'POST' && $url === 'https://www.googleapis.com/drive/v3/files?fields=id' => Http::response(['id' => 'folder-2']),
                $request->method() === 'POST' && str_contains($url, 'uploadType=resumable') => Http::response([], 200, ['Location' => 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&upload_id=abc']),
                $request->method() === 'PUT' => Http::response(['id' => 'drive-file-9']),
                $request->method() === 'GET' => Http::response(['files' => []]),
                default => Http::response(['unexpected' => $url], 500),
            };
        });

        $this->artisan('drclick:server-backup:record', ['path' => $this->backupFile()])->assertSuccessful();

        $this->assertSame('folder-2', $connection->refresh()->folder_id);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_contains($request->url(), 'uploadType=resumable')
            && $request['parents'] === ['folder-2']);
    }

    public function test_a_drive_failure_is_recorded_on_the_run_and_fails_the_command(): void
    {
        $this->connectDrive(folderId: 'folder-1');
        $path = $this->backupFile();

        Http::fake([
            'https://www.googleapis.com/drive/v3/files/folder-1*' => Http::response(['id' => 'folder-1']),
            'https://www.googleapis.com/upload/*' => Http::response(['error' => 'quota'], 403),
        ]);

        $this->artisan('drclick:server-backup:record', ['path' => $path])->assertFailed();

        $run = ServerBackupRun::query()->sole();
        $this->assertSame(ServerBackupDriveStatus::FAILED, $run->drive_status);
        $this->assertSame('Google Drive a refusé l’envoi de la sauvegarde.', $run->drive_error);
    }

    public function test_an_expired_access_token_is_refreshed_and_the_new_one_is_used(): void
    {
        $connection = $this->connectDrive(folderId: 'folder-1', expiresAt: now()->subHour());
        $path = $this->backupFile();

        Http::fake(function (Request $request) {
            $url = $request->url();

            return match (true) {
                $url === 'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'fresh', 'expires_in' => 3599]),
                $request->method() === 'GET' && str_starts_with($url, 'https://www.googleapis.com/drive/v3/files/folder-1') => Http::response(['id' => 'folder-1', 'trashed' => false]),
                $request->method() === 'POST' && str_contains($url, 'uploadType=resumable') => Http::response([], 200, ['Location' => 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&upload_id=abc']),
                $request->method() === 'PUT' => Http::response(['id' => 'drive-file-9']),
                $request->method() === 'GET' => Http::response(['files' => []]),
                default => Http::response(['unexpected' => $url], 500),
            };
        });

        $this->artisan('drclick:server-backup:record', ['path' => $path])->assertSuccessful();

        $connection->refresh();
        $this->assertSame('fresh', $connection->access_token);
        $this->assertTrue($connection->token_expires_at?->equalTo(now()->addSeconds(3599)));
        $this->assertSame(ServerBackupDriveStatus::UPLOADED, ServerBackupRun::query()->sole()->drive_status);

        // Folder check, upload session, upload, prune listing: all on the new token.
        $driveCalls = Http::recorded(fn (Request $request): bool => str_starts_with($request->url(), 'https://www.googleapis.com/'));
        $this->assertCount(4, $driveCalls);

        foreach ($driveCalls as [$request]) {
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer fresh'), $request->url());
        }
    }

    public function test_a_revoked_grant_is_reported_when_the_token_is_refreshed(): void
    {
        $this->connectDrive(folderId: 'folder-1', expiresAt: now()->subHour());
        $path = $this->backupFile();

        Http::fake(['https://oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);

        $this->artisan('drclick:server-backup:record', ['path' => $path])->assertFailed();

        $this->assertSame('Google a retiré l’accès au Drive. Reconnectez Google Drive.', ServerBackupRun::query()->sole()->drive_error);
        Http::assertSent(fn (Request $request): bool => $request['grant_type'] === 'refresh_token'
            && $request['client_secret'] === 'server-secret');
    }

    public function test_the_pc_copy_is_recorded_against_its_backup(): void
    {
        $this->artisan('drclick:server-backup:record', ['path' => $this->backupFile()])->assertSuccessful();

        $this->artisan('drclick:server-backup:pc-copy', ['filename' => self::FILENAME])->assertSuccessful();
        $this->artisan('drclick:server-backup:pc-copy', ['filename' => 'drclick-server-20200101-000000.sql.gz.enc'])->assertFailed();
        $this->artisan('drclick:server-backup:pc-copy', ['filename' => '../../etc/passwd'])->assertFailed();

        $this->assertTrue(ServerBackupRun::query()->sole()->pc_copied_at?->equalTo(now()));
    }

    public function test_only_platform_administrators_can_open_the_page(): void
    {
        $this->get(ServerBackups::getUrl())->assertRedirect('/admin/login');

        $this->actingAs(User::factory()->create(['is_platform_admin' => false]))
            ->get(ServerBackups::getUrl())
            ->assertForbidden();
    }

    public function test_the_page_marks_drive_as_required_until_it_is_connected(): void
    {
        $this->actingAs($this->admin());

        $this->get(ServerBackups::getUrl())
            ->assertOk()
            ->assertSee('Requis')
            ->assertSee('Google Drive est requis : connectez le compte Google qui recevra les sauvegardes.')
            ->assertSee('Aucune sauvegarde du serveur n’a encore été faite.', false);
        $this->assertSame('Requis', ServerBackups::getNavigationBadge());

        config()->set('services.google.client_id', '');

        $this->get(ServerBackups::getUrl())
            ->assertOk()
            ->assertSee('la configuration Google (GOOGLE_CLIENT_ID) manque sur ce serveur', false)
            ->assertSee(ServerBackups::getUrl());
    }

    public function test_nothing_is_required_once_drive_and_the_pc_hold_a_fresh_copy(): void
    {
        $this->connectDrive(folderId: 'folder-1');
        ServerBackupRun::query()->create([
            'filename' => self::FILENAME,
            'path' => '/tmp/'.self::FILENAME,
            'size_bytes' => 2048,
            'sha256' => str_repeat('a', 64),
            'database_driver' => 'mariadb',
            'drive_status' => ServerBackupDriveStatus::UPLOADED,
            'drive_uploaded_at' => now()->subHours(7),
            'pc_copied_at' => now()->subHour(),
        ]);

        $this->assertNull(ServerBackups::getNavigationBadge());

        $this->actingAs($this->admin())
            ->get(ServerBackups::getUrl())
            ->assertOk()
            ->assertDontSee('Requis')
            ->assertSee('backup@clinic.test');
    }

    public function test_a_backup_that_never_reached_the_connected_drive_is_required(): void
    {
        $this->connectDrive(folderId: 'folder-1');
        $this->backupRun(now()->subDay(), ['pc_copied_at' => now()->subHours(16)]);
        $run = $this->backupRun(now()->subMinutes(20), ['drive_status' => ServerBackupDriveStatus::PENDING, 'drive_uploaded_at' => null]);

        // Still being sent.
        $this->assertNull(ServerBackups::getNavigationBadge());

        // The upload was cut off and the run never left PENDING.
        $this->travel(2)->hours();

        $this->assertSame('Requis', ServerBackups::getNavigationBadge());
        $this->assertContains(
            'La dernière sauvegarde n’est pas sur Google Drive. Utilisez « Envoyer la dernière sauvegarde ».',
            array_column(app(ServerBackupStatus::class)->problems(), 'message'),
        );

        // Made while no account was connected; Drive was connected since.
        $run->update(['drive_status' => ServerBackupDriveStatus::SKIPPED]);

        $this->assertSame('Requis', ServerBackups::getNavigationBadge());
    }

    public function test_a_missing_pc_copy_is_required_after_a_week(): void
    {
        $this->connectDrive(folderId: 'folder-1');
        $this->backupRun(now()->subDays(8));
        $latest = $this->backupRun(now()->subHours(7));
        $status = app(ServerBackupStatus::class);

        // Never copied, and the first backup is eight days old.
        $this->assertSame([['level' => 'danger', 'message' => 'Aucune copie n’a encore été récupérée sur le PC Windows.']], $status->problems());
        $this->assertSame('Requis', ServerBackups::getNavigationBadge());

        $latest->update(['pc_copied_at' => now()->subDays(4)]);

        $this->assertSame([['level' => 'warning', 'message' => 'Le PC Windows n’a pas récupéré de copie depuis 4 jours.']], $status->problems());
        $this->assertNull(ServerBackups::getNavigationBadge());

        $latest->update(['pc_copied_at' => now()->subDays(8)]);

        $this->assertSame(['danger'], array_column($status->problems(), 'level'));
        $this->assertSame('Requis', ServerBackups::getNavigationBadge());
    }

    public function test_connecting_drive_round_trips_through_google_with_pkce(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        Livewire::test(ServerBackups::class)
            ->callAction('connectDrive')
            ->assertRedirectContains('https://accounts.google.com/o/oauth2/v2/auth?');

        $pending = session('server_backup.google_oauth');
        $this->assertIsArray($pending);
        $this->assertSame(ServerBackups::getUrl(), $pending['redirect_uri']);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'access-1',
                'refresh_token' => 'refresh-1',
                'expires_in' => 3599,
            ]),
            'https://www.googleapis.com/drive/v3/about*' => Http::response(['user' => ['emailAddress' => 'owner@gmail.test']]),
        ]);

        $this->get(ServerBackups::getUrl().'?'.http_build_query(['code' => 'code-1', 'state' => $pending['state']]))
            ->assertRedirect(ServerBackups::getUrl());

        $connection = ServerDriveConnection::query()->sole();
        $this->assertSame('owner@gmail.test', $connection->email);
        $this->assertSame('refresh-1', $connection->refresh_token);
        $this->assertSame($admin->getKey(), $connection->connected_by);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://oauth2.googleapis.com/token'
            && $request['code_verifier'] === $pending['verifier']
            && $request['redirect_uri'] === ServerBackups::getUrl());
        $this->assertNull(session('server_backup.google_oauth'));
    }

    public function test_a_callback_with_a_foreign_state_connects_nothing(): void
    {
        $this->actingAs($this->admin());
        Http::fake();

        $this->withSession(['server_backup.google_oauth' => [
            'state' => 'expected-state',
            'verifier' => str_repeat('v', 64),
            'redirect_uri' => ServerBackups::getUrl(),
            'expires_at' => now()->addMinutes(5)->getTimestamp(),
        ]])->get(ServerBackups::getUrl().'?code=code-1&state=forged-state')
            ->assertRedirect(ServerBackups::getUrl());

        $this->assertSame(0, ServerDriveConnection::query()->count());
        Http::assertNothingSent();
    }

    public function test_an_unreachable_google_during_the_callback_shows_a_notice_instead_of_an_error(): void
    {
        $this->actingAs($this->admin());
        Http::fake(['https://oauth2.googleapis.com/token' => Http::failedConnection()]);

        $this->withSession(['server_backup.google_oauth' => [
            'state' => 'expected-state',
            'verifier' => str_repeat('v', 64),
            'redirect_uri' => ServerBackups::getUrl(),
            'expires_at' => now()->addMinutes(5)->getTimestamp(),
        ]])->get(ServerBackups::getUrl().'?code=code-1&state=expected-state')
            ->assertRedirect(ServerBackups::getUrl());

        $this->assertSame(0, ServerDriveConnection::query()->count());
        $this->assertContains(
            'Google est injoignable depuis le serveur. Recommencez.',
            array_column((array) session('filament.notifications'), 'body'),
        );
    }

    public function test_disconnecting_forgets_the_grant_and_revokes_it_at_google(): void
    {
        $this->connectDrive(folderId: 'folder-1');
        $this->actingAs($this->admin());
        Http::fake(['https://oauth2.googleapis.com/revoke' => Http::response()]);

        Livewire::test(ServerBackups::class)->callAction('disconnectDrive');

        $this->assertSame(0, ServerDriveConnection::query()->count());
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://oauth2.googleapis.com/revoke'
            && $request['token'] === 'refresh-token');
    }

    /**
     * Run the env command against a made-up default connection. The command
     * only reads config; the real default comes back before the test's
     * database transaction is rolled back.
     *
     * @param  array<string, mixed>  $connection
     * @return array{0: int, 1: string}
     */
    private function envCommandFor(array $connection): array
    {
        $default = config('database.default');
        config()->set('database.connections.backup_probe', $connection);
        config()->set('database.default', 'backup_probe');

        try {
            $status = Artisan::call('drclick:server-backup:env');

            return [$status, Artisan::output()];
        } finally {
            config()->set('database.default', $default);
        }
    }

    private function admin(): User
    {
        return User::factory()->create(['is_platform_admin' => true]);
    }

    private function backupFile(): string
    {
        $path = $this->directory.'/'.self::FILENAME;
        File::put($path, 'encrypted-bytes');

        return $path;
    }

    /**
     * A run the cron made at a given time, on Drive unless told otherwise.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function backupRun(\DateTimeInterface $at, array $attributes = []): ServerBackupRun
    {
        $filename = 'drclick-server-'.$at->format('Ymd-His').'.sql.gz.enc';
        $run = new ServerBackupRun(array_merge([
            'filename' => $filename,
            'path' => '/tmp/'.$filename,
            'size_bytes' => 2048,
            'sha256' => str_repeat('a', 64),
            'database_driver' => 'mariadb',
            'drive_status' => ServerBackupDriveStatus::UPLOADED,
            'drive_uploaded_at' => $at,
        ], $attributes));
        $run->created_at = $at;
        $run->updated_at = $at;
        $run->save();

        return $run;
    }

    private function connectDrive(?string $folderId = null, ?\DateTimeInterface $expiresAt = null): ServerDriveConnection
    {
        return ServerDriveConnection::query()->create([
            'email' => 'backup@clinic.test',
            'folder_id' => $folderId,
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => $expiresAt ?? now()->addHour(),
        ]);
    }
}
