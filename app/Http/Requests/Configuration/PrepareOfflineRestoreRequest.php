<?php

namespace App\Http\Requests\Configuration;

use App\Backups\BackupArchiveException;
use App\Backups\BackupArchivePath;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

final class PrepareOfflineRestoreRequest extends FormRequest
{
    private const ABSOLUTE_MAXIMUM_ARCHIVE_BYTES = 25 * 1024 * 1024 * 1024;

    private const FAILURE_MESSAGE = 'La sauvegarde n\'a pas pu être authentifiée ou préparée.';

    /** @var array{path: string, size: int, device: int, inode: int}|null */
    private ?array $uploadedArchiveIdentity = null;

    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && $actor->hasAnyRole([
                RoleName::SUPER_ADMINISTRATOR->value,
                RoleName::ADMINISTRATOR->value,
            ])
            && $actor->can(PermissionName::CONFIGURATION_MANAGE->value)
            && $actor->can(PermissionName::SETTINGS_MANAGE->value);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'backup' => ['bail', 'required', 'file'],
            'passphrase' => ['bail', 'required', 'string', 'min:12', 'max:1024'],
        ];
    }

    public function uploadedArchivePath(): string
    {
        $current = $this->inspectUploadedArchive();

        if ($current === null
            || $this->uploadedArchiveIdentity === null
            || $current !== $this->uploadedArchiveIdentity) {
            throw new BackupArchiveException('The uploaded backup is no longer a readable request temporary file.');
        }

        return $current['path'];
    }

    public function recoveryPassphrase(): string
    {
        $passphrase = $this->input('passphrase');

        if (! is_string($passphrase)) {
            throw new BackupArchiveException('The recovery passphrase is unavailable.');
        }

        return $passphrase;
    }

    public function forgetRecoveryPassphrase(): void
    {
        foreach ([$this->request, $this->query] as $input) {
            $passphrase = $input->get('passphrase');

            if (is_string($passphrase) && function_exists('sodium_memzero')) {
                sodium_memzero($passphrase);
            }

            $input->remove('passphrase');
        }

        $validator = $this->getValidatorInstance();

        if ($validator instanceof Validator) {
            $data = $validator->getData();
            $passphrase = $data['passphrase'] ?? null;

            if (is_string($passphrase) && function_exists('sodium_memzero')) {
                sodium_memzero($passphrase);
            }

            unset($data['passphrase']);
            $validator->setData($data);
        }
    }

    protected function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $validator): void {
            $allowed = ['_token', 'backup', 'passphrase'];
            $unknown = array_diff(array_keys($this->all()), $allowed);

            if ($unknown !== []) {
                $validator->errors()->add('backup', self::FAILURE_MESSAGE);

                return;
            }

            $passphrase = $this->input('passphrase');

            if (is_string($passphrase)
                && (preg_match('//u', $passphrase) !== 1 || str_contains($passphrase, "\0"))) {
                $validator->errors()->add('passphrase', self::FAILURE_MESSAGE);
            }

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->uploadedArchiveIdentity = $this->inspectUploadedArchive();

            if ($this->uploadedArchiveIdentity === null) {
                $validator->errors()->add('backup', self::FAILURE_MESSAGE);
            }
        });
    }

    protected function failedValidation(ValidatorContract $validator): never
    {
        $this->forgetRecoveryPassphrase();

        throw new HttpResponseException(response()->json([
            'message' => self::FAILURE_MESSAGE,
        ], 422, self::responseHeaders()));
    }

    /** @return array<string, string> */
    public static function responseHeaders(): array
    {
        return [
            'Cache-Control' => 'no-store, private, max-age=0',
            'Pragma' => 'no-cache',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ];
    }

    public static function failureMessage(): string
    {
        return self::FAILURE_MESSAGE;
    }

    /** @return array{path: string, size: int, device: int, inode: int}|null */
    private function inspectUploadedArchive(): ?array
    {
        $upload = $this->file('backup');

        if (! $upload instanceof UploadedFile
            || ! $upload->isValid()
            || ! $this->validClientFilename($upload)) {
            return null;
        }

        $pathname = $upload->getPathname();

        if ($pathname === '' || str_contains($pathname, "\0") || is_link($pathname)) {
            return null;
        }

        $resolved = realpath($pathname);

        if (! is_string($resolved)
            || ! is_file($resolved)
            || ! is_readable($resolved)
            || is_link($resolved)) {
            return null;
        }

        $handle = @fopen($resolved, 'rb');

        if (! is_resource($handle) || ! flock($handle, LOCK_SH)) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            return null;
        }

        try {
            $stream = fstat($handle);
            $path = @stat($resolved);
            $reportedSize = $upload->getSize();
            $maximumBytes = $this->maximumArchiveBytes();

            if (! is_array($stream)
                || ! is_array($path)
                || ($stream['mode'] & 0170000) !== 0100000
                || $stream['size'] < 1
                || $maximumBytes < 1
                || $stream['size'] > $maximumBytes
                || ! is_int($reportedSize)
                || $reportedSize !== $stream['size']
                || $path['dev'] !== $stream['dev']
                || $path['ino'] !== $stream['ino']
                || $path['size'] !== $stream['size']) {
                return null;
            }

            return [
                'path' => $resolved,
                'size' => $stream['size'],
                'device' => $stream['dev'],
                'inode' => $stream['ino'],
            ];
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function validClientFilename(UploadedFile $upload): bool
    {
        $name = $upload->getClientOriginalName();

        if ($upload->getClientOriginalPath() !== $name
            || Str::lower(pathinfo($name, PATHINFO_EXTENSION)) !== 'msbackup') {
            return false;
        }

        try {
            BackupArchivePath::assertSafe($name);
        } catch (BackupArchiveException) {
            return false;
        }

        return true;
    }

    private function maximumArchiveBytes(): int
    {
        $configured = config(
            'medismart.backups.restore_upload_max_bytes',
            self::ABSOLUTE_MAXIMUM_ARCHIVE_BYTES,
        );

        if (! is_int($configured) || $configured < 1) {
            return 0;
        }

        return min($configured, self::ABSOLUTE_MAXIMUM_ARCHIVE_BYTES);
    }
}
