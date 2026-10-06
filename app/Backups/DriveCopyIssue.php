<?php

namespace App\Backups;

/**
 * Why a backup did not reach Google Drive, in words the doctor can act on.
 * The codes come from ScheduledDriveBackupUploader (skips) and the upload job
 * (failures); unknown codes get a generic sentence.
 */
final class DriveCopyIssue
{
    public static function describe(?string $reason): string
    {
        return match ($reason) {
            'drive_not_connected' => 'Google Drive n’est pas connecté (ou la connexion a expiré) : reconnectez le compte.',
            'drive_reconnect_required' => 'Google a refusé l’accès au compte (autorisation expirée ou révoquée) : reconnectez le compte Google Drive.',
            'google_oauth_unconfigured' => 'cette version de Drclick ne contient pas la configuration Google.',
            'encryption_unavailable' => 'le chiffrement requis n’est pas disponible sur ce PC.',
            'ambiguous_drive_connection' => 'plusieurs comptes Google Drive sont connectés sur ce poste.',
            'drive_cabinet_mismatch' => 'ce poste contient les données de plusieurs cabinets.',
            'drive_backup_unlicensed' => 'la licence active n’inclut pas la sauvegarde Google Drive.',
            'automatic_upload_disabled' => 'l’envoi automatique est désactivé.',
            'transfer_failed', 'retry_exhausted' => 'l’envoi a échoué (connexion Internet lente ou coupée, ou Google injoignable). Il est retenté automatiquement; vérifiez la connexion si l’échec persiste.',
            'permanent_precondition_failed' => 'l’archive chiffrée n’a pas pu être vérifiée avant l’envoi, ou le compte Google Drive a été déconnecté.',
            'queue_dispatch_failed' => 'la copie n’a pas pu être mise en file d’envoi.',
            'record_unavailable' => 'l’archive à envoyer n’est plus disponible sur ce PC.',
            default => 'la copie n’a pas pu être préparée.',
        };
    }
}
