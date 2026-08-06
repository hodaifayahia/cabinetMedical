<?php

namespace App\Backups;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Str;
use JsonException;

final readonly class VerifiedBackupMetadata
{
    public const FORMAT = 'msbackup';

    public const SUPPORTED_FORMAT_VERSIONS = [1, 2];

    private const MAXIMUM_SIZE_BYTES = 99_999_999_999_999;

    /** @param 1|2 $formatVersion */
    private function __construct(
        public string $managedFileId,
        public string $name,
        public int $sizeBytes,
        public string $createdAtUtc,
        public int $createdAtEpochSeconds,
        public int $createdAtNanoseconds,
        public string $sha256,
        public string $backupRecordId,
        public int $formatVersion,
    ) {}

    /**
     * @return array{metadata: self, reason_code: null}|array{metadata: null, reason_code: 'malformed_metadata'|'unverified_metadata'}
     */
    public static function fromUntrusted(mixed $raw): array
    {
        if (! is_array($raw) || array_is_list($raw)) {
            return ['metadata' => null, 'reason_code' => 'malformed_metadata'];
        }

        $sha256 = $raw['sha256'] ?? null;
        $verifiedSha256 = $raw['verified_sha256'] ?? null;

        if (($raw['verification_status'] ?? null) !== 'verified'
            || ! is_string($sha256) || preg_match('/\A[a-f0-9]{64}\z/', $sha256) !== 1
            || ! is_string($verifiedSha256) || preg_match('/\A[a-f0-9]{64}\z/', $verifiedSha256) !== 1
            || ! hash_equals($sha256, $verifiedSha256)) {
            return ['metadata' => null, 'reason_code' => 'unverified_metadata'];
        }

        $id = $raw['id'] ?? null;
        $name = $raw['name'] ?? null;
        $size = $raw['size_bytes'] ?? null;
        $createdAt = $raw['created_at'] ?? null;
        $backupRecordId = $raw['backup_record_id'] ?? null;

        if (($raw['format'] ?? null) !== self::FORMAT
            || ! is_int($raw['format_version'] ?? null)
            || ! in_array($raw['format_version'], self::SUPPORTED_FORMAT_VERSIONS, true)
            || ! is_string($id) || preg_match('/\A[A-Za-z0-9_-]{1,200}\z/', $id) !== 1
            || ! is_string($name) || ! self::validName($name)
            || ! is_int($size) || $size < 1 || $size > self::MAXIMUM_SIZE_BYTES
            || ! is_string($createdAt)
            || ! is_string($backupRecordId) || ! Str::isUuid($backupRecordId)) {
            return ['metadata' => null, 'reason_code' => 'malformed_metadata'];
        }

        $normalizedTime = self::normalizeTime($createdAt);

        if ($normalizedTime === null) {
            return ['metadata' => null, 'reason_code' => 'malformed_metadata'];
        }

        return [
            'metadata' => new self(
                managedFileId: $id,
                name: $name,
                sizeBytes: $size,
                createdAtUtc: $normalizedTime['utc'],
                createdAtEpochSeconds: $normalizedTime['seconds'],
                createdAtNanoseconds: $normalizedTime['nanoseconds'],
                sha256: $sha256,
                backupRecordId: Str::lower($backupRecordId),
                formatVersion: $raw['format_version'],
            ),
            'reason_code' => null,
        ];
    }

    public function dailyBucket(): string
    {
        return $this->utcDate()->format('Y-m-d');
    }

    public function weeklyBucket(): string
    {
        return $this->utcDate()->format('o-\WW');
    }

    public function monthlyBucket(): string
    {
        return $this->utcDate()->format('Y-m');
    }

    public function hasSameManagedMetadata(self $other): bool
    {
        return $this->managedFileId === $other->managedFileId
            && $this->name === $other->name
            && $this->sizeBytes === $other->sizeBytes
            && $this->createdAtUtc === $other->createdAtUtc
            && $this->sha256 === $other->sha256
            && $this->backupRecordId === $other->backupRecordId
            && $this->formatVersion === $other->formatVersion;
    }

    public function representsSameLogicalBackup(self $other): bool
    {
        return $this->backupRecordId === $other->backupRecordId
            && $this->sizeBytes === $other->sizeBytes
            && $this->createdAtUtc === $other->createdAtUtc
            && $this->sha256 === $other->sha256
            && $this->formatVersion === $other->formatVersion;
    }

    /**
     * Comparator for usort: newest first, then stable managed-file ID.
     */
    public static function compareNewest(self $left, self $right): int
    {
        $seconds = $right->createdAtEpochSeconds <=> $left->createdAtEpochSeconds;

        if ($seconds !== 0) {
            return $seconds;
        }

        $nanoseconds = $right->createdAtNanoseconds <=> $left->createdAtNanoseconds;

        return $nanoseconds !== 0
            ? $nanoseconds
            : strcmp($left->managedFileId, $right->managedFileId);
    }

    /**
     * @return array{
     *     managed_file_id: string,
     *     name: string,
     *     size_bytes: int,
     *     created_at_utc: string,
     *     sha256: string,
     *     backup_record_id: string,
     *     format: 'msbackup',
     *     format_version: 1|2,
     *     metadata_fingerprint: string
     * }
     */
    public function toPlanMetadata(): array
    {
        $metadata = [
            'managed_file_id' => $this->managedFileId,
            'name' => $this->name,
            'size_bytes' => $this->sizeBytes,
            'created_at_utc' => $this->createdAtUtc,
            'sha256' => $this->sha256,
            'backup_record_id' => $this->backupRecordId,
            'format' => self::FORMAT,
            'format_version' => $this->formatVersion,
        ];

        try {
            $encoded = json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new BackupArchiveException('Verified backup metadata could not be fingerprinted.');
        }

        $metadata['metadata_fingerprint'] = hash('sha256', $encoded);

        return $metadata;
    }

    private static function validName(string $name): bool
    {
        return $name !== ''
            && strlen($name) <= 255
            && preg_match('//u', $name) === 1
            && preg_match('/[\x00-\x1F\x7F]/', $name) !== 1
            && basename(str_replace('\\', '/', $name)) === $name
            && str_ends_with($name, '.msbackup');
    }

    /** @return array{utc: string, seconds: int, nanoseconds: int}|null */
    private static function normalizeTime(string $value): ?array
    {
        if (preg_match(
            '/\A([0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-5][0-9]:[0-5][0-9])(?:\.([0-9]{1,9}))?(Z|[+-](?:0[0-9]|1[0-4]):[0-5][0-9])\z/',
            $value,
            $matches,
        ) !== 1) {
            return null;
        }

        $offset = $matches[3] === 'Z' ? '+00:00' : $matches[3];

        if ($offset === '-00:00') {
            return null;
        }

        if (str_starts_with($offset, '+14:') || str_starts_with($offset, '-14:')) {
            if (! str_ends_with($offset, ':00')) {
                return null;
            }
        }

        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:sP',
            $matches[1].$offset,
            new DateTimeZone('UTC'),
        );
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false
            || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        $fraction = $matches[2];
        $nanoseconds = (int) str_pad($fraction, 9, '0');
        $seconds = (int) $date->format('U');
        $utc = (new DateTimeImmutable('@'.$seconds))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s').'.'.str_pad((string) $nanoseconds, 9, '0', STR_PAD_LEFT).'Z';

        return [
            'utc' => $utc,
            'seconds' => $seconds,
            'nanoseconds' => $nanoseconds,
        ];
    }

    private function utcDate(): DateTimeImmutable
    {
        return (new DateTimeImmutable('@'.$this->createdAtEpochSeconds))
            ->setTimezone(new DateTimeZone('UTC'));
    }
}
