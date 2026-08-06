<?php

namespace App\Services;

use RuntimeException;

final class NativeTunnelStatusException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
