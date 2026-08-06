<?php

namespace App\Services;

final readonly class NativeTunnelStatusEvidence
{
    public function __construct(
        public string $runtimeInstanceId,
        public string $installationId,
        public string $applicationVersion,
        public ?string $configuredHostname,
        public string $phase,
        public ?string $listenerOrigin,
        public ?string $cloudflaredVersion,
        public bool $executableVerified,
        public int $retryCount,
        public ?string $lastErrorCode,
        public int $updatedAtUnixMs,
        public int $sequence,
        public string $signature,
    ) {}
}
