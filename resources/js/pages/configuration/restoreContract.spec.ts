import { describe, expect, it } from 'vitest';

import { HttpError } from '@/lib/http';
import type { OfflineRestorePreparation } from '@/types';
import {
    nativeRestoreRuntimeState,
    normalizeOfflineRestorePreparation,
    offlineRestoreComponentLabel,
    offlineRestoreNativeErrorMessage,
    offlineRestorePreparationErrorMessage,
} from './restoreContract';

const validPreparation = (): OfflineRestorePreparation => ({
    authorization: {
        protocol: 'medismart-offline-restore-authorization',
        version: 1,
        operation_id: '0192f4fd-7ee8-7fd2-8b40-d43d645aab12',
        plan_sha256: 'a'.repeat(64),
    },
    backup: {
        created_at: '2026-08-04T03:00:00+01:00',
        application_version: '2.3.0',
        schema_version: 2,
        components: [
            { name: 'database', file_count: 1, size_bytes: 4096 },
            { name: 'private_storage', file_count: 3, size_bytes: 8192 },
            { name: 'public_storage', file_count: 1, size_bytes: 1024 },
        ],
        file_count: 5,
        size_bytes: 13_312,
    },
});

const clone = <T>(value: T): T => JSON.parse(JSON.stringify(value)) as T;

describe('offline restore browser contract', () => {
    it('normalizes the exact safe artifact and strips unrelated server data', () => {
        const preparation = validPreparation() as OfflineRestorePreparation & {
            path?: string;
            secret?: string;
        };
        preparation.path = 'C:\\private\\restore';
        preparation.secret = 'must-not-cross-the-contract';

        expect(normalizeOfflineRestorePreparation(preparation)).toEqual(
            validPreparation(),
        );
    });

    it.each([
        [
            'non-canonical operation id',
            (value: OfflineRestorePreparation) => {
                value.authorization.operation_id = 'NOT-A-UUID';
            },
        ],
        [
            'non-lowercase plan hash',
            (value: OfflineRestorePreparation) => {
                value.authorization.plan_sha256 = 'A'.repeat(64);
            },
        ],
        [
            'wrong component order',
            (value: OfflineRestorePreparation) => {
                value.backup.components[0] = {
                    name: 'private_storage',
                    file_count: 1,
                    size_bytes: 4096,
                };
            },
        ],
        [
            'mismatched totals',
            (value: OfflineRestorePreparation) => {
                value.backup.file_count += 1;
            },
        ],
        [
            'control characters',
            (value: OfflineRestorePreparation) => {
                value.backup.application_version = '2.3.0\npath';
            },
        ],
    ])('rejects %s', (_label, mutate) => {
        const preparation = clone(validPreparation());
        mutate(preparation);

        expect(normalizeOfflineRestorePreparation(preparation)).toBeNull();
    });

    it('maps authentication and upload boundaries to actionable French text', () => {
        expect(
            offlineRestorePreparationErrorMessage(
                new HttpError(423, 'Password confirmation required.'),
            ),
        ).toContain('mot de passe');
        expect(
            offlineRestorePreparationErrorMessage(
                new HttpError(413, 'Payload Too Large'),
            ),
        ).toContain('taille maximale');
        expect(
            offlineRestorePreparationErrorMessage(
                new HttpError(429, 'Too Many Requests'),
            ),
        ).toContain('Trop de tentatives');
        expect(
            offlineRestorePreparationErrorMessage({
                validation: true,
                errors: {},
                message:
                    "La sauvegarde n'a pas pu être authentifiée ou préparée.",
            }),
        ).toBe("La sauvegarde n'a pas pu être authentifiée ou préparée.");
    });

    it('accepts only bounded French native messages and known runtime states', () => {
        expect(
            offlineRestoreNativeErrorMessage({
                message_fr: 'La restauration a été refusée sans modification.',
            }),
        ).toBe('La restauration a été refusée sans modification.');
        expect(
            offlineRestoreNativeErrorMessage({ message_fr: 'unsafe\nmessage' }),
        ).not.toContain('unsafe');
        expect(
            nativeRestoreRuntimeState({
                runtime_state: 'offline_recovery_required',
            }),
        ).toBe('offline_recovery_required');
        expect(nativeRestoreRuntimeState({ runtime_state: 'invented' })).toBe(
            'unchanged',
        );
    });

    it('uses French labels for every normalized component', () => {
        expect(offlineRestoreComponentLabel('database')).toBe(
            'Base de données',
        );
        expect(offlineRestoreComponentLabel('private_storage')).toBe(
            'Documents privés',
        );
        expect(offlineRestoreComponentLabel('public_storage')).toBe(
            'Fichiers publics et logo',
        );
    });
});
