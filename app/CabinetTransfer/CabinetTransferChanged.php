<?php

namespace App\CabinetTransfer;

use RuntimeException;

final class CabinetTransferChanged extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Les dossiers en ligne ont changé depuis la copie sur le PC. Relancez le transfert.');
    }
}
