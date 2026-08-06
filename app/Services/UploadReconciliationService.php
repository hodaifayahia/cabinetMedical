<?php

namespace App\Services;

use App\Models\ApplicationEvent;
use App\Models\AuditLog;
use App\Models\UploadedDocument;
use App\Models\UploadSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * @phpstan-type ReconciliationReport array{
 *     sessions_expired: int,
 *     rejected_files_deleted: int,
 *     orphan_files_deleted: int,
 *     attention_required: int
 * }
 */
final class UploadReconciliationService
{
    private const MAXIMUM_ROWS_PER_RUN = 500;

    private const MAXIMUM_FILES_PER_RUN = 5000;

    /**
     * Expire stale credentials and clean only files that are provably
     * disposable. Pending review files are retained and reported when their
     * location is inconsistent; accepted clinical documents are never in the
     * deletion scope.
     *
     * @return ReconciliationReport
     */
    public function reconcile(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $report = [
            'sessions_expired' => 0,
            'rejected_files_deleted' => 0,
            'orphan_files_deleted' => 0,
            'attention_required' => 0,
        ];

        $this->expireSessions($now, $report);
        $this->cleanRejectedStaging($report);
        $this->inspectPendingDocuments($report);
        $this->cleanOrphanQuarantine($now, $report);

        return $report;
    }

    /** @param ReconciliationReport $report */
    private function expireSessions(CarbonImmutable $now, array &$report): void
    {
        $sessions = UploadSession::query()
            ->whereIn('status', [
                UploadSession::STATUS_PENDING,
                UploadSession::STATUS_UPLOADING,
            ])
            ->where('expires_at', '<=', $now)
            ->limit(self::MAXIMUM_ROWS_PER_RUN)
            ->get();

        foreach ($sessions as $session) {
            $updated = UploadSession::query()
                ->whereKey($session->getKey())
                ->whereIn('status', [
                    UploadSession::STATUS_PENDING,
                    UploadSession::STATUS_UPLOADING,
                ])
                ->where('expires_at', '<=', $now)
                ->update([
                    'status' => UploadSession::STATUS_EXPIRED,
                    'public_token_hash' => hash('sha256', random_bytes(32)),
                ]);

            if ($updated !== 1) {
                continue;
            }

            $session->refresh();
            AuditLog::record('upload_session.expired', $session);
            $report['sessions_expired']++;
        }
    }

    /** @param ReconciliationReport $report */
    private function cleanRejectedStaging(array &$report): void
    {
        $disk = Storage::disk('local');
        $documents = UploadedDocument::query()
            ->where('status', UploadedDocument::STATUS_REJECTED)
            ->where('disk', 'local')
            ->where('path', 'like', 'upload-rejected/%')
            ->limit(self::MAXIMUM_ROWS_PER_RUN)
            ->get();

        foreach ($documents as $document) {
            $expectedPrefix = 'upload-rejected/'.$document->upload_session_id.'/';

            if (! str_starts_with($document->path, $expectedPrefix)
                || basename($document->path) === '') {
                $this->recordAttention($document, 'invalid_rejected_staging_path', $report);

                continue;
            }

            if (! $disk->exists($document->path)) {
                continue;
            }

            if ($disk->delete($document->path)) {
                $report['rejected_files_deleted']++;
            } else {
                $this->recordAttention($document, 'rejected_staging_delete_failed', $report);
            }
        }
    }

    /** @param ReconciliationReport $report */
    private function inspectPendingDocuments(array &$report): void
    {
        $disk = Storage::disk('local');
        $documents = UploadedDocument::query()
            ->whereIn('status', [
                UploadedDocument::STATUS_QUARANTINED,
                UploadedDocument::STATUS_PENDING_REVIEW,
            ])
            ->limit(self::MAXIMUM_ROWS_PER_RUN)
            ->get();

        foreach ($documents as $document) {
            $expected = 'upload-quarantine/'
                .$document->upload_session_id
                .'/'.$document->stored_name;

            if ($document->disk !== 'local' || ! hash_equals($expected, $document->path)) {
                $this->recordAttention($document, 'invalid_quarantine_path', $report);

                continue;
            }

            if (! $disk->exists($expected)) {
                $this->recordAttention($document, 'quarantine_file_missing', $report);
            }
        }
    }

    /** @param ReconciliationReport $report */
    private function cleanOrphanQuarantine(CarbonImmutable $now, array &$report): void
    {
        $disk = Storage::disk('local');

        try {
            $files = array_slice(
                $disk->allFiles('upload-quarantine'),
                0,
                self::MAXIMUM_FILES_PER_RUN,
            );
        } catch (Throwable) {
            ApplicationEvent::record(
                'UploadReconciliationRequired',
                'warning',
                context: ['reason' => 'quarantine_listing_failed'],
            );
            $report['attention_required']++;

            return;
        }

        if ($files === []) {
            return;
        }

        $known = UploadedDocument::query()
            ->whereIn('path', $files)
            ->pluck('path')
            ->filter(static fn (mixed $path): bool => is_string($path))
            ->flip();
        $deleteBefore = $now->subDay()->getTimestamp();

        foreach ($files as $path) {
            if ($known->has($path)) {
                continue;
            }

            if (preg_match(
                '/\Aupload-quarantine\/[0-9a-f-]{36}\/[A-Za-z0-9._-]+\z/i',
                $path,
            ) !== 1) {
                $this->recordOrphanAttention($path, 'invalid_orphan_path', $report);

                continue;
            }

            try {
                if ($disk->lastModified($path) > $deleteBefore) {
                    continue;
                }
            } catch (Throwable) {
                $this->recordOrphanAttention($path, 'orphan_timestamp_unavailable', $report);

                continue;
            }

            if ($disk->delete($path)) {
                $report['orphan_files_deleted']++;
            } else {
                $this->recordOrphanAttention($path, 'orphan_delete_failed', $report);
            }
        }
    }

    /** @param ReconciliationReport $report */
    private function recordAttention(
        UploadedDocument $document,
        string $reason,
        array &$report,
    ): void {
        $report['attention_required']++;
        $alreadyReported = ApplicationEvent::query()
            ->where('event', 'UploadReconciliationRequired')
            ->where('occurred_at', '>=', now()->subDay())
            ->where('context', 'like', '%'.$document->getKey().'%')
            ->where('context', 'like', '%'.$reason.'%')
            ->exists();

        if (! $alreadyReported) {
            ApplicationEvent::record(
                'UploadReconciliationRequired',
                'warning',
                context: [
                    'uploaded_document_id' => $document->getKey(),
                    'reason' => $reason,
                ],
            );
        }
    }

    /** @param ReconciliationReport $report */
    private function recordOrphanAttention(string $path, string $reason, array &$report): void
    {
        $report['attention_required']++;
        ApplicationEvent::record(
            'UploadReconciliationRequired',
            'warning',
            context: [
                'orphan_path_sha256' => hash('sha256', $path),
                'reason' => $reason,
            ],
        );
    }
}
