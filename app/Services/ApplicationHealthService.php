<?php

namespace App\Services;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class ApplicationHealthService
{
    public function __construct(
        private readonly NetworkService $network,
        private readonly TunnelService $tunnel,
        private readonly LicenseService $licenses,
        private readonly RemoteUploadBoundary $remoteUploadBoundary,
        private readonly LanUploadBoundary $lanUploadBoundary,
        private readonly Migrator $migrator,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function status(?Request $request = null): array
    {
        $database = $this->databaseStatus();
        $storage = $this->storageStatus();
        $queue = $this->queueStatus();
        $scheduler = $this->schedulerStatus();
        $tunnel = $this->safeTunnelStatus();
        $localAddress = $this->safeLocalAddress();
        $lanListenerActive = $localAddress !== null && $this->network->lanListenerActive();
        $healthy = $database['connected']
            && $database['foundation_ready']
            && $database['migrations_current']
            && $storage['writable']
            && $queue['operational'];

        return [
            'status' => $healthy ? 'healthy' : 'degraded',
            'application' => [
                'name' => (string) config('app.name'),
                'version' => (string) config('medismart.version', 'unknown'),
                'environment' => (string) config('app.env'),
            ],
            'database' => $database,
            'storage' => $storage,
            'queue' => $queue,
            'scheduler' => $scheduler,
            'lan_listener' => [
                'status' => $localAddress === null
                    ? 'unavailable'
                    : ($lanListenerActive ? 'active' : 'stopped'),
                'address' => $localAddress,
                'upload_base_url' => $lanListenerActive ? $this->safeLocalUploadBaseUrl() : null,
            ],
            'tunnel' => $tunnel,
            'urls' => [
                'local' => (string) config('medismart.runtime.local_url'),
                'remote' => $tunnel['runtime_state'] === 'active'
                    && $this->remoteUploadBoundary->tunnelMatchesConfiguredHost($tunnel)
                    ? $this->remoteUploadBoundary->configuredRemoteOrigin()
                    : null,
            ],
            'license' => $this->safeLicenseStatus(),
            'lan_upload_boundary' => $this->lanUploadBoundary->attestation($request),
            'remote_upload_boundary' => $this->remoteUploadBoundary->attestation($request),
            'checked_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array{
     *     connected: bool,
     *     foundation_ready: bool,
     *     migrations_current: bool,
     *     pending_migrations: int|null,
     *     driver: string,
     *     schema_version: int|null,
     *     latest_migration: string|null,
     *     latest_available_migration: string|null,
     *     error: string|null
     * }
     */
    private function databaseStatus(): array
    {
        try {
            DB::connection()->select('select 1');
            $latestMigration = Schema::hasTable('migrations')
                ? DB::table('migrations')->orderByDesc('id')->value('migration')
                : null;
            $migrationFiles = $this->migrator->getMigrationFiles([
                database_path('migrations'),
            ]);
            $availableMigrations = array_keys($migrationFiles);
            $ranMigrations = $this->migrator->repositoryExists()
                ? $this->migrator->getRepository()->getRan()
                : [];
            $pendingMigrations = array_values(array_diff($availableMigrations, $ranMigrations));
            sort($pendingMigrations);
            $latestAvailableMigration = $availableMigrations === []
                ? null
                : end($availableMigrations);

            return [
                'connected' => true,
                'foundation_ready' => Schema::hasTable('application_settings')
                    && Schema::hasTable('upload_sessions')
                    && Schema::hasTable('audit_logs'),
                'migrations_current' => $pendingMigrations === [],
                'pending_migrations' => count($pendingMigrations),
                'driver' => DB::connection()->getDriverName(),
                'schema_version' => Schema::hasTable('migrations') ? DB::table('migrations')->count() : null,
                'latest_migration' => is_string($latestMigration) ? $latestMigration : null,
                'latest_available_migration' => is_string($latestAvailableMigration)
                    ? $latestAvailableMigration
                    : null,
                'error' => null,
            ];
        } catch (Throwable) {
            return [
                'connected' => false,
                'foundation_ready' => false,
                'migrations_current' => false,
                'pending_migrations' => null,
                'driver' => (string) config('database.default'),
                'schema_version' => null,
                'latest_migration' => null,
                'latest_available_migration' => null,
                'error' => 'database_unavailable',
            ];
        }
    }

    /** @return array{writable: bool, path: string, free_bytes: int|null} */
    private function storageStatus(): array
    {
        $path = storage_path('app/private');
        $checkPath = is_dir($path) ? $path : dirname($path);
        $freeBytes = @disk_free_space($checkPath);

        return [
            'writable' => $this->probeStorageWrite($path),
            'path' => 'storage/app/private',
            'free_bytes' => is_float($freeBytes) ? (int) $freeBytes : null,
        ];
    }

    private function probeStorageWrite(string $directory): bool
    {
        if (! is_dir($directory) || ! is_writable($directory)) {
            return false;
        }

        try {
            $path = $directory.DIRECTORY_SEPARATOR.'.health-'.bin2hex(random_bytes(12)).'.tmp';
        } catch (Throwable) {
            return false;
        }

        $handle = @fopen($path, 'x+b');

        if ($handle === false) {
            return false;
        }

        try {
            $probe = 'medismart-storage-health-v1';
            $written = @fwrite($handle, $probe);

            return $written === strlen($probe) && @fflush($handle);
        } finally {
            @fclose($handle);
            @unlink($path);
        }
    }

    /**
     * @return array{
     *     connection: string,
     *     available: bool,
     *     worker_status: 'active'|'stopped'|'not_required',
     *     operational: bool,
     *     observation_source: 'native_supervisor_process_contract'|'not_required'|'unverified',
     *     pending: int|null,
     *     failed: int|null
     * }
     */
    private function queueStatus(): array
    {
        $connection = (string) config('queue.default');
        $desktopSupervised = (bool) config('medismart.runtime.desktop_supervised', false);
        $workerActive = $desktopSupervised
            && config('medismart.runtime.queue_worker_status') === 'active';

        if ($connection !== 'database') {
            return [
                'connection' => $connection,
                'available' => true,
                'worker_status' => 'not_required',
                'operational' => true,
                'observation_source' => 'not_required',
                'pending' => null,
                'failed' => null,
            ];
        }

        try {
            $available = Schema::hasTable('jobs');

            return [
                'connection' => $connection,
                'available' => $available,
                'worker_status' => $workerActive ? 'active' : 'stopped',
                'operational' => $available && $workerActive,
                'observation_source' => $desktopSupervised
                    ? 'native_supervisor_process_contract'
                    : 'unverified',
                'pending' => $available ? DB::table('jobs')->count() : null,
                'failed' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : null,
            ];
        } catch (Throwable) {
            return [
                'connection' => $connection,
                'available' => false,
                'worker_status' => $workerActive ? 'active' : 'stopped',
                'operational' => false,
                'observation_source' => $desktopSupervised
                    ? 'native_supervisor_process_contract'
                    : 'unverified',
                'pending' => null,
                'failed' => null,
            ];
        }
    }

    /**
     * @return array{
     *     status: 'active'|'stopped',
     *     observation_source: 'native_supervisor_process_contract'|'unverified',
     *     process_bound: true
     * }
     */
    private function schedulerStatus(): array
    {
        $desktopSupervised = (bool) config('medismart.runtime.desktop_supervised', false);

        return [
            'status' => $desktopSupervised
                && config('medismart.runtime.scheduler_status') === 'active'
                    ? 'active'
                    : 'stopped',
            'observation_source' => $desktopSupervised
                ? 'native_supervisor_process_contract'
                : 'unverified',
            // The native supervisor restarts Laravel when an observed child
            // status changes, so this attestation belongs to this PHP process.
            'process_bound' => true,
        ];
    }

    /** @return array<string, bool|string|null> */
    private function safeTunnelStatus(): array
    {
        try {
            return $this->tunnel->status();
        } catch (Throwable) {
            return [
                'configured' => false,
                'provider' => 'cloudflare',
                'mode' => 'named',
                'hostname' => null,
                'service_installed' => false,
                'desired_state' => 'stopped',
                'runtime_state' => 'unavailable',
                'last_health_check_at' => null,
                'last_error' => 'settings_unavailable',
            ];
        }
    }

    private function safeLocalAddress(): ?string
    {
        try {
            return $this->network->preferredIpv4();
        } catch (Throwable) {
            return null;
        }
    }

    private function safeLocalUploadBaseUrl(): ?string
    {
        try {
            return $this->network->localUploadBaseUrl();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{
     *     state: string,
     *     edition: string|null,
     *     expires_at: string|null,
     *     offline_grace_until: string|null,
     *     last_verified_at: string|null,
     *     clock_warning: bool
     * }
     */
    private function safeLicenseStatus(): array
    {
        try {
            return $this->licenses->status();
        } catch (Throwable) {
            return [
                'state' => 'server_unavailable',
                'edition' => null,
                'expires_at' => null,
                'offline_grace_until' => null,
                'last_verified_at' => null,
                'clock_warning' => false,
            ];
        }
    }
}
