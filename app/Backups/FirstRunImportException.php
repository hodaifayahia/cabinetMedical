<?php

namespace App\Backups;

use RuntimeException;
use Throwable;

/** Why a new desktop could not start from the chosen backup, as a stable code. */
final class FirstRunImportException extends RuntimeException
{
    public const UNAVAILABLE = 'unavailable';

    public const BUSY = 'busy';

    public const PASSPHRASE_REQUIRED = 'passphrase_required';

    public const DECRYPTION_FAILED = 'decryption_failed';

    public const INVALID_ARCHIVE = 'invalid_archive';

    public const NEWER_VERSION = 'newer_version';

    public const APPLY_FAILED = 'apply_failed';

    public function __construct(public readonly string $reason, ?Throwable $previous = null)
    {
        parent::__construct('The backup could not be imported: '.$reason.'.', previous: $previous);
    }

    /** What the doctor reads on the restore page. */
    public function userMessage(): string
    {
        return match ($this->reason) {
            self::UNAVAILABLE => 'Ce PC contient déjà un cabinet : une sauvegarde ne peut être importée qu’avant la création du premier compte.',
            self::BUSY => 'Une sauvegarde ou une restauration est déjà en cours sur ce PC. Réessayez dans quelques minutes.',
            self::PASSPHRASE_REQUIRED => 'Cette sauvegarde est chiffrée : saisissez sa phrase secrète.',
            self::DECRYPTION_FAILED => 'Phrase secrète incorrecte, ou fichier incomplet ou endommagé.',
            self::INVALID_ARCHIVE => 'Ce fichier n’est pas une sauvegarde Drclick valide, ou il est endommagé.',
            self::NEWER_VERSION => 'Cette sauvegarde vient d’une version plus récente de Drclick. Mettez d’abord Drclick à jour sur ce PC.',
            default => 'La sauvegarde n’a pas pu être restaurée. Ce PC est resté vide; réessayez ou contactez le support Drclick.',
        };
    }
}
