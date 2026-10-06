<?php

namespace Tests\Feature\Backups;

use App\Jobs\UploadBackupToGoogleDrive;
use App\Models\BackupRecord;
use App\Models\CabinetSetting;
use App\Models\DriveBackupConnection;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Why "saving to Google Drive does not work" on a real clinic: archives of
 * hundreds of megabytes, slow lines, a backup folder deleted by the doctor
 * and a grant Google stopped honouring. Each must either succeed or say
 * clearly what the doctor has to do.
 */
class GoogleDriveUploadReliabilityTest extends TestCase
{
    use RefreshDatabase;

    private const MAGIC = "MEDISMART-MSBAK\x02";

    private const SESSION_URL = 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&upload_id=session-1';

    /** @var list<string> */
    private array $createdFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        File::ensureDirectoryExists(storage_path('app/private/backups'));
        Http::preventStrayRequests();
        Sleep::fake();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_a_large_archive_is_sent_in_resumable_chunks_and_survives_an_interruption(): void
    {
        config([
            'medismart.backups.drive_resumable_threshold_bytes' => 16,
            'medismart.backups.drive_upload_chunk_bytes' => 16,
        ]);
        $cabinet = CabinetSetting::current();
        $bytes = self::MAGIC.str_repeat('encrypted-payload-', 3);
        $record = $this->archive($bytes);
        $this->connectedDrive($cabinet);
        $received = [];
        $failedOnce = false;
        $sessionMetadata = null;

        Http::fake(function (Request $request) use ($bytes, &$received, &$failedOnce, &$sessionMetadata) {
            $url = $request->url();

            if ($request->method() === 'GET' && str_contains($url, '/drive/v3/files/drive-folder-id')) {
                return Http::response(['id' => 'drive-folder-id', 'mimeType' => 'application/vnd.google-apps.folder', 'trashed' => false]);
            }

            if ($request->method() === 'GET' && str_contains($url, '/drive/v3/files')) {
                return Http::response(['files' => []]);
            }

            if ($request->method() === 'POST' && str_contains($url, 'uploadType=resumable')) {
                $sessionMetadata = json_decode($request->body(), true);

                return Http::response([], 200, ['Location' => self::SESSION_URL]);
            }

            if ($request->method() === 'PUT' && $url === self::SESSION_URL) {
                $range = $request->header('Content-Range')[0] ?? '';

                if (preg_match('/\Abytes \*\/(\d+)\z/', $range) === 1) {
                    return Http::response('', 308, ['Range' => 'bytes=0-'.(strlen(implode('', $received)) - 1)]);
                }

                preg_match('/\Abytes (\d+)-(\d+)\/(\d+)\z/', $range, $matches);
                $this->assertSame(strlen($bytes), (int) $matches[3]);

                // The second chunk is lost on the way once.
                if ((int) $matches[1] === 16 && ! $failedOnce) {
                    $failedOnce = true;

                    return Http::response(['error' => 'backend error'], 503);
                }

                $received[(int) $matches[1]] = $request->body();
                $held = strlen(implode('', $received));

                if ($held === strlen($bytes)) {
                    return Http::response(['id' => 'remote-resumable-id', 'name' => 'Drclick encrypted backup']);
                }

                return Http::response('', 308, ['Range' => 'bytes=0-'.($held - 1)]);
            }

            return Http::response(['error' => 'unexpected request'], 500);
        });

        (new UploadBackupToGoogleDrive((int) $cabinet->getKey(), (string) $record->getKey(), 'Drclick Backups'))
            ->handle(app(GoogleDriveService::class));

        $record->refresh();
        $this->assertTrue($failedOnce);
        $this->assertSame($bytes, implode('', $received));
        $this->assertSame('remote-resumable-id', $record->remote_file_id);
        $this->assertSame(BackupRecord::DRIVE_UPLOAD_COMPLETED, $record->drive_upload_status);
        $this->assertSame(['drive-folder-id'], $sessionMetadata['parents'] ?? null);
        $this->assertSame($record->sha256, $sessionMetadata['appProperties']['medismart_sha256'] ?? null);
        // The session URL carries the upload; the OAuth token is not sent to it.
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT'
            && $request->hasHeader('Authorization'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'uploadType=multipart'));
    }

    public function test_a_backup_folder_deleted_on_drive_is_created_again(): void
    {
        $cabinet = CabinetSetting::current();
        $record = $this->archive(self::MAGIC.'small-encrypted-archive');
        $this->connectedDrive($cabinet);
        $uploadedTo = null;

        Http::fake(function (Request $request) use (&$uploadedTo) {
            $url = $request->url();

            if ($request->method() === 'GET' && str_contains($url, '/drive/v3/files/drive-folder-id')) {
                return Http::response(['id' => 'drive-folder-id', 'mimeType' => 'application/vnd.google-apps.folder', 'trashed' => true]);
            }

            if ($request->method() === 'GET' && str_contains($url, '/drive/v3/files')) {
                return Http::response(['files' => []]);
            }

            if ($request->method() === 'POST' && $url === 'https://www.googleapis.com/drive/v3/files') {
                return Http::response(['id' => 'new-folder-id']);
            }

            if ($request->method() === 'POST' && str_contains($url, 'uploadType=multipart')) {
                $uploadedTo = str_contains($request->body(), '"new-folder-id"') ? 'new-folder-id' : 'other';

                return Http::response(['id' => 'remote-file-id', 'name' => 'Drclick encrypted backup']);
            }

            return Http::response(['error' => 'unexpected request'], 500);
        });

        (new UploadBackupToGoogleDrive((int) $cabinet->getKey(), (string) $record->getKey(), 'Drclick Backups'))
            ->handle(app(GoogleDriveService::class));

        $this->assertSame('new-folder-id', $uploadedTo);
        $this->assertSame('new-folder-id', DriveBackupConnection::query()->sole()->folder_id);
        $this->assertSame('remote-file-id', $record->refresh()->remote_file_id);
    }

    public function test_a_grant_refused_by_google_asks_the_doctor_to_reconnect_instead_of_retrying(): void
    {
        $cabinet = CabinetSetting::current();
        $record = $this->archive(self::MAGIC.'small-encrypted-archive');
        $this->connectedDrive($cabinet)->forceFill(['token_expires_at' => now()->subMinute()])->save();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $job = (new UploadBackupToGoogleDrive((int) $cabinet->getKey(), (string) $record->getKey(), 'Drclick Backups'))
            ->withFakeQueueInteractions();
        $job->handle(app(GoogleDriveService::class));

        $job->assertFailedWith(RuntimeException::class);
        $record->refresh();
        $this->assertSame(BackupRecord::DRIVE_UPLOAD_FAILED, $record->drive_upload_status);
        $this->assertSame('drive_reconnect_required', $record->drive_upload_failure_code);

        $connection = DriveBackupConnection::query()->sole();
        $this->assertNull($connection->refresh_token);
        $this->assertNull($connection->access_token);
        $status = app(GoogleDriveService::class)->status($cabinet);
        $this->assertFalse($status['google_drive_connected']);
        $this->assertTrue($status['google_drive_reconnect_required']);
        $this->assertDatabaseHas('cloud_connections', [
            'provider' => 'google_drive',
            'status' => 'error',
            'last_error' => 'reconnect_required',
        ]);
        Http::assertSentCount(1);
    }

    public function test_an_access_token_about_to_expire_is_refreshed_before_use(): void
    {
        $cabinet = CabinetSetting::current();
        $this->connectedDrive($cabinet)->forceFill(['token_expires_at' => now()->addSeconds(30)])->save();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'fresh-token', 'expires_in' => 3599]),
            'https://www.googleapis.com/drive/v3/about*' => Http::response(['user' => ['emailAddress' => 'doctor@example.test']]),
        ]);

        app(GoogleDriveService::class)->testConnection($cabinet);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/drive/v3/about')
            && $request->hasHeader('Authorization', 'Bearer fresh-token'));
        $this->assertSame('fresh-token', DriveBackupConnection::query()->sole()->access_token);
    }

    public function test_the_upload_job_may_outlive_the_worker_default_timeout_without_being_run_twice(): void
    {
        $job = new UploadBackupToGoogleDrive(1, (string) Str::uuid(), 'Drclick Backups');

        $this->assertGreaterThan(60, $job->timeout);
        $this->assertGreaterThan($job->timeout, (int) config('queue.connections.database.retry_after'));
    }

    private function archive(string $bytes): BackupRecord
    {
        $path = storage_path('app/private/backups/test-drive-'.Str::uuid().'.msbackup');
        file_put_contents($path, $bytes);
        $this->createdFiles[] = $path;

        return BackupRecord::query()->create([
            'filename' => basename($path),
            'disk' => 'local',
            'local_path' => $path,
            'size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'schema_version' => 1,
            'application_version' => '2.0.0-test',
            'status' => 'completed',
            'started_at' => now()->subSecond(),
            'completed_at' => now(),
        ]);
    }

    private function connectedDrive(CabinetSetting $cabinet): DriveBackupConnection
    {
        return DriveBackupConnection::query()->create([
            'cabinet_setting_id' => $cabinet->getKey(),
            'email' => 'doctor@example.test',
            'folder_name' => 'Drclick Backups',
            'folder_id' => 'drive-folder-id',
            'access_token' => 'drive-access-token',
            'refresh_token' => 'drive-refresh-token',
            'token_expires_at' => now()->addHour(),
        ]);
    }
}
