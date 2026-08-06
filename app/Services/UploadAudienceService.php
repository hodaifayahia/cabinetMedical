<?php

namespace App\Services;

use App\Models\UploadSession;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

final class UploadAudienceService
{
    public function __construct(
        private readonly NetworkService $network,
        private readonly TunnelService $tunnel,
        private readonly LicenseService $licenses,
        private readonly RemoteUploadBoundary $boundary,
        private readonly LanUploadBoundary $lanBoundary,
    ) {}

    public function assertAllowed(Request $request, UploadSession $session): void
    {
        $allowed = match ($session->mode) {
            'local' => $this->localAllowed($request),
            'remote' => $this->remoteAllowed($request),
            default => false,
        };

        if (! $allowed) {
            throw new NotFoundHttpException;
        }
    }

    private function localAllowed(Request $request): bool
    {
        try {
            $expected = $this->network->localUploadBaseUrl();

            if (! is_string($expected)) {
                return false;
            }

            $configuredLanOrigin = $this->lanBoundary->configuredOrigin();

            return $this->network->lanListenerActive()
                && ($configuredLanOrigin !== null
                    ? $this->lanBoundary->audienceMatches($request, $expected)
                    : ! (bool) config('medismart.runtime.desktop_supervised', false)
                        && $this->boundary->audienceMatches($request, 'local', $expected));
        } catch (Throwable) {
            return false;
        }
    }

    private function remoteAllowed(Request $request): bool
    {
        if (! $this->boundary->audienceMatches($request, 'remote')) {
            return false;
        }

        try {
            $tunnel = $this->tunnel->status();

            return $this->licenses->featureEnabled('remote_upload')
                && ($tunnel['configured'] ?? false) === true
                && ($tunnel['runtime_state'] ?? null) === 'active'
                && $this->boundary->tunnelMatchesConfiguredHost($tunnel);
        } catch (Throwable) {
            return false;
        }
    }
}
