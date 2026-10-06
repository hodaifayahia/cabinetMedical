<?php

namespace App\Support;

/**
 * Where patient records may be created and read.
 *
 * They belong on the cabinet's own machine: the desktop app (a PC, or the
 * poste principal other PCs attach to) or an on-premise Hub. The online
 * service only keeps accounts, licences, AI credits and the mobile app,
 * unless MEDISMART_HOSTED_CLINICAL reopens its clinical screens.
 */
final class ClinicalWorkstation
{
    public static function isOnlineService(): bool
    {
        return ! (bool) config('medismart.runtime.desktop_supervised', false)
            && ! (bool) config('hub.enabled', false);
    }

    public static function clinicalScreensOpen(): bool
    {
        return ! self::isOnlineService()
            || (bool) config('medismart.hosted.clinical_enabled', false);
    }
}
