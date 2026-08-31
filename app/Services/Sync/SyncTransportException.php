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
    public function __construct(
        string $message,
        public readonly bool $offline = false,
    ) {
        parent::__construct($message);
    }
}
