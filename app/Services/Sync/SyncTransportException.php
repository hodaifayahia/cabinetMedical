<?php

namespace App\Services\Sync;

use RuntimeException;

/**
 * The remote could not be reached, or refused the exchange.
 *
 * `offline` separates "there is no internet right now" — the normal condition
 * for a local-first installation, and not a fault — from a real failure such as
 * an expired token. The UI phrases the two differently.
 */
final class SyncTransportException extends RuntimeException
{
    /** The online account belongs to a cabinet this installation does not hold. */
    public const REASON_CABINET_MISMATCH = 'cabinet_mismatch';

    public function __construct(
        string $message,
        public readonly bool $offline = false,
        public readonly ?string $reason = null,
    ) {
        parent::__construct($message);
    }
}
