import { isHttpError, isValidationError } from '@/lib/http';
import type {
    OfflineRestorePreparation,
    OfflineRestoreRuntimeState,
} from '@/types';

const isRecord = (value: unknown): value is Record<string, unknown> =>
    typeof value === 'object' && value !== null;

export const normalizeOfflineRestorePreparation = (
    value: unknown,
): OfflineRestorePreparation | null => {
    if (
        !isRecord(value) ||
        !isRecord(value.authorization) ||
        !isRecord(value.backup)
    ) {
        return null;
    }

    const authorization = value.authorization;
    const backup = value.backup;

    if (
        authorization.protocol !== 'medismart-offline-restore-authorization' ||
        authorization.version !== 1 ||
        typeof authorization.operation_id !== 'string' ||
        !/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/.test(
            authorization.operation_id,
        ) ||
        typeof authorization.plan_sha256 !== 'string' ||
        !/^[0-9a-f]{64}$/.test(authorization.plan_sha256) ||
        typeof backup.created_at !== 'string' ||
        backup.created_at.length === 0 ||
        backup.created_at.length > 64 ||
        /[\u0000-\u001f\u007f]/.test(backup.created_at) ||
        typeof backup.application_version !== 'string' ||
        backup.application_version.length === 0 ||
        backup.application_version.length > 128 ||
        backup.application_version.trim() !== backup.application_version ||
        /[\u0000-\u001f\u007f]/.test(backup.application_version) ||
        !Number.isSafeInteger(backup.schema_version) ||
        Number(backup.schema_version) < 1 ||
        !Number.isSafeInteger(backup.file_count) ||
        Number(backup.file_count) < 0 ||
        !Number.isSafeInteger(backup.size_bytes) ||
        Number(backup.size_bytes) < 0 ||
        !Array.isArray(backup.components) ||
        backup.components.length !== 3
    ) {
        return null;
    }

    const expectedNames = [
        'database',
        'private_storage',
        'public_storage',
    ] as const;
    const components: OfflineRestorePreparation['backup']['components'] = [];

    for (const [index, component] of backup.components.entries()) {
        const expectedName = expectedNames[index];

        if (
            expectedName === undefined ||
            !isRecord(component) ||
            component.name !== expectedName ||
            !Number.isSafeInteger(component.file_count) ||
            Number(component.file_count) < 0 ||
            !Number.isSafeInteger(component.size_bytes) ||
            Number(component.size_bytes) < 0
        ) {
            return null;
        }

        components.push({
            name: expectedName,
            file_count: Number(component.file_count),
            size_bytes: Number(component.size_bytes),
        });
    }

    const totalFiles = components.reduce(
        (total, component) => total + component.file_count,
        0,
    );
    const totalBytes = components.reduce(
        (total, component) => total + component.size_bytes,
        0,
    );

    if (
        !Number.isSafeInteger(totalFiles) ||
        !Number.isSafeInteger(totalBytes) ||
        totalFiles !== backup.file_count ||
        totalBytes !== backup.size_bytes
    ) {
        return null;
    }

    return {
        authorization: {
            protocol: 'medismart-offline-restore-authorization',
            version: 1,
            operation_id: authorization.operation_id,
            plan_sha256: authorization.plan_sha256,
        },
        backup: {
            created_at: backup.created_at,
            application_version: backup.application_version,
            schema_version: Number(backup.schema_version),
            components,
            file_count: Number(backup.file_count),
            size_bytes: Number(backup.size_bytes),
        },
    };
};

export const offlineRestorePreparationErrorMessage = (
    error: unknown,
): string => {
    if (
        isValidationError(error) &&
        typeof error.message === 'string' &&
        error.message.trim() !== ''
    ) {
        return error.message;
    }

    if (isHttpError(error)) {
        if (error.status === 401) {
            return 'Votre session a expiré. Reconnectez-vous avant de préparer la restauration.';
        }

        if (error.status === 403) {
            return 'Seul un administrateur autorisé peut préparer une restauration.';
        }

        if (error.status === 413) {
            return 'Cette archive dépasse la taille maximale acceptée par l’installation.';
        }

        if (error.status === 419) {
            return 'La protection de session a expiré. Rechargez la page puis réessayez.';
        }

        if (error.status === 423) {
            return 'Confirmez de nouveau votre mot de passe avant de préparer la restauration.';
        }

        if (error.status === 429) {
            return 'Trop de tentatives ont été effectuées. Attendez quelques minutes avant de réessayer.';
        }
    }

    return 'La restauration n’a pas pu être préparée. Aucune donnée active n’a été modifiée.';
};

export const offlineRestoreNativeErrorMessage = (error: unknown): string => {
    if (
        isRecord(error) &&
        typeof error.message_fr === 'string' &&
        error.message_fr.trim() !== '' &&
        error.message_fr.length <= 1000 &&
        !/[\u0000-\u001f\u007f]/.test(error.message_fr)
    ) {
        return error.message_fr;
    }

    return 'La restauration a été refusée par le superviseur. Consultez l’état ci-dessous avant de réessayer.';
};

export const nativeRestoreRuntimeState = (
    value: unknown,
): OfflineRestoreRuntimeState => {
    if (!isRecord(value)) {
        return 'unchanged';
    }

    return value.runtime_state === 'verified_running' ||
        value.runtime_state === 'offline_recovery_required'
        ? value.runtime_state
        : 'unchanged';
};

export const offlineRestoreComponentLabel = (
    name: OfflineRestorePreparation['backup']['components'][number]['name'],
): string => {
    if (name === 'database') {
        return 'Base de données';
    }

    if (name === 'private_storage') {
        return 'Documents privés';
    }

    return 'Fichiers publics et logo';
};
