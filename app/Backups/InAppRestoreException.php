<?php

namespace App\Backups;

use RuntimeException;
use Throwable;

/** Why a backup could not be restored from the settings page, as a stable code. */
final class InAppRestoreException extends RuntimeException
{
    public const UNAVAILABLE = 'unavailable';

    public const BUSY = 'busy';

    public const PASSPHRASE_REQUIRED = 'passphrase_required';

    public const DECRYPTION_FAILED = 'decryption_failed';

    public const INVALID_ARCHIVE = 'invalid_archive';

    public const NEWER_VERSION = 'newer_version';

    public const EXPIRED = 'expired';

    public const SAFETY_BACKUP_FAILED = 'safety_backup_failed';

    public const APPLY_FAILED = 'apply_failed';

    public function __construct(public readonly string $reason, ?Throwable $previous = null)
    {
        parent::__construct('The backup could not be restored: '.$reason.'.', previous: $previous);
    }

    /** The field the error belongs to on the restore form. */
    public function field(): string
    {
        return in_array($this->reason, [self::PASSPHRASE_REQUIRED, self::DECRYPTION_FAILED], true)
            ? 'passphrase'
            : 'backup';
    }

    /** What the doctor reads on the settings page. */
    public function userMessage(): string
    {
        return match ($this->reason) {
            self::UNAVAILABLE => 'La restauration n’est possible que dans l’application Drclick installée sur ce PC.',
            self::BUSY => 'Une sauvegarde ou une restauration est déjà en cours sur ce PC. Réessayez dans quelques minutes.',
            self::PASSPHRASE_REQUIRED => 'Cette sauvegarde est chiffrée : saisissez sa phrase secrète.',
            self::DECRYPTION_FAILED => 'Phrase secrète incorrecte, ou fichier incomplet ou endommagé.',
            self::INVALID_ARCHIVE => 'Ce fichier n’est pas une sauvegarde Drclick valide, ou il est endommagé.',
            self::NEWER_VERSION => 'Cette sauvegarde vient d’une version plus récente de Drclick. Mettez d’abord Drclick à jour sur ce PC.',
            self::EXPIRED => 'La vérification de cette sauvegarde a expiré. Sélectionnez-la de nouveau.',
            self::SAFETY_BACKUP_FAILED => 'La sauvegarde de sécurité des données actuelles n’a pas pu être créée : rien n’a été modifié.',
            default => 'La sauvegarde n’a pas pu être restaurée. Les données actuelles ont été conservées; réessayez ou contactez le support Drclick.',
        };
    }
}
