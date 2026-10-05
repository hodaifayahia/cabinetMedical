import { describe, expect, it } from 'vitest';
import type { BackupReminderState } from '@/types/desktop';
import {
    BACKUP_SETTINGS_URL,
    DRIVE_BACKUP_SETTINGS_URL,
    backupReminderCopy,
    normalizeBackupReminder,
} from './backupReminder';

const states: BackupReminderState[] = [
    'local_missing',
    'local_overdue',
    'drive_failing',
];

describe('backup reminder (extended)', () => {
    it('drops unknown fields from the shared prop', () => {
        expect(
            normalizeBackupReminder({
                state: 'local_overdue',
                last_backup_at: '2026-01-01',
            }),
        ).toEqual({ state: 'local_overdue' });
    });

    it.each([
        ['an array', ['local_missing']],
        ['a number', 7],
        ['a boolean', true],
        ['an object without state', {}],
        ['an uppercase state', { state: 'LOCAL_MISSING' }],
        ['a padded state', { state: ' local_missing ' }],
        ['a null state', { state: null }],
    ])('rejects %s', (_label, value) => {
        expect(normalizeBackupReminder(value)).toBeNull();
    });

    it.each(states)('gives %s a complete, non-empty copy', (state) => {
        const copy = backupReminderCopy(state);

        expect(copy.title.trim()).not.toBe('');
        expect(copy.message.trim()).not.toBe('');
        expect(copy.action.label.trim()).not.toBe('');
        expect(copy.action.href).toMatch(
            /^\/app\/configuration\/connectivity-backup#/,
        );
    });

    it('gives each state its own headline', () => {
        const titles = states.map((state) => backupReminderCopy(state).title);

        expect(new Set(titles).size).toBe(states.length);
    });

    it('only the Drive state routes to the Drive section', () => {
        expect(
            states.filter(
                (state) =>
                    backupReminderCopy(state).action.href ===
                    DRIVE_BACKUP_SETTINGS_URL,
            ),
        ).toEqual(['drive_failing']);
        expect(
            states.filter(
                (state) =>
                    backupReminderCopy(state).action.href ===
                    BACKUP_SETTINGS_URL,
            ),
        ).toEqual(['local_missing', 'local_overdue']);
    });

    it('does not mention Google in the local-only reminders', () => {
        for (const state of ['local_missing', 'local_overdue'] as const) {
            const copy = backupReminderCopy(state);

            expect(`${copy.title} ${copy.message}`).not.toMatch(/google/i);
        }
    });

    it('returns a fresh object for every call', () => {
        const first = backupReminderCopy('local_missing');
        first.action.href = '/tampered';

        expect(backupReminderCopy('local_missing').action.href).toBe(
            BACKUP_SETTINGS_URL,
        );
    });
});
