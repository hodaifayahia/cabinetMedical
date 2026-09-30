import { describe, expect, it } from 'vitest';
import {
    BACKUP_SETTINGS_URL,
    DRIVE_BACKUP_SETTINGS_URL,
    backupReminderCopy,
    normalizeBackupReminder,
} from './backupReminder';

describe('backup reminder', () => {
    it('accepts only the known server states', () => {
        for (const state of [
            'local_missing',
            'local_overdue',
            'drive_failing',
        ] as const) {
            expect(normalizeBackupReminder({ state })).toEqual({ state });
        }

        expect(normalizeBackupReminder(null)).toBeNull();
        expect(normalizeBackupReminder(undefined)).toBeNull();
        expect(normalizeBackupReminder('local_missing')).toBeNull();
        // The Drive-required states of the earlier build are gone.
        expect(normalizeBackupReminder({ state: 'disconnected' })).toBeNull();
        expect(normalizeBackupReminder({ state: 3 })).toBeNull();
    });

    it('sends a missing or late local backup to the save-now button', () => {
        expect(backupReminderCopy('local_missing').action).toEqual({
            label: 'Sauvegarder maintenant',
            href: BACKUP_SETTINGS_URL,
        });
        expect(backupReminderCopy('local_overdue').action).toEqual({
            label: 'Sauvegarder maintenant',
            href: BACKUP_SETTINGS_URL,
        });
        expect(backupReminderCopy('local_overdue').message).toContain(
            '24 heures',
        );
        expect(BACKUP_SETTINGS_URL).toBe(
            '/app/configuration/connectivity-backup#backup-now',
        );
    });

    it('only mentions Drive when its optional copy is failing', () => {
        const failing = backupReminderCopy('drive_failing');

        expect(failing.action).toEqual({
            label: 'Vérifier Google Drive',
            href: DRIVE_BACKUP_SETTINGS_URL,
        });
        // The local copy is still there: the doctor must not think all is lost.
        expect(failing.message).toContain('restent enregistrées sur ce PC');
        expect(DRIVE_BACKUP_SETTINGS_URL).toBe(
            '/app/configuration/connectivity-backup#google-drive',
        );
    });
});
