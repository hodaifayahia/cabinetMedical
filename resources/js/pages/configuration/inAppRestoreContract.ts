import { isHttpError, isValidationError } from '@/lib/http';
import type { InAppRestorePreparation, LocalBackupEntry } from '@/types';

const isRecord = (value: unknown): value is Record<string, unknown> =>
    typeof value === 'object' && value !== null;

const boundedText = (value: unknown, maximum: number): string | null =>
    typeof value === 'string' &&
    value.length > 0 &&
    value.length <= maximum &&
    !/[\u0000-\u001f\u007f]/.test(value)
        ? value
        : null;

const count = (value: unknown): number | null =>
    Number.isSafeInteger(value) && Number(value) >= 0 ? Number(value) : null;

/** The verified summary returned by `backup/archives/restore/prepare`. */
export const normalizeInAppRestorePreparation = (
    value: unknown,
): InAppRestorePreparation | null => {
    if (!isRecord(value) || !isRecord(value.summary)) {
        return null;
    }

    const summary = value.summary;
    const operationId = value.operation_id;
    const patients = count(summary.patients);
    const users = count(summary.users);
    const fileCount = count(summary.file_count);

    if (
        typeof operationId !== 'string' ||
        !/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/.test(
            operationId,
        ) ||
        value.confirmation !== 'RESTAURER' ||
        patients === null ||
        users === null ||
        fileCount === null ||
        typeof summary.encrypted !== 'boolean'
    ) {
        return null;
    }

    return {
        operation_id: operationId,
        source: boundedText(value.source, 255) ?? 'sauvegarde.msbackup',
        confirmation: 'RESTAURER',
        summary: {
            cabinet: boundedText(summary.cabinet, 255),
            patients,
            users,
            consultations: count(summary.consultations),
            created_at: boundedText(summary.created_at, 64),
            application_version: boundedText(summary.application_version, 128),
            file_count: fileCount,
            encrypted: summary.encrypted,
        },
    };
};

/** The first validation message, or a French fallback for any other failure. */
export const inAppRestoreErrorMessage = (
    error: unknown,
    fallback = 'La sauvegarde n’a pas pu être vérifiée. Réessayez.',
): string => {
    if (isValidationError(error)) {
        for (const field of [
            'passphrase',
            'backup',
            'archive',
            'confirmation',
            'confirmed',
            'operation_id',
        ]) {
            const message = error.errors[field]?.[0];

            if (message) {
                return message;
            }
        }

        return error.message ?? fallback;
    }

    if (isHttpError(error)) {
        if (error.status === 423) {
            return 'Confirmez d’abord votre mot de passe (bouton « Confirmer mon mot de passe » en haut de la page).';
        }

        if (error.status === 403) {
            return 'Seul le médecin du cabinet peut restaurer une sauvegarde sur ce PC.';
        }

        if (error.status === 413) {
            return 'Ce fichier est trop volumineux pour être envoyé.';
        }

        if (error.status === 429) {
            return 'Trop de tentatives : patientez une minute avant de réessayer.';
        }

        if (error.status === 503 && error.message) {
            return error.message;
        }
    }

    return fallback;
};

export const backupKindLabel = (kind: LocalBackupEntry['kind']): string => {
    switch (kind) {
        case 'manual':
            return 'Manuelle';
        case 'scheduled':
            return 'Automatique';
        case 'safety':
            return 'Avant restauration';
        case 'drive_download':
            return 'Téléchargée de Drive';
        default:
            return 'Autre';
    }
};
