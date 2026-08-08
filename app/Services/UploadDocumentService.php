<?php

namespace App\Services;

use App\Models\ApplicationEvent;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\Patient;
use App\Models\UploadedDocument;
use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class UploadDocumentService
{
    /** @var array<string, list<string>> */
    private const SAFE_EXTENSIONS_BY_MIME = [
        'application/pdf' => ['pdf'],
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
    ];

    /**
     * @param  list<UploadedFile>  $files
     * @return list<UploadedDocument>
     */
    public function receive(
        UploadSession $session,
        array $files,
        ?string $sourceIp = null,
        ?string $userAgent = null,
    ): array {
        if (! $session->isUsable()) {
            throw ValidationException::withMessages([
                'files' => __('This upload session has expired or was revoked.'),
            ]);
        }

        if ($files === []) {
            throw ValidationException::withMessages(['files' => __('Select at least one file.')]);
        }

        if (count($files) > $session->maximum_files) {
            throw ValidationException::withMessages([
                'files' => __('This session accepts at most :count files.', [
                    'count' => $session->maximum_files,
                ]),
            ]);
        }

        $validated = [];
        $incomingBytes = 0;
        $allowedMimeTypes = $session->allowed_mime_types;

        foreach ($files as $index => $file) {
            if (! $file->isValid()) {
                throw ValidationException::withMessages([
                    "files.{$index}" => __('The file could not be uploaded.'),
                ]);
            }

            $size = $file->getSize();
            $mime = $file->getMimeType();
            $extension = Str::lower($file->getClientOriginalExtension());

            if (! is_int($size) || $size < 1 || $size > $session->maximum_individual_bytes) {
                throw ValidationException::withMessages([
                    "files.{$index}" => __('The file exceeds the allowed size.'),
                ]);
            }

            if (! is_string($mime)
                || ! in_array($mime, $allowedMimeTypes, true)
                || ! isset(self::SAFE_EXTENSIONS_BY_MIME[$mime])
                || ! in_array($extension, self::SAFE_EXTENSIONS_BY_MIME[$mime], true)) {
                throw ValidationException::withMessages([
                    "files.{$index}" => __('Only valid PDF, JPEG, and PNG files are accepted.'),
                ]);
            }

            $incomingBytes += $size;
            $validated[] = [
                'file' => $file,
                'size' => $size,
                'mime' => $mime,
                'extension' => $extension === 'jpeg' ? 'jpg' : $extension,
            ];
        }

        if ($incomingBytes > $session->maximum_total_bytes) {
            throw ValidationException::withMessages([
                'files' => __('The files exceed the total size allowed for this session.'),
            ]);
        }

        $storedPaths = [];
        $prepared = [];

        try {
            foreach ($validated as $validatedFile) {
                /** @var UploadedFile $file */
                $file = $validatedFile['file'];
                $storedName = Str::uuid()->toString().'.'.$validatedFile['extension'];
                $path = $file->storeAs(
                    'upload-quarantine/'.$session->getKey(),
                    $storedName,
                    'local',
                );

                if (! is_string($path)) {
                    throw new RuntimeException('The quarantined file could not be stored.');
                }

                $storedPaths[] = $path;
                $checksum = hash_file('sha256', Storage::disk('local')->path($path));

                if (! is_string($checksum)) {
                    throw new RuntimeException('The quarantined file checksum could not be calculated.');
                }

                $this->assertSafeStoredFile(
                    $path,
                    $validatedFile['mime'],
                    $validatedFile['size'],
                    $checksum,
                );

                $prepared[] = [
                    'original_name' => Str::limit(
                        basename(str_replace('\\', '/', $file->getClientOriginalName())),
                        190,
                        '',
                    ),
                    'stored_name' => $storedName,
                    'path' => $path,
                    'mime' => $validatedFile['mime'],
                    'size' => $validatedFile['size'],
                    'sha256' => $checksum,
                ];
            }

            /** @var list<UploadedDocument> $documents */
            $documents = DB::transaction(function () use (
                $session,
                $prepared,
                $incomingBytes,
                $sourceIp,
                $userAgent,
            ): array {
                $claimed = UploadSession::query()
                    ->whereKey($session->getKey())
                    ->whereIn('status', [UploadSession::STATUS_PENDING, UploadSession::STATUS_UPLOADING])
                    ->where('expires_at', '>', now())
                    ->update(['updated_at' => now()]);

                if ($claimed !== 1) {
                    throw ValidationException::withMessages([
                        'files' => __('This upload session has expired or was revoked.'),
                    ]);
                }

                /** @var UploadSession $lockedSession */
                $lockedSession = UploadSession::query()->lockForUpdate()->findOrFail($session->getKey());
                $existingCount = $lockedSession->documents()->count();
                $existingBytes = (int) $lockedSession->documents()->sum('size');

                if ($existingCount + count($prepared) > $lockedSession->maximum_files) {
                    throw ValidationException::withMessages([
                        'files' => __('This session accepts at most :count files.', [
                            'count' => $lockedSession->maximum_files,
                        ]),
                    ]);
                }

                if ($existingBytes + $incomingBytes > $lockedSession->maximum_total_bytes) {
                    throw ValidationException::withMessages([
                        'files' => __('The files exceed the total size allowed for this session.'),
                    ]);
                }

                $documents = [];

                foreach ($prepared as $preparedFile) {
                    $document = $lockedSession->documents()->make([
                        'patient_id' => $lockedSession->patient_id,
                        'original_name' => $preparedFile['original_name'],
                        'stored_name' => $preparedFile['stored_name'],
                        'disk' => 'local',
                        'path' => $preparedFile['path'],
                        'mime_type' => $preparedFile['mime'],
                        'size' => $preparedFile['size'],
                        'sha256' => $preparedFile['sha256'],
                        'status' => UploadedDocument::STATUS_PENDING_REVIEW,
                        'uploaded_at' => now(),
                    ]);
                    $document->forceFill([
                        'cabinet_id' => $lockedSession->cabinet_id,
                    ])->save();

                    AuditLog::record('upload.received', $document, [
                        'upload_session_id' => $lockedSession->getKey(),
                        'mime_type' => $document->mime_type,
                        'size' => $document->size,
                        'sha256' => $document->sha256,
                    ]);
                    ApplicationEvent::record('UploadReceived', context: [
                        'uploaded_document_id' => $document->getKey(),
                        'upload_session_id' => $lockedSession->getKey(),
                    ]);
                    $documents[] = $document;
                }

                $lockedSession->update([
                    'status' => UploadSession::STATUS_UPLOADING,
                    'source_ip' => $lockedSession->source_ip ?: $sourceIp,
                    'user_agent' => $lockedSession->user_agent ?: Str::limit((string) $userAgent, 1000, ''),
                ]);

                return $documents;
            });

            return $documents;
        } catch (Throwable $exception) {
            foreach ($storedPaths as $storedPath) {
                Storage::disk('local')->delete($storedPath);
            }

            throw $exception;
        }
    }

    public function accept(UploadedDocument $uploaded, User $reviewer, ?Patient $patient = null): Document
    {
        $patient ??= $uploaded->patient ?? $uploaded->uploadSession()->first()?->patient;

        if (! $patient instanceof Patient) {
            throw ValidationException::withMessages([
                'patient_id' => __('Select the patient who should receive this document.'),
            ]);
        }

        $sourcePath = null;
        $destinationPath = null;
        $moved = false;
        $disk = Storage::disk('local');

        try {
            return DB::transaction(function () use (
                $uploaded,
                $reviewer,
                $patient,
                $disk,
                &$sourcePath,
                &$destinationPath,
                &$moved,
            ): Document {
                $claimed = UploadedDocument::query()
                    ->whereKey($uploaded->getKey())
                    ->where('status', UploadedDocument::STATUS_PENDING_REVIEW)
                    ->update(['updated_at' => now()]);

                if ($claimed !== 1) {
                    throw ValidationException::withMessages([
                        'document' => __('This file has already been reviewed.'),
                    ]);
                }

                /** @var UploadedDocument $locked */
                $locked = UploadedDocument::query()->lockForUpdate()->findOrFail($uploaded->getKey());
                $session = $locked->uploadSession()->firstOrFail();

                if ($session->purpose !== 'medical_document') {
                    throw ValidationException::withMessages([
                        'document' => __('This upload purpose cannot be added to a patient record.'),
                    ]);
                }

                $boundPatientId = $locked->patient_id ?? $session->patient_id;

                if ($boundPatientId !== null && (int) $boundPatientId !== (int) $patient->getKey()) {
                    throw ValidationException::withMessages([
                        'patient_id' => __('This upload is bound to a different patient.'),
                    ]);
                }

                $previewed = AuditLog::query()
                    ->where('action', 'upload.previewed')
                    ->where('subject_type', $locked->getMorphClass())
                    ->where('subject_id', (string) $locked->getKey())
                    ->where('user_id', $reviewer->getKey())
                    ->where('created_at', '>=', $locked->uploaded_at)
                    ->exists();

                if (! $previewed) {
                    throw ValidationException::withMessages([
                        'document' => __('Preview this file before accepting it.'),
                    ]);
                }

                $sourcePath = $this->quarantinePath($locked);
                $destinationPath = 'patient-documents/'.$patient->getKey().'/'.$locked->stored_name;
                $this->assertSafeStoredFile(
                    $sourcePath,
                    $locked->mime_type,
                    $locked->size,
                    $locked->sha256,
                );

                if ($disk->exists($destinationPath)
                    || ! $disk->move($sourcePath, $destinationPath)) {
                    throw new RuntimeException('The accepted file could not be moved into managed storage.');
                }

                $moved = true;

                $title = trim(pathinfo($locked->original_name, PATHINFO_FILENAME));
                $document = Document::query()->create([
                    'patient_id' => $patient->getKey(),
                    'category' => 'uploaded',
                    'title' => Str::limit($title !== '' ? $title : __('Uploaded document'), 200, ''),
                    'file_path' => $destinationPath,
                    'original_filename' => $locked->original_name,
                    'mime_type' => $locked->mime_type,
                    'file_size' => $locked->size,
                    'created_by' => $reviewer->getKey(),
                ]);

                $locked->update([
                    'patient_id' => $patient->getKey(),
                    'document_id' => $document->getKey(),
                    'path' => $destinationPath,
                    'status' => UploadedDocument::STATUS_ACCEPTED,
                    'reviewed_by' => $reviewer->getKey(),
                    'reviewed_at' => now(),
                ]);

                AuditLog::record('upload.accepted', $locked, [
                    'document_id' => $document->getKey(),
                    'patient_id' => $patient->getKey(),
                ], $reviewer->getKey());

                return $document;
            });
        } catch (Throwable $exception) {
            if ($moved
                && is_string($sourcePath)
                && is_string($destinationPath)
                && $disk->exists($destinationPath)
                && ! $disk->move($destinationPath, $sourcePath)) {
                ApplicationEvent::record('UploadRecoveryRequired', 'critical', context: [
                    'uploaded_document_id' => $uploaded->getKey(),
                    'operation' => 'accept',
                ]);
            }

            throw $exception;
        }
    }

    public function reject(UploadedDocument $uploaded, User $reviewer): void
    {
        $disk = Storage::disk('local');
        $sourcePath = null;
        $stagingPath = null;
        $moved = false;

        try {
            DB::transaction(function () use (
                $uploaded,
                $reviewer,
                $disk,
                &$sourcePath,
                &$stagingPath,
                &$moved,
            ): void {
                $claimed = UploadedDocument::query()
                    ->whereKey($uploaded->getKey())
                    ->where('status', UploadedDocument::STATUS_PENDING_REVIEW)
                    ->update(['updated_at' => now()]);

                if ($claimed !== 1) {
                    throw ValidationException::withMessages([
                        'document' => __('This file has already been reviewed.'),
                    ]);
                }

                /** @var UploadedDocument $locked */
                $locked = UploadedDocument::query()->lockForUpdate()->findOrFail($uploaded->getKey());
                $sourcePath = $this->quarantinePath($locked);
                $this->assertSafeStoredFile(
                    $sourcePath,
                    $locked->mime_type,
                    $locked->size,
                    $locked->sha256,
                );
                $stagingPath = 'upload-rejected/'.$locked->upload_session_id.'/'.Str::uuid().'.discard';

                if (! $disk->move($sourcePath, $stagingPath)) {
                    throw new RuntimeException('The rejected file could not be isolated for deletion.');
                }

                $moved = true;
                $locked->update([
                    'path' => $stagingPath,
                    'status' => UploadedDocument::STATUS_REJECTED,
                    'reviewed_by' => $reviewer->getKey(),
                    'reviewed_at' => now(),
                ]);
                AuditLog::record('upload.rejected', $locked, [], $reviewer->getKey());
            });
        } catch (Throwable $exception) {
            if ($moved
                && is_string($sourcePath)
                && is_string($stagingPath)
                && $disk->exists($stagingPath)
                && ! $disk->move($stagingPath, $sourcePath)) {
                ApplicationEvent::record('UploadRecoveryRequired', 'critical', context: [
                    'uploaded_document_id' => $uploaded->getKey(),
                    'operation' => 'reject',
                ]);
            }

            throw $exception;
        }

        if (is_string($stagingPath) && ! $disk->delete($stagingPath)) {
            ApplicationEvent::record('UploadCleanupRequired', 'warning', context: [
                'uploaded_document_id' => $uploaded->getKey(),
                'operation' => 'reject',
            ]);
        }
    }

    public function assertReviewable(UploadedDocument $uploaded): string
    {
        if ($uploaded->status !== UploadedDocument::STATUS_PENDING_REVIEW) {
            throw ValidationException::withMessages([
                'document' => __('This file has already been reviewed.'),
            ]);
        }

        $path = $this->quarantinePath($uploaded);
        $this->assertSafeStoredFile(
            $path,
            $uploaded->mime_type,
            $uploaded->size,
            $uploaded->sha256,
        );

        return $path;
    }

    private function quarantinePath(UploadedDocument $uploaded): string
    {
        $expected = 'upload-quarantine/'.$uploaded->upload_session_id.'/'.$uploaded->stored_name;

        if ($uploaded->disk !== 'local' || ! hash_equals($expected, $uploaded->path)) {
            throw ValidationException::withMessages([
                'document' => __('The quarantine location is invalid.'),
            ]);
        }

        return $expected;
    }

    private function assertSafeStoredFile(
        string $path,
        string $expectedMime,
        int $expectedSize,
        string $expectedChecksum,
    ): void {
        $disk = Storage::disk('local');

        if (! $disk->exists($path)) {
            throw ValidationException::withMessages([
                'document' => __('The quarantined file is no longer available.'),
            ]);
        }

        $absolutePath = $disk->path($path);
        $size = filesize($absolutePath);
        $checksum = hash_file('sha256', $absolutePath);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($absolutePath);

        if ($size !== $expectedSize
            || ! is_string($checksum)
            || ! hash_equals($expectedChecksum, $checksum)
            || $mime !== $expectedMime) {
            throw ValidationException::withMessages([
                'document' => __('The quarantined file failed its integrity check.'),
            ]);
        }

        if ($expectedMime === 'application/pdf') {
            $handle = fopen($absolutePath, 'rb');
            $header = is_resource($handle) ? fread($handle, 5) : false;
            $tailLength = max(1, min(2048, $expectedSize));

            if (is_resource($handle)) {
                fseek($handle, -$tailLength, SEEK_END);
                $tail = fread($handle, $tailLength);
                fclose($handle);
            } else {
                $tail = false;
            }

            if ($header !== '%PDF-' || ! is_string($tail) || ! str_contains($tail, '%%EOF')) {
                throw ValidationException::withMessages([
                    'document' => __('The PDF file is malformed.'),
                ]);
            }
        }

        if (in_array($expectedMime, ['image/jpeg', 'image/png'], true)) {
            $image = @getimagesize($absolutePath);
            $expectedType = $expectedMime === 'image/jpeg' ? IMAGETYPE_JPEG : IMAGETYPE_PNG;

            if (! is_array($image)
                || $image[2] !== $expectedType
                || $image[0] < 1
                || $image[1] < 1
                || $image[0] > 12000
                || $image[1] > 12000
                || $image[0] * $image[1] > 40_000_000) {
                throw ValidationException::withMessages([
                    'document' => __('The image is malformed or its dimensions are too large.'),
                ]);
            }
        }
    }
}
