import { describe, expect, it } from 'vitest';
import {
    normalizeNativeUpdateCheck,
    normalizeNativeUpdateInstallResponse,
    normalizeNativeUpdaterStatus,
    normalizeUpdateInstallPreparation,
    signedUpdaterErrorMessage,
} from './updateContract';

const metadata = {
    version: '1.2.3',
    current_version: '1.0.0',
    published_at: '2026-08-05T10:00:00Z',
};

const installPreparation = () => ({
    authorization: {
        protocol: 'medismart-update-install-authorization',
        version: 1,
        target_version: '1.2.3',
        backup_record_id: '57dca9dd-6c10-49c8-ae81-3d773bf36582',
        backup_sha256: '42'.repeat(32),
        installation_id: 'e169a732-1f4e-46ed-b5b8-a0bc752f6f09',
        issued_at: 1_700_000_000,
        expires_at: 1_700_000_300,
        nonce: 'ad7b2dc9-9c8b-4c82-acf3-f76aa915ee09',
        signature: 'ab'.repeat(32),
    },
    backup: {
        id: '57dca9dd-6c10-49c8-ae81-3d773bf36582',
        filename: 'MediSmart-Backup-2026-08-05-100000-abc123.msbackup',
        sha256_hint: '424242424242…',
        completed_at: '2026-08-05T10:00:00+01:00',
    },
});

const fallbackMessage = 'Le programme de mise à jour signé n’a pas répondu.';
const checkFailedMessage =
    'La recherche de mise à jour a échoué. Vérifiez la connexion puis réessayez.';

describe('signed updater native contracts', () => {
    it('accepts the exact status and check payloads', () => {
        expect(
            normalizeNativeUpdaterStatus({
                configured: true,
                current_version: '1.0.0',
                pending_update: metadata,
                last_checked_at: 1_700_000_000,
                checking: false,
                installing: false,
            }),
        ).not.toBeNull();
        expect(
            normalizeNativeUpdateCheck({
                update: null,
                checked_at: 1_700_000_000,
            }),
        ).not.toBeNull();
    });

    it('accepts only the exact native install-success contract', () => {
        const result = {
            accepted: true,
            target_version: '1.2.3',
            message_fr:
                'La mise à jour vérifiée est installée; MediSmart va redémarrer.',
        };

        expect(normalizeNativeUpdateInstallResponse(result)).not.toBeNull();
        expect(
            normalizeNativeUpdateInstallResponse({
                ...result,
                message_fr: 'Installé depuis C:\\private\\artifact.zip',
            }),
        ).toBeNull();
        expect(
            normalizeNativeUpdateInstallResponse({
                ...result,
                debug_path: 'C:\\private\\artifact.zip',
            }),
        ).toBeNull();
    });

    it('rejects malformed native status instead of enabling the UI', () => {
        expect(
            normalizeNativeUpdaterStatus({
                configured: 'yes',
                current_version: '../1.0.0',
                pending_update: null,
                last_checked_at: -1,
                checking: false,
                installing: false,
            }),
        ).toBeNull();
        expect(
            normalizeNativeUpdaterStatus({
                configured: true,
                current_version: '1.0.0',
                pending_update: {
                    ...metadata,
                    published_at: 'x'.repeat(65),
                },
                last_checked_at: 1_700_000_000,
                checking: false,
                installing: false,
            }),
        ).toBeNull();
        expect(
            normalizeNativeUpdateCheck({
                update: null,
                checked_at: 1_700_000_000,
                debug: 'internal updater state',
            }),
        ).toBeNull();
    });

    it('binds an install preparation to its verified backup record', () => {
        const preparation = installPreparation();

        expect(normalizeUpdateInstallPreparation(preparation)).not.toBeNull();
        preparation.backup.id = 'e169a732-1f4e-46ed-b5b8-a0bc752f6f09';
        expect(normalizeUpdateInstallPreparation(preparation)).toBeNull();
    });

    it('rejects unsafe or inconsistent backup display metadata', () => {
        const pathTraversal = installPreparation();
        pathTraversal.backup.filename = '../MediSmart-Backup.msbackup';
        expect(normalizeUpdateInstallPreparation(pathTraversal)).toBeNull();

        const oversizedFilename = installPreparation();
        oversizedFilename.backup.filename = `MediSmart-Backup-${'a'.repeat(240)}.msbackup`;
        expect(normalizeUpdateInstallPreparation(oversizedFilename)).toBeNull();

        const unrelatedHashHint = installPreparation();
        unrelatedHashHint.backup.sha256_hint = '434343434343…';
        expect(normalizeUpdateInstallPreparation(unrelatedHashHint)).toBeNull();

        const oversizedHashHint = installPreparation();
        oversizedHashHint.backup.sha256_hint = '42'.repeat(7) + '…';
        expect(normalizeUpdateInstallPreparation(oversizedHashHint)).toBeNull();

        const impossibleTimestamp = installPreparation();
        impossibleTimestamp.backup.completed_at = '2026-02-30T10:00:00Z';
        expect(
            normalizeUpdateInstallPreparation(impossibleTimestamp),
        ).toBeNull();

        const nonRfc3339Timestamp = installPreparation();
        nonRfc3339Timestamp.backup.completed_at = '2026-08-05 10:00:00';
        expect(
            normalizeUpdateInstallPreparation(nonRfc3339Timestamp),
        ).toBeNull();
    });

    it('rejects unknown preparation fields and overlong authorizations', () => {
        const preparation = installPreparation();

        expect(
            normalizeUpdateInstallPreparation({
                ...preparation,
                backup: {
                    ...preparation.backup,
                    debug_path: '/private/backups/current.msbackup',
                },
            }),
        ).toBeNull();

        preparation.authorization.expires_at =
            preparation.authorization.issued_at + 601;
        expect(normalizeUpdateInstallPreparation(preparation)).toBeNull();
    });

    it('uses only an exact canonical French native error', () => {
        expect(
            signedUpdaterErrorMessage({
                code: 'signed_update_check_failed',
                message_fr: checkFailedMessage,
            }),
        ).toBe(checkFailedMessage);
    });

    it('does not leak malformed, oversized, or internal error details', () => {
        const rejectedErrors = [
            { message_fr: 'Échec vérifié.' },
            {
                code: 'signed_update_check_failed',
                message_fr: 'Échec vérifié.',
            },
            {
                code: 'signed_update_check_failed',
                message_fr: checkFailedMessage,
                debug: 'C:\\private\\updater-key.pem',
            },
            {
                code: 'signed_update_check_failed',
                message_fr: checkFailedMessage + '\nC:\\private\\artifact.zip',
            },
            {
                code: 'signed_update_check_failed',
                message_fr: 'é'.repeat(161),
            },
            {
                code: 'unknown_native_error',
                message_fr: checkFailedMessage,
            },
            new Error('C:\\private\\updater-key.pem'),
            'C:\\private\\updater-key.pem',
        ];

        for (const error of rejectedErrors) {
            expect(signedUpdaterErrorMessage(error)).toBe(fallbackMessage);
        }

        expect(
            signedUpdaterErrorMessage({ endpoint: 'https://secret.invalid' }),
        ).toBe(fallbackMessage);
        expect(signedUpdaterErrorMessage(null)).toBe(fallbackMessage);
    });
});
