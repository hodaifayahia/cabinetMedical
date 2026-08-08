<?php

namespace App\Services\Backups;

use App\Models\BackupRecord;
use App\Models\CabinetSetting;
use App\Models\CloudConnection;
use App\Models\DriveBackupConnection;
use App\Services\GoogleOAuthLoopbackOrigin;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class GoogleDriveBackup
{
    public const DRIVE_SCOPE = 'https://www.googleapis.com/auth/drive.file';

    private const ENCRYPTED_BACKUP_MIME = 'application/vnd.medismart.backup';

    private const ENCRYPTED_ARCHIVE_MAGIC = "MEDISMART-MSBAK\x02";

    private const RETENTION_MAXIMUM_FILES = 10_000;

    public function __construct(private readonly GoogleOAuthLoopbackOrigin $origin) {}

    public function isConfigured(): bool
    {
        $clientId = config('services.google.client_id');

        return is_string($clientId)
            && $clientId !== ''
            && strlen($clientId) <= 1000
            && trim($clientId) === $clientId
            && preg_match('/[\x00-\x1F\x7F]/', $clientId) !== 1
            && config('services.google.drive_scope') === self::DRIVE_SCOPE
            && $this->origin->available();
    }

    /**
     * @return array{
     *     google_drive_configured: bool,
     *     google_drive_email: string|null,
     *     google_drive_connected: bool,
     *     google_drive_folder: string,
     *     last_backup_at: string|null,
     *     last_backup_name: string|null
     * }
     */
    public function status(CabinetSetting $cabinet): array
    {
        $connection = DriveBackupConnection::query()->whereBelongsTo($cabinet, 'cabinet')->first();
        /** @var Carbon|null $lastBackupAt */
        $lastBackupAt = $connection?->last_backup_at;

        return [
            'google_drive_configured' => $this->isConfigured(),
            'google_drive_email' => $connection?->email,
            'google_drive_connected' => $connection?->refresh_token !== null,
            'google_drive_folder' => $connection?->folder_name ?: 'MediSmart Backups',
            'last_backup_at' => $lastBackupAt?->format('d/m/Y H:i'),
            'last_backup_name' => $connection?->last_backup_name,
        ];
    }

    public function authorizationUrl(
        string $state,
        string $codeChallenge,
        string $redirectUri,
    ): string {
        if (preg_match('/\A[A-Za-z0-9_-]{43}\z/', $state) !== 1
            || preg_match('/\A[A-Za-z0-9_-]{43}\z/', $codeChallenge) !== 1
            || ! hash_equals($this->origin->redirectUri(), $redirectUri)) {
            throw new RuntimeException('The Google Drive authorization request is invalid.');
        }

        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => self::DRIVE_SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);
    }

    public function connect(
        string $code,
        string $codeVerifier,
        string $redirectUri,
        CabinetSetting $cabinet,
    ): void {
        if ($code === ''
            || strlen($codeVerifier) < 43
            || strlen($codeVerifier) > 128
            || preg_match('/\A[A-Za-z0-9_-]+\z/', $codeVerifier) !== 1
            || ! hash_equals($this->origin->redirectUri(), $redirectUri)) {
            throw new RuntimeException('The Google Drive authorization response is invalid.');
        }

        $payload = [
            'code' => $code,
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
            'code_verifier' => $codeVerifier,
        ];
        $payload = $this->withOptionalClientSecret($payload);

        $response = Http::asForm()
            ->connectTimeout(5)
            ->timeout(15)
            ->post('https://oauth2.googleapis.com/token', $payload);

        $accessToken = $this->providerToken($response->json('access_token'));

        if (! $response->successful() || $accessToken === null) {
            throw new RuntimeException('Google did not approve the Drive connection.');
        }

        $existingConnection = DriveBackupConnection::query()
            ->whereBelongsTo($cabinet, 'cabinet')
            ->first();
        $refreshToken = $this->providerToken($response->json('refresh_token'))
            ?? $this->providerToken($existingConnection?->refresh_token);

        if ($refreshToken === null) {
            throw new RuntimeException('Google did not return a durable Drive grant.');
        }

        $profileResponse = Http::withToken($accessToken)
            ->connectTimeout(5)
            ->timeout(15)
            ->get('https://www.googleapis.com/drive/v3/about', [
                'fields' => 'user(emailAddress)',
            ]);
        $email = $profileResponse->json('user.emailAddress');

        if (! $profileResponse->successful()
            || ! is_string($email)
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Google Drive did not return a valid account identity.');
        }

        DB::transaction(function () use (
            $cabinet,
            $response,
            $accessToken,
            $refreshToken,
            $email,
        ): void {
            $connection = DriveBackupConnection::query()->firstOrNew([
                'cabinet_setting_id' => $cabinet->getKey(),
            ]);
            $expiresIn = $response->json('expires_in', 3600);
            $expiresIn = is_int($expiresIn) && $expiresIn > 0 && $expiresIn <= 604800
                ? $expiresIn
                : 3600;
            $connection->fill([
                'email' => $email,
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'token_expires_at' => now()->addSeconds($expiresIn),
            ]);
            $connection->save();
            $this->syncConnectionStatus($connection, 'connected');
        });
    }

    /** @return array{email: string|null, checked_at: string} */
    public function testConnection(CabinetSetting $cabinet): array
    {
        $connection = DriveBackupConnection::query()->whereBelongsTo($cabinet, 'cabinet')->first();

        if ($connection === null || $connection->refresh_token === null) {
            throw new RuntimeException('Connect Google Drive before testing the connection.');
        }

        try {
            $token = $this->accessToken($connection);
            $response = Http::withToken($token)
                ->connectTimeout(5)
                ->timeout(15)
                ->get('https://www.googleapis.com/drive/v3/about', [
                    'fields' => 'user(emailAddress)',
                ]);
            $email = $response->json('user.emailAddress');

            if (! $response->successful()
                || ! is_string($email)
                || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new RuntimeException('Google Drive returned an invalid account identity.');
            }

            $connection->update(['email' => $email]);
            $this->syncConnectionStatus($connection, 'connected');

            return [
                'email' => $email,
                'checked_at' => now()->toIso8601String(),
            ];
        } catch (Throwable $exception) {
            try {
                $this->syncConnectionStatus(
                    $connection,
                    'error',
                    'The Google Drive connection test failed.',
                );
            } catch (Throwable) {
                // Preserve the generic adapter failure below.
            }

            throw new RuntimeException(
                'The Google Drive connection test failed.',
                previous: $exception,
            );
        }
    }

    /**
     * Best-effort provider revocation followed by mandatory local credential
     * deletion. A provider outage must never trap OAuth tokens on the device.
     */
    public function disconnect(CabinetSetting $cabinet): bool
    {
        $connection = DriveBackupConnection::query()->whereBelongsTo($cabinet, 'cabinet')->first();
        $token = $connection?->access_token ?: $connection?->refresh_token;
        $revocationConfirmed = $token === null;

        if (is_string($token) && $token !== '') {
            try {
                $response = Http::asForm()
                    ->connectTimeout(5)
                    ->timeout(10)
                    ->post('https://oauth2.googleapis.com/revoke', ['token' => $token]);
                $revocationConfirmed = $response->successful();
            } catch (Throwable) {
                $revocationConfirmed = false;
            }
        }

        DB::transaction(function () use ($connection, $revocationConfirmed): void {
            $connection?->delete();
            $cloud = CloudConnection::query()->firstOrNew(['provider' => 'google_drive']);
            $cloud->fill([
                'account_email' => null,
                'encrypted_access_token' => null,
                'encrypted_refresh_token' => null,
                'token_expires_at' => null,
                'folder_id' => null,
                'folder_name' => null,
                'status' => 'disconnected',
                'last_error' => $revocationConfirmed
                    ? null
                    : 'Remote token revocation could not be confirmed; local credentials were deleted.',
            ])->save();
        });

        return $revocationConfirmed;
    }

    /**
     * List only files carrying the complete MediSmart v2 metadata contract.
     * Provider names and arbitrary descriptions are never rendered blindly.
     *
     * @return list<array{
     *     id: string,
     *     name: string,
     *     size_bytes: int,
     *     created_at: string,
     *     sha256_hint: string,
     *     backup_record_id: string
     * }>
     */
    public function listBackups(CabinetSetting $cabinet): array
    {
        $connection = DriveBackupConnection::query()->whereBelongsTo($cabinet, 'cabinet')->first();

        if ($connection === null || $connection->refresh_token === null) {
            throw new RuntimeException('Connect a Google Drive account before listing backups.');
        }

        if ($connection->folder_id === null) {
            return [];
        }

        $folderId = $connection->folder_id;

        if (preg_match('/\A[A-Za-z0-9_-]{1,200}\z/', $folderId) !== 1) {
            throw new RuntimeException('The Google Drive backup folder is invalid.');
        }

        $token = $this->accessToken($connection);
        $response = Http::withToken($token)->get(
            'https://www.googleapis.com/drive/v3/files',
            [
                'q' => "trashed = false and '{$folderId}' in parents and mimeType = '".self::ENCRYPTED_BACKUP_MIME."'",
                'spaces' => 'drive',
                'pageSize' => 100,
                'orderBy' => 'createdTime desc',
                'fields' => 'files(id,name,size,createdTime,mimeType,parents,appProperties)',
            ],
        );

        if (! $response->successful()) {
            throw new RuntimeException('Google Drive backups could not be listed.');
        }

        $files = $response->json('files');

        if (! is_array($files)) {
            throw new RuntimeException('Google Drive returned an invalid backup list.');
        }

        $backups = [];

        foreach ($files as $file) {
            $metadata = $this->safeRemoteBackup($file, $folderId);

            if ($metadata !== null) {
                $backups[] = [
                    'id' => $metadata['id'],
                    'name' => $metadata['name'],
                    'size_bytes' => $metadata['size_bytes'],
                    'created_at' => $metadata['created_at'],
                    'sha256_hint' => substr($metadata['sha256'], 0, 12),
                    'backup_record_id' => $metadata['backup_record_id'],
                ];
            }
        }

        return $backups;
    }

    /**
     * Return the complete, paginated inventory consumed by the shared
     * retention planner. A malformed matching file or truncated inventory
     * aborts the entire retention pass instead of being silently ignored.
     *
     * @return list<array{
     *     id: string,
     *     name: string,
     *     size_bytes: int,
     *     created_at: string,
     *     sha256: string,
     *     backup_record_id: string,
     *     format: 'msbackup',
     *     format_version: 2,
     *     verification_status: 'verified',
     *     verified_sha256: string
     * }>
     */
    public function retentionInventory(CabinetSetting $cabinet): array
    {
        $connection = $this->connectedWithFolder($cabinet);
        $token = $this->accessToken($connection);
        $folderId = $connection->folder_id;
        $pageToken = null;
        $seenPageTokens = [];
        $inventory = [];

        do {
            $query = [
                'q' => "trashed = false and '{$folderId}' in parents and mimeType = '".self::ENCRYPTED_BACKUP_MIME."'",
                'spaces' => 'drive',
                'pageSize' => 100,
                'orderBy' => 'createdTime desc',
                'fields' => 'nextPageToken,files(id,name,size,createdTime,mimeType,parents,appProperties)',
            ];

            if ($pageToken !== null) {
                $query['pageToken'] = $pageToken;
            }

            $response = Http::withToken($token)->get(
                'https://www.googleapis.com/drive/v3/files',
                $query,
            );
            $files = $response->successful() ? $response->json('files') : null;

            if (! is_array($files)) {
                throw new RuntimeException('The Google Drive retention inventory could not be verified.');
            }

            foreach ($files as $file) {
                $metadata = $this->safeRemoteBackup($file, $folderId);

                if ($metadata === null
                    || $this->matchingCompletedLocalRecord($metadata) === null
                    || count($inventory) >= self::RETENTION_MAXIMUM_FILES) {
                    throw new RuntimeException('The Google Drive retention inventory is unsafe.');
                }

                $inventory[] = [
                    'id' => $metadata['id'],
                    'name' => $metadata['name'],
                    'size_bytes' => $metadata['size_bytes'],
                    'created_at' => $metadata['created_at'],
                    'sha256' => $metadata['sha256'],
                    'backup_record_id' => $metadata['backup_record_id'],
                    'format' => 'msbackup',
                    'format_version' => 2,
                    'verification_status' => 'verified',
                    'verified_sha256' => $metadata['sha256'],
                ];
            }

            $nextPageToken = $response->json('nextPageToken');

            if ($nextPageToken === null || $nextPageToken === '') {
                $pageToken = null;

                continue;
            }

            if (! is_string($nextPageToken)
                || strlen($nextPageToken) > 4096
                || preg_match('/[\x00-\x1F\x7F]/', $nextPageToken) === 1
                || isset($seenPageTokens[$nextPageToken])) {
                throw new RuntimeException('Google Drive returned an invalid retention cursor.');
            }

            $seenPageTokens[$nextPageToken] = true;
            $pageToken = $nextPageToken;
        } while ($pageToken !== null);

        return $inventory;
    }

    /**
     * Stream one exact managed Drive artifact into a new local file and
     * authenticate its non-secret envelope metadata before returning.
     *
     * @return array{
     *     id: string,
     *     name: string,
     *     size_bytes: int,
     *     created_at: string,
     *     sha256: string,
     *     backup_record_id: string
     * }
     */
    public function downloadVerifiedArchive(
        CabinetSetting $cabinet,
        string $fileId,
        string $destinationPath,
    ): array {
        $connection = $this->connectedWithFolder($cabinet);
        $token = $this->accessToken($connection);
        $metadata = $this->fetchRemoteBackup($token, $connection->folder_id, $fileId);
        $maximumBytes = max(
            strlen(self::ENCRYPTED_ARCHIVE_MAGIC) + 1,
            (int) config('medismart.backups.remote_download_max_bytes', 25 * 1024 * 1024 * 1024),
        );

        if ($metadata['size_bytes'] > $maximumBytes
            || $destinationPath === ''
            || str_contains($destinationPath, "\0")
            || file_exists($destinationPath)
            || is_link($destinationPath)) {
            throw new RuntimeException('The selected Google Drive backup cannot be downloaded safely.');
        }

        try {
            $response = Http::withToken($token)
                ->withOptions(['stream' => true])
                ->get(
                    'https://www.googleapis.com/drive/v3/files/'.$metadata['id'],
                    ['alt' => 'media'],
                );

            if (! $response->successful()) {
                throw new RuntimeException('Google Drive rejected the backup download.');
            }

            $contentLength = $response->header('Content-Length');

            if ($contentLength !== ''
                && (preg_match('/\A[0-9]+\z/', $contentLength) !== 1
                    || (int) $contentLength !== $metadata['size_bytes'])) {
                throw new RuntimeException('Google Drive returned an unexpected backup size.');
            }

            $destination = fopen($destinationPath, 'x+b');

            if (! is_resource($destination) || ! chmod($destinationPath, 0600)) {
                if (is_resource($destination)) {
                    fclose($destination);
                }

                throw new RuntimeException('The private backup destination is unavailable.');
            }

            $source = $response->toPsrResponse()->getBody();
            $hash = hash_init('sha256');
            $downloaded = 0;
            $prefix = '';

            try {
                while (! $source->eof()) {
                    $chunk = $source->read(1024 * 1024);

                    if ($chunk === '') {
                        break;
                    }

                    $downloaded += strlen($chunk);

                    if ($downloaded > $metadata['size_bytes'] || $downloaded > $maximumBytes) {
                        throw new RuntimeException('The downloaded backup exceeded its verified size.');
                    }

                    if (strlen($prefix) < strlen(self::ENCRYPTED_ARCHIVE_MAGIC)) {
                        $prefix .= substr(
                            $chunk,
                            0,
                            strlen(self::ENCRYPTED_ARCHIVE_MAGIC) - strlen($prefix),
                        );
                    }

                    hash_update($hash, $chunk);
                    $this->writeAll($destination, $chunk);
                }

                if (! fflush($destination)) {
                    throw new RuntimeException('The downloaded backup could not be flushed to disk.');
                }
            } finally {
                fclose($destination);
            }

            if ($downloaded !== $metadata['size_bytes']
                || ! hash_equals($metadata['sha256'], hash_final($hash))
                || ! hash_equals(self::ENCRYPTED_ARCHIVE_MAGIC, $prefix)) {
                throw new RuntimeException('The downloaded backup failed integrity verification.');
            }

            return $metadata;
        } catch (Throwable $exception) {
            if (file_exists($destinationPath)) {
                @unlink($destinationPath);
            }

            throw new RuntimeException(
                'The selected Google Drive backup could not be verified.',
                previous: $exception,
            );
        }
    }

    /**
     * Delete only a file that still satisfies the complete managed metadata
     * contract inside the installation's exact configured Drive folder.
     *
     * @return array{
     *     id: string,
     *     name: string,
     *     backup_record_id: string,
     *     newer_backup_record_id: string
     * }
     */
    public function deleteManagedBackup(CabinetSetting $cabinet, string $fileId): array
    {
        $connection = $this->connectedWithFolder($cabinet);
        $token = $this->accessToken($connection);
        $metadata = $this->fetchRemoteBackup($token, $connection->folder_id, $fileId);

        if ($this->matchingCompletedLocalRecord($metadata) === null) {
            throw new RuntimeException(
                'The selected Google Drive backup has no exact completed local upload record.',
            );
        }

        $newerBackup = $this->newerVerifiedRemoteBackup(
            $token,
            $connection->folder_id,
            $metadata,
        );
        $revalidatedTarget = $this->fetchRemoteBackup(
            $token,
            $connection->folder_id,
            $metadata['id'],
        );

        if ($revalidatedTarget !== $metadata
            || $this->matchingCompletedLocalRecord($revalidatedTarget) === null) {
            throw new RuntimeException(
                'The selected Google Drive backup changed during deletion verification.',
            );
        }

        $response = Http::withToken($token)->delete(
            'https://www.googleapis.com/drive/v3/files/'.$metadata['id'],
        );

        if (! $response->successful()) {
            throw new RuntimeException('Google Drive rejected the managed backup deletion.');
        }

        return [
            'id' => $metadata['id'],
            'name' => $metadata['name'],
            'backup_record_id' => $metadata['backup_record_id'],
            'newer_backup_record_id' => $newerBackup['backup_record_id'],
        ];
    }

    /**
     * @param  resource  $stream
     * @param  array{
     *     backup_record_id: string,
     *     filename: string,
     *     size: int,
     *     sha256: string,
     *     format: string,
     *     format_version: int
     * }  $artifact
     * @param  (Closure(int, int): void)|null  $progress
     * @return array{id: string, name: string}
     */
    public function uploadVerifiedArchive(
        CabinetSetting $cabinet,
        $stream,
        array $artifact,
        string $folderName,
        ?Closure $progress = null,
    ): array {
        $connection = DriveBackupConnection::query()->whereBelongsTo($cabinet, 'cabinet')->first();

        if ($connection === null || $connection->refresh_token === null || ! is_resource($stream)) {
            throw new RuntimeException('Connect a Google Drive account before saving a backup.');
        }

        try {
            $requestedFolder = trim($folderName) ?: 'MediSmart Backups';

            if ($connection->folder_name !== $requestedFolder) {
                $connection->folder_name = $requestedFolder;
                $connection->folder_id = null;
                $connection->save();
            }

            $token = $this->accessToken($connection);
            $folderId = $this->ensureFolder($connection, $token);
            $this->syncConnectionStatus($connection, 'uploading');
            $existing = $this->findExistingArchive(
                $token,
                $folderId,
                $artifact,
            );

            if ($existing !== null) {
                $this->recordSuccessfulUpload($connection, $artifact['filename']);

                return $existing;
            }

            if (rewind($stream) === false) {
                throw new RuntimeException('The verified backup stream could not be read.');
            }

            $progress?->__invoke(0, $artifact['size']);
            $metadata = [
                'name' => $artifact['filename'],
                'parents' => [$folderId],
                'mimeType' => self::ENCRYPTED_BACKUP_MIME,
                'description' => 'DrClickDz encrypted backup v2',
                'appProperties' => [
                    'medismart_backup_record_id' => $artifact['backup_record_id'],
                    'medismart_format' => $artifact['format'],
                    'medismart_format_version' => (string) $artifact['format_version'],
                    'medismart_sha256' => $artifact['sha256'],
                    'medismart_size_bytes' => (string) $artifact['size'],
                ],
            ];
            $request = Http::withToken($token);

            if ($progress !== null) {
                $artifactSize = $artifact['size'];
                $request = $request->withOptions([
                    'progress' => static function (
                        int $downloadTotal,
                        int $downloadedBytes,
                        int $uploadTotal,
                        int $uploadedBytes,
                    ) use ($artifactSize, $progress): void {
                        unset($downloadTotal, $downloadedBytes);
                        $uploaded = $uploadTotal > 0
                            ? (int) floor(
                                min(1, max(0, $uploadedBytes) / $uploadTotal) * $artifactSize,
                            )
                            : min($artifactSize, max(0, $uploadedBytes));
                        $progress($uploaded, $artifactSize);
                    },
                ]);
            }

            $response = $request
                ->attach(
                    'metadata',
                    json_encode($metadata, JSON_THROW_ON_ERROR),
                    'metadata.json',
                    ['Content-Type' => 'application/json; charset=UTF-8'],
                )
                ->attach(
                    'file',
                    $stream,
                    $artifact['filename'],
                    ['Content-Type' => self::ENCRYPTED_BACKUP_MIME],
                )
                ->post('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,name');

            $remoteId = $response->json('id');

            if (! $response->successful()
                || ! is_string($remoteId)
                || preg_match('/\A[A-Za-z0-9_-]{1,200}\z/', $remoteId) !== 1) {
                throw new RuntimeException('Google Drive rejected the encrypted backup upload.');
            }

            $remoteName = $response->json('name');
            $this->recordSuccessfulUpload($connection, $artifact['filename']);

            return [
                'id' => $remoteId,
                'name' => is_string($remoteName) && $remoteName !== ''
                    ? $remoteName
                    : $artifact['filename'],
            ];
        } catch (DriveUploadCancelled $exception) {
            try {
                $this->syncConnectionStatus($connection, 'connected');
            } catch (Throwable) {
                // Cancellation remains authoritative even if status persistence fails.
            }

            throw $exception;
        } catch (Throwable) {
            try {
                $this->syncConnectionStatus(
                    $connection,
                    'error',
                    'The Google Drive backup upload failed.',
                );
            } catch (Throwable) {
                // Preserve the generic adapter failure below.
            }

            throw new RuntimeException('The Google Drive backup upload failed.');
        }
    }

    public function recordUploadFailure(CabinetSetting $cabinet): void
    {
        $connection = DriveBackupConnection::query()->whereBelongsTo($cabinet, 'cabinet')->first();
        $this->syncConnectionStatus(
            $connection,
            'error',
            'The Google Drive backup upload failed.',
        );
    }

    private function accessToken(DriveBackupConnection $connection): string
    {
        /** @var Carbon|null $tokenExpiresAt */
        $tokenExpiresAt = $connection->token_expires_at;

        if ($connection->access_token && $tokenExpiresAt?->isFuture()) {
            return $connection->access_token;
        }

        $payload = $this->withOptionalClientSecret([
            'client_id' => config('services.google.client_id'),
            'refresh_token' => $connection->refresh_token,
            'grant_type' => 'refresh_token',
        ]);
        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', $payload);

        if (! $response->successful() || ! $response->json('access_token')) {
            throw new RuntimeException('The Google Drive connection has expired. Connect Gmail again.');
        }

        $connection->update([
            'access_token' => $response->json('access_token'),
            'token_expires_at' => now()->addSeconds((int) $response->json('expires_in', 3600)),
        ]);

        return (string) $response->json('access_token');
    }

    /**
     * Public installed-app clients omit a secret. Existing confidential
     * clients keep sending their configured secret without exposing it to
     * URLs, application output, or audit metadata.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withOptionalClientSecret(array $payload): array
    {
        $clientSecret = config('services.google.client_secret');

        if (is_string($clientSecret) && $clientSecret !== '') {
            $payload['client_secret'] = $clientSecret;
        }

        return $payload;
    }

    private function providerToken(mixed $value): ?string
    {
        if (! is_string($value)
            || $value === ''
            || strlen($value) > 16384
            || trim($value) !== $value
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return null;
        }

        return $value;
    }

    /**
     * @return array{
     *     id: string,
     *     name: string,
     *     size_bytes: int,
     *     created_at: string,
     *     sha256: string,
     *     backup_record_id: string
     * }|null
     */
    private function safeRemoteBackup(mixed $file, string $expectedFolderId): ?array
    {
        if (! is_array($file)) {
            return null;
        }

        $id = $file['id'] ?? null;
        $name = $file['name'] ?? null;
        $size = $file['size'] ?? null;
        $createdAt = $file['createdTime'] ?? null;
        $mimeType = $file['mimeType'] ?? null;
        $parents = $file['parents'] ?? null;
        $properties = $file['appProperties'] ?? null;

        if (! is_string($id)
            || preg_match('/\A[A-Za-z0-9_-]{1,200}\z/', $id) !== 1
            || ! is_string($name)
            || strlen($name) > 255
            || basename(str_replace('\\', '/', $name)) !== $name
            || ! str_ends_with($name, '.msbackup')
            || ! is_string($size)
            || preg_match('/\A[1-9][0-9]{0,13}\z/', $size) !== 1
            || ! is_string($createdAt)
            || strtotime($createdAt) === false
            || $mimeType !== self::ENCRYPTED_BACKUP_MIME
            || ! is_array($parents)
            || count($parents) !== 1
            || ($parents[0] ?? null) !== $expectedFolderId
            || ! is_array($properties)
            || ($properties['medismart_format'] ?? null) !== 'msbackup'
            || ($properties['medismart_format_version'] ?? null) !== '2'
            || ! is_string($properties['medismart_size_bytes'] ?? null)
            || preg_match('/\A[1-9][0-9]{0,13}\z/', $properties['medismart_size_bytes']) !== 1
            || ! hash_equals($size, $properties['medismart_size_bytes'])
            || ! is_string($properties['medismart_sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/', $properties['medismart_sha256']) !== 1
            || ! is_string($properties['medismart_backup_record_id'] ?? null)
            || ! Str::isUuid($properties['medismart_backup_record_id'])) {
            return null;
        }

        return [
            'id' => $id,
            'name' => $name,
            'size_bytes' => (int) $size,
            'created_at' => $createdAt,
            'sha256' => $properties['medismart_sha256'],
            'backup_record_id' => $properties['medismart_backup_record_id'],
        ];
    }

    private function connectedWithFolder(CabinetSetting $cabinet): DriveBackupConnection
    {
        $connection = DriveBackupConnection::query()->whereBelongsTo($cabinet, 'cabinet')->first();

        if ($connection === null
            || $connection->refresh_token === null
            || ! is_string($connection->folder_id)
            || preg_match('/\A[A-Za-z0-9_-]{1,200}\z/', $connection->folder_id) !== 1) {
            throw new RuntimeException('Connect Google Drive and select a managed backup folder first.');
        }

        return $connection;
    }

    /**
     * @return array{
     *     id: string,
     *     name: string,
     *     size_bytes: int,
     *     created_at: string,
     *     sha256: string,
     *     backup_record_id: string
     * }
     */
    private function fetchRemoteBackup(string $token, string $folderId, string $fileId): array
    {
        if (preg_match('/\A[A-Za-z0-9_-]{1,200}\z/', $fileId) !== 1) {
            throw new RuntimeException('The Google Drive backup identifier is invalid.');
        }

        $response = Http::withToken($token)->get(
            'https://www.googleapis.com/drive/v3/files/'.$fileId,
            ['fields' => 'id,name,size,createdTime,mimeType,parents,appProperties'],
        );
        $metadata = $response->successful()
            ? $this->safeRemoteBackup($response->json(), $folderId)
            : null;

        if ($metadata === null || ! hash_equals($fileId, $metadata['id'])) {
            throw new RuntimeException('The selected Google Drive file is not a managed DrClickDz backup.');
        }

        return $metadata;
    }

    /**
     * A remote deletion is allowed only when another strictly newer Drive
     * artifact still matches the completed local record produced by the
     * verified upload job. Provider names or appProperties alone never prove
     * that a recoverable replacement exists.
     *
     * @param  array{
     *     id: string,
     *     name: string,
     *     size_bytes: int,
     *     created_at: string,
     *     sha256: string,
     *     backup_record_id: string
     * }  $target
     * @return array{
     *     id: string,
     *     name: string,
     *     size_bytes: int,
     *     created_at: string,
     *     sha256: string,
     *     backup_record_id: string
     * }
     */
    private function newerVerifiedRemoteBackup(
        string $token,
        string $folderId,
        array $target,
    ): array {
        $response = Http::withToken($token)->get(
            'https://www.googleapis.com/drive/v3/files',
            [
                'q' => "trashed = false and '{$folderId}' in parents and mimeType = '".self::ENCRYPTED_BACKUP_MIME."'",
                'spaces' => 'drive',
                'pageSize' => 100,
                'orderBy' => 'createdTime desc',
                'fields' => 'files(id,name,size,createdTime,mimeType,parents,appProperties)',
            ],
        );
        $files = $response->successful() ? $response->json('files') : null;

        if (! is_array($files)) {
            throw new RuntimeException('Newer Google Drive backups could not be verified.');
        }

        $targetCreatedAt = strtotime($target['created_at']);

        if ($targetCreatedAt === false) {
            throw new RuntimeException('The selected Google Drive backup timestamp is invalid.');
        }

        foreach ($files as $file) {
            $candidate = $this->safeRemoteBackup($file, $folderId);
            $candidateCreatedAt = is_array($candidate)
                ? strtotime($candidate['created_at'])
                : false;

            if ($candidate === null
                || $candidateCreatedAt === false
                || $candidateCreatedAt <= $targetCreatedAt
                || hash_equals($target['id'], $candidate['id'])) {
                continue;
            }

            $record = $this->matchingCompletedLocalRecord($candidate);

            if ($record === null) {
                continue;
            }

            $revalidated = $this->fetchRemoteBackup(
                $token,
                $folderId,
                $candidate['id'],
            );

            if ($revalidated !== $candidate
                || $this->matchingCompletedLocalRecord($revalidated) === null) {
                throw new RuntimeException('The newer Google Drive backup changed during verification.');
            }

            return $revalidated;
        }

        throw new RuntimeException('No newer verified Google Drive backup protects this deletion.');
    }

    /**
     * @param  array{
     *     id: string,
     *     name: string,
     *     size_bytes: int,
     *     created_at: string,
     *     sha256: string,
     *     backup_record_id: string
     * }  $metadata
     */
    private function matchingCompletedLocalRecord(array $metadata): ?BackupRecord
    {
        return BackupRecord::query()
            ->whereKey($metadata['backup_record_id'])
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->where('remote_file_id', $metadata['id'])
            ->where('filename', $metadata['name'])
            ->where('size', $metadata['size_bytes'])
            ->where('sha256', $metadata['sha256'])
            ->first();
    }

    /** @param resource $destination */
    private function writeAll($destination, string $bytes): void
    {
        $offset = 0;
        $length = strlen($bytes);

        while ($offset < $length) {
            $written = fwrite($destination, substr($bytes, $offset));

            if (! is_int($written) || $written <= 0) {
                throw new RuntimeException('The downloaded backup could not be written to disk.');
            }

            $offset += $written;
        }
    }

    private function ensureFolder(DriveBackupConnection $connection, string $token): string
    {
        if ($connection->folder_id !== null) {
            return $connection->folder_id;
        }

        $response = Http::withToken($token)->post('https://www.googleapis.com/drive/v3/files', [
            'name' => $connection->folder_name ?: 'MediSmart Backups',
            'mimeType' => 'application/vnd.google-apps.folder',
        ]);

        if (! $response->successful() || ! $response->json('id')) {
            throw new RuntimeException('The Google Drive backup folder could not be created.');
        }

        $connection->update(['folder_id' => $response->json('id')]);

        return (string) $response->json('id');
    }

    /**
     * @param array{
     *     backup_record_id: string,
     *     filename: string,
     *     size: int,
     *     sha256: string,
     *     format: string,
     *     format_version: int
     * } $artifact
     * @return array{id: string, name: string}|null
     */
    private function findExistingArchive(
        string $token,
        string $folderId,
        array $artifact,
    ): ?array {
        $response = Http::withToken($token)->get(
            'https://www.googleapis.com/drive/v3/files',
            [
                'q' => "trashed = false and '{$folderId}' in parents and mimeType = '".self::ENCRYPTED_BACKUP_MIME."' and appProperties has { key='medismart_backup_record_id' and value='{$artifact['backup_record_id']}' }",
                'spaces' => 'drive',
                'pageSize' => 1,
                'fields' => 'files(id,name,size,createdTime,mimeType,parents,appProperties)',
            ],
        );

        if (! $response->successful()) {
            throw new RuntimeException('The existing Google Drive backup could not be checked.');
        }

        $files = $response->json('files');

        if (! is_array($files)) {
            throw new RuntimeException('Google Drive returned an invalid existing backup list.');
        }

        if ($files === []) {
            return null;
        }

        $metadata = $this->safeRemoteBackup($files[0] ?? null, $folderId);

        if ($metadata === null
            || ! hash_equals($artifact['backup_record_id'], $metadata['backup_record_id'])
            || ! hash_equals($artifact['filename'], $metadata['name'])
            || $artifact['size'] !== $metadata['size_bytes']
            || ! hash_equals($artifact['sha256'], $metadata['sha256'])) {
            throw new RuntimeException('The existing Google Drive backup does not match its local record.');
        }

        return [
            'id' => $metadata['id'],
            'name' => $metadata['name'],
        ];
    }

    private function recordSuccessfulUpload(
        DriveBackupConnection $connection,
        string $filename,
    ): void {
        $connection->update([
            'last_backup_at' => now(),
            'last_backup_name' => $filename,
        ]);
        $this->syncConnectionStatus($connection, 'connected');
    }

    private function syncConnectionStatus(
        ?DriveBackupConnection $connection,
        string $status,
        ?string $lastError = null,
    ): void {
        $cloud = CloudConnection::query()->firstOrNew(['provider' => 'google_drive']);
        $cloud->fill([
            'account_email' => $connection?->email,
            'folder_id' => $connection?->folder_id,
            'folder_name' => $connection?->folder_name,
            'status' => $status,
            'last_error' => $lastError,
        ]);

        if ($status === 'connected') {
            $cloud->last_connected_at = now();
        }

        $cloud->save();
    }
}
