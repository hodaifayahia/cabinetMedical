<?php

namespace App\Support;

use App\Models\Cabinet;

/**
 * A cabinet that was part of a batch but could not legally receive a code.
 */
final readonly class SkippedLicenseCode
{
    public function __construct(
        public Cabinet $cabinet,
        public string $reason,
    ) {}
}
