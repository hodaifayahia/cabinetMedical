export type DesktopDownload = {
    available: boolean;
    url: string | null;
    label: string;
    reason: string | null;
};

/**
 * What the doctor must hear about a supervised desktop's backups: the local
 * ones are required, the Google Drive copy optional. Shared only with someone
 * who can act on it; null for everyone else and everywhere but a supervised
 * desktop.
 */
export type BackupReminderState =
    'local_missing' | 'local_overdue' | 'drive_failing';

export type BackupReminder = {
    state: BackupReminderState;
};
