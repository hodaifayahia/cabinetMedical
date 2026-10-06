import { invoke, isTauri } from '@tauri-apps/api/core';

/**
 * Native "Parcourir…" for the backup copy folder. The desktop shell exposes
 * `pick_backup_folder`, which opens the Windows folder dialog and resolves
 * to the chosen absolute path, or `null` when the doctor cancels. In a
 * browser, or a shell built without the command, the doctor types the path.
 */
export const backupFolderPickerAvailable = (): boolean => {
    try {
        return isTauri();
    } catch {
        return false;
    }
};

export type BackupFolderPick =
    | { status: 'picked'; path: string }
    | { status: 'cancelled' }
    | { status: 'unavailable' };

export const pickBackupFolder = async (): Promise<BackupFolderPick> => {
    if (!backupFolderPickerAvailable()) {
        return { status: 'unavailable' };
    }

    try {
        const path = await invoke<string | null>('pick_backup_folder');

        if (typeof path !== 'string' || path.trim() === '') {
            return { status: 'cancelled' };
        }

        return path.length > 1024 || /[\u0000-\u001f\u007f]/.test(path)
            ? { status: 'unavailable' }
            : { status: 'picked', path };
    } catch {
        // An older shell without the command: typing the path still works.
        return { status: 'unavailable' };
    }
};
