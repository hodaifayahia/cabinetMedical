import type { BackupHistoryEntry } from '@/types';

export const driveUploadStatusLabel = (
    status: BackupHistoryEntry['drive_upload_status'],
): string => {
    switch (status) {
        case 'queued':
            return 'En attente du worker';
        case 'uploading':
            return 'Envoi en cours';
        case 'retrying':
            return 'Nouvelle tentative planifiée';
        case 'cancel_requested':
            return 'Annulation demandée';
        case 'cancelled':
            return 'Envoi annulé';
        case 'completed':
            return 'Envoi confirmé';
        case 'failed':
            return 'Envoi en échec';
        default:
            return 'Aucun envoi demandé';
    }
};

export const boundedDriveUploadProgress = (
    value: number | null,
): number | null =>
    value === null || !Number.isFinite(value)
        ? null
        : Math.min(100, Math.max(0, Math.trunc(value)));

export const boundedDriveUploadAttempts = (value: number): number =>
    Number.isFinite(value) ? Math.min(3, Math.max(0, Math.trunc(value))) : 0;

export const boundedDriveUploadBytes = (
    uploaded: number | null,
    archiveSize: number | null,
): number | null => {
    if (
        uploaded === null ||
        archiveSize === null ||
        !Number.isFinite(uploaded) ||
        !Number.isFinite(archiveSize)
    ) {
        return null;
    }

    return Math.min(
        Math.max(0, Math.trunc(archiveSize)),
        Math.max(0, Math.trunc(uploaded)),
    );
};
