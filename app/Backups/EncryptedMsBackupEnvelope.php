<?php

namespace App\Backups;

use DateTimeImmutable;
use JsonException;

final readonly class EncryptedMsBackupEnvelope
{
    public const FORMAT_IDENTIFIER = 'medismart-encrypted-backup';

    public const FORMAT_VERSION = 2;

    public const ENVELOPE_VERSION = 1;

    public const ENCRYPTION_PROFILE = 'libsodium-secretstream-xchacha20poly1305-argon2id-v1';

    public const ENCRYPTION_ALGORITHM = 'xchacha20poly1305-secretstream';

    public const KDF_ALGORITHM = 'argon2id13';

    public const FRAMING = 'uint32be-length-prefixed';

    public function __construct(
        public string $createdAt,
        public int $plaintextSize,
        public string $plaintextSha256,
        public string $salt,
        public int $operationsLimit,
        public int $memoryLimitBytes,
        public string $streamHeader,
        public int $chunkBytes,
    ) {
        new MsBackupEncryptionParameters(
            $operationsLimit,
            $memoryLimitBytes,
            $chunkBytes,
        );

        if ($plaintextSize < 1 || $plaintextSize > MsBackupArchiveVerifier::MAXIMUM_ARCHIVE_BYTES
            || preg_match('/\A[a-f0-9]{64}\z/', $plaintextSha256) !== 1
            || strlen($salt) !== SODIUM_CRYPTO_PWHASH_SALTBYTES
            || strlen($streamHeader) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
            throw new BackupArchiveException('The encrypted backup envelope is malformed or unsupported.');
        }

        $created = DateTimeImmutable::createFromFormat(DATE_ATOM, $createdAt);

        if ($created === false || $created->format(DATE_ATOM) !== $createdAt) {
            throw new BackupArchiveException('The encrypted backup envelope is malformed or unsupported.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'format' => self::FORMAT_IDENTIFIER,
            'format_version' => self::FORMAT_VERSION,
            'envelope_version' => self::ENVELOPE_VERSION,
            'created_at' => $this->createdAt,
            'inner_archive' => [
                'format' => MsBackupArchiveCreator::FORMAT_IDENTIFIER,
                'format_version' => MsBackupArchiveCreator::FORMAT_VERSION,
                'size' => $this->plaintextSize,
                'sha256' => $this->plaintextSha256,
            ],
            'encryption' => [
                'enabled' => true,
                'profile' => self::ENCRYPTION_PROFILE,
                'algorithm' => self::ENCRYPTION_ALGORITHM,
                'recovery_secret' => 'user-passphrase',
                'kdf' => [
                    'algorithm' => self::KDF_ALGORITHM,
                    'salt' => $this->encodeBinary($this->salt),
                    'operations_limit' => $this->operationsLimit,
                    'memory_limit_bytes' => $this->memoryLimitBytes,
                ],
                'stream' => [
                    'header' => $this->encodeBinary($this->streamHeader),
                    'chunk_bytes' => $this->chunkBytes,
                    'framing' => self::FRAMING,
                    'final_tag_required' => true,
                ],
            ],
        ];
    }

    public function toJson(): string
    {
        try {
            return json_encode(
                $this->toArray(),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException) {
            throw new BackupArchiveException('The encrypted backup envelope could not be encoded.');
        }
    }

    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new BackupArchiveException('The encrypted backup envelope is malformed or unsupported.');
        }

        if (! is_array($data) || array_is_list($data)) {
            throw new BackupArchiveException('The encrypted backup envelope is malformed or unsupported.');
        }

        $inner = $data['inner_archive'] ?? null;
        $encryption = $data['encryption'] ?? null;
        $kdf = is_array($encryption) ? ($encryption['kdf'] ?? null) : null;
        $stream = is_array($encryption) ? ($encryption['stream'] ?? null) : null;

        if (($data['format'] ?? null) !== self::FORMAT_IDENTIFIER
            || ($data['format_version'] ?? null) !== self::FORMAT_VERSION
            || ($data['envelope_version'] ?? null) !== self::ENVELOPE_VERSION
            || ! is_string($data['created_at'] ?? null)
            || ! is_array($inner) || array_is_list($inner)
            || ($inner['format'] ?? null) !== MsBackupArchiveCreator::FORMAT_IDENTIFIER
            || ($inner['format_version'] ?? null) !== MsBackupArchiveCreator::FORMAT_VERSION
            || ! is_int($inner['size'] ?? null)
            || ! is_string($inner['sha256'] ?? null)
            || ! is_array($encryption) || array_is_list($encryption)
            || ($encryption['enabled'] ?? null) !== true
            || ($encryption['profile'] ?? null) !== self::ENCRYPTION_PROFILE
            || ($encryption['algorithm'] ?? null) !== self::ENCRYPTION_ALGORITHM
            || ($encryption['recovery_secret'] ?? null) !== 'user-passphrase'
            || ! is_array($kdf) || array_is_list($kdf)
            || ($kdf['algorithm'] ?? null) !== self::KDF_ALGORITHM
            || ! is_string($kdf['salt'] ?? null)
            || ! is_int($kdf['operations_limit'] ?? null)
            || ! is_int($kdf['memory_limit_bytes'] ?? null)
            || ! is_array($stream) || array_is_list($stream)
            || ! is_string($stream['header'] ?? null)
            || ! is_int($stream['chunk_bytes'] ?? null)
            || ($stream['framing'] ?? null) !== self::FRAMING
            || ($stream['final_tag_required'] ?? null) !== true) {
            throw new BackupArchiveException('The encrypted backup envelope is malformed or unsupported.');
        }

        return new self(
            createdAt: $data['created_at'],
            plaintextSize: $inner['size'],
            plaintextSha256: $inner['sha256'],
            salt: self::decodeBinary($kdf['salt']),
            operationsLimit: $kdf['operations_limit'],
            memoryLimitBytes: $kdf['memory_limit_bytes'],
            streamHeader: self::decodeBinary($stream['header']),
            chunkBytes: $stream['chunk_bytes'],
        );
    }

    private function encodeBinary(string $value): string
    {
        return sodium_bin2base64($value, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }

    private static function decodeBinary(string $value): string
    {
        try {
            return sodium_base642bin($value, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING, '');
        } catch (\SodiumException) {
            throw new BackupArchiveException('The encrypted backup envelope is malformed or unsupported.');
        }
    }
}
