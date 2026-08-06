import { describe, expect, it } from 'vitest';
import {
    boundedDriveUploadAttempts,
    boundedDriveUploadBytes,
    boundedDriveUploadProgress,
    driveUploadStatusLabel,
} from './driveUploadPresentation';

describe('Drive upload presentation', () => {
    it('uses explicit French labels for every bounded state', () => {
        expect(driveUploadStatusLabel('queued')).toBe('En attente du worker');
        expect(driveUploadStatusLabel('uploading')).toBe('Envoi en cours');
        expect(driveUploadStatusLabel('retrying')).toBe(
            'Nouvelle tentative planifiée',
        );
        expect(driveUploadStatusLabel('cancel_requested')).toBe(
            'Annulation demandée',
        );
        expect(driveUploadStatusLabel('cancelled')).toBe('Envoi annulé');
        expect(driveUploadStatusLabel('completed')).toBe('Envoi confirmé');
        expect(driveUploadStatusLabel('failed')).toBe('Envoi en échec');
        expect(driveUploadStatusLabel(null)).toBe('Aucun envoi demandé');
    });

    it('clamps numeric values before rendering them', () => {
        expect(boundedDriveUploadProgress(-8)).toBe(0);
        expect(boundedDriveUploadProgress(120)).toBe(100);
        expect(boundedDriveUploadProgress(Number.NaN)).toBeNull();
        expect(boundedDriveUploadAttempts(-2)).toBe(0);
        expect(boundedDriveUploadAttempts(91)).toBe(3);
        expect(boundedDriveUploadAttempts(Number.POSITIVE_INFINITY)).toBe(0);
        expect(boundedDriveUploadBytes(-2, 100)).toBe(0);
        expect(boundedDriveUploadBytes(900, 100)).toBe(100);
        expect(boundedDriveUploadBytes(50, null)).toBeNull();
    });
});
