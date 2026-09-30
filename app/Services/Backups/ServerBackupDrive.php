<?php

namespace App\Services\Backups;

use App\Enums\ServerBackupDriveStatus;
use App\Models\ServerBackupRun;
use App\Models\ServerDriveConnection;
use App\Models\User;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Sends the online service's nightly backups to the platform's Google Drive.
 *
 * The admin connects one Google account from the back office (a web OAuth
 * client with PKCE; the redirect lands back on the back-office page). The
 * `drive.file` scope only lets the app see files it created itself, so the
 * rest of that Drive stays out of reach.
 *
 * The desktop's per-cabinet Drive backup (`GoogleDriveBackup`) is a different
 * flow: a loopback redirect to the clinic's own PC, one account per cabinet.
 */
final class ServerBackupDrive
{
    public const SCOPE = 'https://www.googleapis.com/auth/drive.file';

    public const FOLDER_NAME = 'Drclick serveur';

    public const KEEP_ON_DRIVE = 30;

    private const SESSION_KEY = 'server_backup.google_oauth';

    private const MIME = 'application/octet-stream';

    public function isConfigured(): bool
    {
        $clientId = config('services.google.client_id');

        return is_string($clientId) && trim($clientId) !== '';
    }

    public function connection(): ?ServerDriveConnection
    {
        return ServerDriveConnection::query()->latest('id')->first();
    }

    public function isConnected(): bool
    {
        return $this->connection() !== null;
    }

    /**
     * Start the consent flow. The state and PKCE verifier live in the admin's
     * session only until Google sends them back.
     */
    public function authorizationUrl(string $redirectUri): string
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('La configuration Google (GOOGLE_CLIENT_ID) manque sur ce serveur.');
        }

        $state = $this->randomToken(32);
        $verifier = $this->randomToken(64);

        session()->put(self::SESSION_KEY, [
            'state' => $state,
            'verifier' => $verifier,
            'redirect_uri' => $redirectUri,
            'expires_at' => now()->addMinutes(10)->getTimestamp(),
        ]);

        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'offline',
            // Without a fresh consent Google omits the refresh token when the
            // account approved this client before, and nightly uploads need it.
            'prompt' => 'consent',
            'state' => $state,
            'code_challenge' => $this->base64Url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
        ]);
    }

    public function completeAuthorization(string $code, string $state, User $actor): ServerDriveConnection
    {
        $pending = session()->pull(self::SESSION_KEY);

        if (! is_array($pending)
            || ! is_string($pending['state'] ?? null)
            || ! is_string($pending['verifier'] ?? null)
            || ! is_string($pending['redirect_uri'] ?? null)
            || ! is_int($pending['expires_at'] ?? null)
            || $pending['expires_at'] < now()->getTimestamp()
            || ! hash_equals($pending['state'], $state)
            || $code === '') {
            throw new RuntimeException('La demande de connexion Google a expiré. Recommencez.');
        }

        // Callers show RuntimeException messages; a timeout would otherwise
        // reach the admin as an error page.
        try {
            $response = Http::asForm()
                ->connectTimeout(10)
                ->timeout(20)
                ->post('https://oauth2.googleapis.com/token', $this->withOptionalClientSecret([
                    'code' => $code,
                    'client_id' => config('services.google.client_id'),
                    'redirect_uri' => $pending['redirect_uri'],
                    'grant_type' => 'authorization_code',
                    'code_verifier' => $pending['verifier'],
                ]));

            $accessToken = $response->json('access_token');
            $refreshToken = $response->json('refresh_token');

            if (! $response->successful() || ! is_string($accessToken) || $accessToken === '') {
                throw new RuntimeException('Google a refusé la connexion.');
            }

            if (! is_string($refreshToken) || $refreshToken === '') {
                throw new RuntimeException('Google n’a pas accordé d’accès durable. Retirez l’accès Drclick de votre compte Google puis recommencez.');
            }

            $profile = Http::withToken($accessToken)
                ->connectTimeout(10)
                ->timeout(20)
                ->get('https://www.googleapis.com/drive/v3/about', ['fields' => 'user(emailAddress)']);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Google est injoignable depuis le serveur. Recommencez.', previous: $exception);
        }

        $email = $profile->json('user.emailAddress');

        if (! $profile->successful() || ! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Google Drive n’a pas renvoyé l’adresse du compte.');
        }

        return DB::transaction(function () use ($response, $accessToken, $refreshToken, $email, $actor): ServerDriveConnection {
            ServerDriveConnection::query()->delete();

            return ServerDriveConnection::query()->create([
                'email' => $email,
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'token_expires_at' => now()->addSeconds($this->expiresIn($response->json('expires_in'))),
                'connected_by' => $actor->getKey(),
            ]);
        });
    }

    public function disconnect(): void
    {
        $connection = $this->connection();

        if ($connection === null) {
            return;
        }

        try {
            Http::asForm()
                ->connectTimeout(5)
                ->timeout(10)
                ->post('https://oauth2.googleapis.com/revoke', ['token' => $connection->refresh_token]);
        } catch (Throwable) {
            // Forgetting the grant here is what matters; Google also expires
            // unused refresh tokens on its own.
        }

        ServerDriveConnection::query()->delete();
    }

    /**
     * Upload one encrypted dump and record the outcome on its run. Throws with
     * a message fit for the back office when the copy did not reach Drive.
     */
    public function upload(ServerBackupRun $run, string $path): void
    {
        try {
            $connection = $this->connection()
                ?? throw new RuntimeException('Aucun compte Google Drive n’est connecté.');

            if (! is_file($path) || ! is_readable($path)) {
                throw new RuntimeException('Le fichier de sauvegarde est introuvable sur le serveur.');
            }

            $token = $this->accessToken($connection);
            $folderId = $this->ensureFolder($connection, $token);

            $session = Http::withToken($token)
                ->withHeaders([
                    'X-Upload-Content-Type' => self::MIME,
                    'X-Upload-Content-Length' => (string) $run->size_bytes,
                ])
                ->connectTimeout(10)
                ->timeout(30)
                ->post('https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&fields=id', [
                    'name' => $run->filename,
                    'parents' => [$folderId],
                    'mimeType' => self::MIME,
                    // Drive keeps no sidecar file: this is the checksum a
                    // restore from the Drive copy alone can check against.
                    'description' => 'Sauvegarde chiffrée du serveur Drclick. SHA-256 : '.$run->sha256,
                    'appProperties' => [
                        'drclick_server_backup' => '1',
                        'sha256' => $run->sha256,
                    ],
                ]);
            $location = $session->header('Location');

            if (! $session->successful() || ! str_starts_with($location, 'https://www.googleapis.com/')) {
                throw new RuntimeException('Google Drive a refusé l’envoi de la sauvegarde.');
            }

            $handle = fopen($path, 'rb');

            if ($handle === false) {
                throw new RuntimeException('Le fichier de sauvegarde est illisible.');
            }

            $response = Http::withToken($token)
                ->withBody(Utils::streamFor($handle), self::MIME)
                ->connectTimeout(10)
                ->timeout(900)
                ->put($location);
            $fileId = $response->json('id');

            if (! $response->successful() || ! is_string($fileId) || preg_match('/\A[A-Za-z0-9_-]{1,200}\z/', $fileId) !== 1) {
                throw new RuntimeException('Google Drive n’a pas confirmé la réception de la sauvegarde.');
            }
        } catch (Throwable $exception) {
            $message = match (true) {
                $exception instanceof RuntimeException => $exception->getMessage(),
                $exception instanceof ConnectionException => 'Google Drive est injoignable depuis le serveur.',
                default => 'L’envoi vers Google Drive a échoué.',
            };
            $run->update([
                'drive_status' => ServerBackupDriveStatus::FAILED,
                'drive_error' => mb_substr($message, 0, 255),
            ]);

            throw new RuntimeException($message, previous: $exception);
        }

        $run->update([
            'drive_status' => ServerBackupDriveStatus::UPLOADED,
            'drive_file_id' => $fileId,
            'drive_uploaded_at' => now(),
            'drive_error' => null,
        ]);

        $this->pruneOldCopies($token, $folderId);
    }

    private function accessToken(ServerDriveConnection $connection): string
    {
        /** @var Carbon|null $expiresAt */
        $expiresAt = $connection->token_expires_at;

        if (is_string($connection->access_token) && $connection->access_token !== '' && $expiresAt?->isAfter(now()->addMinute())) {
            return $connection->access_token;
        }

        $response = Http::asForm()
            ->connectTimeout(10)
            ->timeout(20)
            ->post('https://oauth2.googleapis.com/token', $this->withOptionalClientSecret([
                'client_id' => config('services.google.client_id'),
                'refresh_token' => $connection->refresh_token,
                'grant_type' => 'refresh_token',
            ]));
        $token = $response->json('access_token');

        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw new RuntimeException('Google a retiré l’accès au Drive. Reconnectez Google Drive.');
        }

        $connection->update([
            'access_token' => $token,
            'token_expires_at' => now()->addSeconds($this->expiresIn($response->json('expires_in'))),
        ]);

        return $token;
    }

    /**
     * Reuse the backup folder, or make a new one when someone deleted or
     * trashed it from the Drive.
     */
    private function ensureFolder(ServerDriveConnection $connection, string $token): string
    {
        if (is_string($connection->folder_id) && $connection->folder_id !== '') {
            $folder = Http::withToken($token)
                ->connectTimeout(10)
                ->timeout(20)
                ->get('https://www.googleapis.com/drive/v3/files/'.rawurlencode($connection->folder_id), [
                    'fields' => 'id,trashed',
                ]);

            if ($folder->successful() && $folder->json('trashed') !== true) {
                return $connection->folder_id;
            }

            // Only a folder that is gone gets replaced. On a passing error
            // (5xx, rate limit) a new folder would leave the old one's copies
            // outside the prune for good.
            if (! $folder->successful() && $folder->status() !== 404) {
                throw new RuntimeException('Google Drive est momentanément indisponible. Réessayez avec « Envoyer la dernière sauvegarde ».');
            }
        }

        $response = Http::withToken($token)
            ->connectTimeout(10)
            ->timeout(20)
            ->post('https://www.googleapis.com/drive/v3/files?fields=id', [
                'name' => self::FOLDER_NAME,
                'mimeType' => 'application/vnd.google-apps.folder',
            ]);
        $folderId = $response->json('id');

        if (! $response->successful() || ! is_string($folderId) || $folderId === '') {
            throw new RuntimeException('Le dossier de sauvegarde n’a pas pu être créé sur Google Drive.');
        }

        $connection->update(['folder_id' => $folderId]);

        return $folderId;
    }

    /**
     * Keep the newest copies only. Best effort: a failed clean-up must not
     * turn a successful upload into a reported failure.
     */
    private function pruneOldCopies(string $token, string $folderId): void
    {
        try {
            $query = sprintf(
                "'%s' in parents and trashed = false and appProperties has { key='drclick_server_backup' and value='1' }",
                str_replace(['\\', "'"], ['\\\\', "\\'"], $folderId),
            );
            $listing = Http::withToken($token)
                ->connectTimeout(10)
                ->timeout(20)
                ->get('https://www.googleapis.com/drive/v3/files', [
                    'q' => $query,
                    'orderBy' => 'createdTime desc',
                    'fields' => 'files(id)',
                    'pageSize' => 200,
                ]);

            if (! $listing->successful()) {
                return;
            }

            $files = (array) $listing->json('files', []);

            foreach (array_slice($files, self::KEEP_ON_DRIVE) as $file) {
                $id = is_array($file) ? ($file['id'] ?? null) : null;

                if (is_string($id) && preg_match('/\A[A-Za-z0-9_-]{1,200}\z/', $id) === 1) {
                    Http::withToken($token)
                        ->connectTimeout(10)
                        ->timeout(20)
                        ->delete('https://www.googleapis.com/drive/v3/files/'.$id);
                }
            }
        } catch (Throwable $exception) {
            Log::warning('Server backup Drive clean-up failed.', ['exception' => $exception::class]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withOptionalClientSecret(array $payload): array
    {
        $secret = config('services.google.client_secret');

        if (is_string($secret) && $secret !== '') {
            $payload['client_secret'] = $secret;
        }

        return $payload;
    }

    private function expiresIn(mixed $value): int
    {
        return is_int($value) && $value > 0 && $value <= 604800 ? $value : 3600;
    }

    /**
     * @param  positive-int  $bytes
     */
    private function randomToken(int $bytes): string
    {
        return $this->base64Url(random_bytes($bytes));
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
