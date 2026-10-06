<?php

namespace App\CabinetTransfer;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use JsonException;

/**
 * Where a transfer to this PC stands, kept in one file so each step of the
 * background job resumes where the previous one stopped and the progress
 * page can read it. The online token is stored encrypted with this PC's key.
 */
final class TransferState
{
    public const RUNNING = 'running';

    public const IMPORTED = 'imported';

    public const PURGED = 'purged';

    public const FAILED = 'failed';

    /** @param array<string, mixed> $data */
    private function __construct(private array $data) {}

    /**
     * @param  array<string, mixed>  $remoteCabinet
     */
    public static function begin(
        string $endpoint,
        string $token,
        string $envelope,
        array $remoteCabinet,
        ?string $accountEmail,
        ?string $accountCabinetName,
    ): self {
        $state = new self([
            'status' => self::RUNNING,
            'phase' => 'manifest',
            'message' => 'Préparation du transfert…',
            'endpoint' => $endpoint,
            'token' => Crypt::encryptString($token),
            'envelope' => $envelope,
            'remote_cabinet' => $remoteCabinet,
            'account_email' => $accountEmail,
            'account_cabinet_name' => $accountCabinetName,
            'manifest' => null,
            'file_index' => 0,
            'table_index' => 0,
            'after' => 0,
            'copied_rows' => 0,
            'copied_files' => 0,
            'started_at' => now()->toIso8601String(),
        ]);
        $state->save();

        return $state;
    }

    public static function current(): ?self
    {
        $path = self::path();

        if (! is_file($path)) {
            return null;
        }

        try {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($data) ? new self($data) : null;
    }

    public static function clear(): void
    {
        File::delete(self::path());
    }

    public static function stagingDirectory(): string
    {
        return storage_path('app/private/cabinet-transfer/staging');
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /** @param array<string, mixed> $values */
    public function update(array $values): self
    {
        $this->data = [...$this->data, ...$values];
        $this->save();

        return $this;
    }

    public function token(): string
    {
        return Crypt::decryptString((string) $this->data['token']);
    }

    public function status(): string
    {
        return (string) ($this->data['status'] ?? self::FAILED);
    }

    /**
     * What the progress page may show: never the token or the licence.
     *
     * @return array<string, mixed>
     */
    public function publicView(): array
    {
        $manifest = is_array($this->data['manifest'] ?? null) ? $this->data['manifest'] : null;

        return [
            'status' => $this->status(),
            'phase' => $this->data['phase'] ?? null,
            'message' => $this->data['message'] ?? null,
            'error' => $this->data['error'] ?? null,
            'copied_rows' => (int) ($this->data['copied_rows'] ?? 0),
            'total_rows' => $manifest === null ? null : array_sum(array_map('intval', $manifest['tables'] ?? [])),
            'copied_files' => (int) ($this->data['copied_files'] ?? 0),
            'total_files' => $manifest === null ? null : count($manifest['files'] ?? []),
            'missing_files' => $manifest === null ? 0 : (int) ($manifest['missing_files'] ?? 0),
            'summary' => $this->data['summary'] ?? null,
            'owner_email' => $this->data['account_email'] ?? null,
            'transferred_at' => $this->data['transferred_at'] ?? null,
            'purge_error' => $this->data['purge_error'] ?? null,
        ];
    }

    private function save(): void
    {
        File::ensureDirectoryExists(dirname(self::path()));
        File::put(self::path(), json_encode($this->data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private static function path(): string
    {
        return storage_path('app/private/cabinet-transfer/state.json');
    }
}
