<?php

namespace App\Licensing;

use RuntimeException;

/**
 * Why the online service refused to activate an installed desktop.
 *
 * The reason is a stable machine code the desktop maps to its own French
 * message; the message is the online service's French wording, shown when
 * the desktop does not know the reason.
 */
final class DesktopActivationRefused extends RuntimeException
{
    public const INVALID_CODE = 'invalid_code';

    public const CODE_ALREADY_USED = 'code_already_used';

    public const CABINET_SUSPENDED = 'cabinet_suspended';

    public const CABINET_UNAVAILABLE = 'cabinet_unavailable';

    public const INVALID_CREDENTIALS = 'invalid_credentials';

    public const NOT_OWNER = 'not_owner';

    public const ACCESS_DENIED = 'access_denied';

    public const SIGNING_UNAVAILABLE = 'activation_unavailable';

    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }

    public static function invalidCode(): self
    {
        return new self('Ce code de licence est invalide ou n’est plus disponible.', self::INVALID_CODE, 422);
    }

    public static function codeAlreadyUsed(): self
    {
        return new self(
            'Ce code de licence a déjà été utilisé sur un autre poste. Chaque code ne s’utilise qu’une fois : '
            .'demandez un nouveau code à Drclick, ou activez ce poste avec le compte en ligne du cabinet.',
            self::CODE_ALREADY_USED,
            409,
        );
    }

    public static function cabinetSuspended(): self
    {
        return new self('Ce cabinet est suspendu. Contactez l’administration Drclick.', self::CABINET_SUSPENDED, 403);
    }

    public static function cabinetUnavailable(): self
    {
        return new self(
            'La licence de ce cabinet n’est plus disponible sur le service en ligne. Contactez l’administration Drclick.',
            self::CABINET_UNAVAILABLE,
            409,
        );
    }

    public static function invalidCredentials(): self
    {
        return new self('Adresse e-mail ou mot de passe incorrect sur le service en ligne.', self::INVALID_CREDENTIALS, 422);
    }

    public static function notOwner(): self
    {
        return new self(
            'Seul le titulaire du cabinet peut activer un poste avec son compte en ligne.',
            self::NOT_OWNER,
            403,
        );
    }

    public static function accessDenied(string $message): self
    {
        return new self($message, self::ACCESS_DENIED, 403);
    }

    public static function signingUnavailable(): self
    {
        return new self(
            'L’activation en ligne des postes n’est pas encore disponible. Contactez Drclick pour recevoir un fichier de licence.',
            self::SIGNING_UNAVAILABLE,
            503,
        );
    }
}
