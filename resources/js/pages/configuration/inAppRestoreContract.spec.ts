import { invoke, isTauri } from '@tauri-apps/api/core';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    backupFolderPickerAvailable,
    pickBackupFolder,
} from '@/lib/backupFolderPicker';
import { HttpError } from '@/lib/http';
import {
    backupKindLabel,
    inAppRestoreErrorMessage,
    normalizeInAppRestorePreparation,
} from '@/pages/configuration/inAppRestoreContract';

vi.mock('@tauri-apps/api/core', () => ({
    invoke: vi.fn(),
    isTauri: vi.fn(),
}));

const preparation = {
    operation_id: '0c9e8d7f-6a5b-4c3d-9e2f-1a0b9c8d7e6f',
    source: 'sauvegarde.msbackup',
    confirmation: 'RESTAURER',
    summary: {
        cabinet: 'Cabinet Lumière',
        patients: 12,
        users: 2,
        consultations: 40,
        created_at: '2026-10-01T10:00:00+01:00',
        application_version: '2.1.0',
        file_count: 7,
        encrypted: true,
    },
};

describe('in-app restore contract', () => {
    it('accepts the verified summary of a prepared backup', () => {
        expect(normalizeInAppRestorePreparation(preparation)).toEqual(
            preparation,
        );
    });

    it('rejects a malformed or unexpected answer', () => {
        expect(normalizeInAppRestorePreparation(null)).toBeNull();
        expect(
            normalizeInAppRestorePreparation({
                ...preparation,
                operation_id: '../etc',
            }),
        ).toBeNull();
        expect(
            normalizeInAppRestorePreparation({
                ...preparation,
                confirmation: 'OUI',
            }),
        ).toBeNull();
        expect(
            normalizeInAppRestorePreparation({
                ...preparation,
                summary: { ...preparation.summary, patients: -1 },
            }),
        ).toBeNull();
    });

    it('drops unsafe text instead of rendering it', () => {
        const normalized = normalizeInAppRestorePreparation({
            ...preparation,
            summary: { ...preparation.summary, cabinet: 'A\u0000B' },
        });

        expect(normalized?.summary.cabinet).toBeNull();
    });

    it('explains failures in French', () => {
        expect(
            inAppRestoreErrorMessage({
                validation: true,
                errors: { passphrase: ['Phrase secrète incorrecte.'] },
            }),
        ).toBe('Phrase secrète incorrecte.');
        expect(inAppRestoreErrorMessage(new HttpError(423, 'x'))).toContain(
            'Confirmez d’abord votre mot de passe',
        );
        expect(inAppRestoreErrorMessage(new Error('boom'), 'Repli.')).toBe(
            'Repli.',
        );
        expect(backupKindLabel('scheduled')).toBe('Automatique');
        expect(backupKindLabel('safety')).toBe('Avant restauration');
    });
});

describe('native backup folder picker', () => {
    beforeEach(() => {
        vi.mocked(invoke).mockReset();
        vi.mocked(isTauri).mockReset();
    });

    it('is unavailable in a browser', async () => {
        vi.mocked(isTauri).mockReturnValue(false);

        expect(backupFolderPickerAvailable()).toBe(false);
        await expect(pickBackupFolder()).resolves.toEqual({
            status: 'unavailable',
        });
        expect(invoke).not.toHaveBeenCalled();
    });

    it('returns the folder chosen in the desktop dialog', async () => {
        vi.mocked(isTauri).mockReturnValue(true);
        vi.mocked(invoke).mockResolvedValue('E:\\Sauvegardes Drclick');

        await expect(pickBackupFolder()).resolves.toEqual({
            status: 'picked',
            path: 'E:\\Sauvegardes Drclick',
        });
        expect(invoke).toHaveBeenCalledWith('pick_backup_folder');
    });

    it('treats a cancelled dialog or an older shell gracefully', async () => {
        vi.mocked(isTauri).mockReturnValue(true);
        vi.mocked(invoke).mockResolvedValueOnce(null);

        await expect(pickBackupFolder()).resolves.toEqual({
            status: 'cancelled',
        });

        vi.mocked(invoke).mockRejectedValueOnce(
            new Error('command pick_backup_folder not found'),
        );

        await expect(pickBackupFolder()).resolves.toEqual({
            status: 'unavailable',
        });
    });
});
