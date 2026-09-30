import type { BackupReminder, BackupReminderState } from '@/types/desktop';

export const BACKUP_SETTINGS_URL =
    '/app/configuration/connectivity-backup#backup-now';

export const DRIVE_BACKUP_SETTINGS_URL =
    '/app/configuration/connectivity-backup#google-drive';

export type BackupReminderCopy = {
    title: string;
    message: string;
    action: { label: string; href: string };
};

const STATES: readonly BackupReminderState[] = [
    'local_missing',
    'local_overdue',
    'drive_failing',
];

/**
 * The shared prop is untrusted page data: anything but a known state means
 * "no reminder" rather than a broken banner.
 */
export function normalizeBackupReminder(value: unknown): BackupReminder | null {
    if (typeof value !== 'object' || value === null) {
        return null;
    }

    const state = (value as { state?: unknown }).state;

    return typeof state === 'string' &&
        (STATES as readonly string[]).includes(state)
        ? { state: state as BackupReminderState }
        : null;
}

export function backupReminderCopy(
    state: BackupReminderState,
): BackupReminderCopy {
    switch (state) {
        case 'local_missing':
            return {
                title: 'Aucune sauvegarde sur ce PC',
                message:
                    'Les données du cabinet ne sont encore sauvegardées nulle part. Créez une première sauvegarde maintenant.',
                action: {
                    label: 'Sauvegarder maintenant',
                    href: BACKUP_SETTINGS_URL,
                },
            };
        case 'local_overdue':
            return {
                title: 'Sauvegarde en retard',
                message:
                    'Aucune sauvegarde n’a été enregistrée sur ce PC depuis plus de 24 heures.',
                action: {
                    label: 'Sauvegarder maintenant',
                    href: BACKUP_SETTINGS_URL,
                },
            };
        case 'drive_failing':
            return {
                title: 'Copie Google Drive en échec',
                message:
                    'Les sauvegardes restent enregistrées sur ce PC, mais leurs copies n’arrivent plus sur Google Drive. Vérifiez le compte Google du cabinet.',
                action: {
                    label: 'Vérifier Google Drive',
                    href: DRIVE_BACKUP_SETTINGS_URL,
                },
            };
    }
}
