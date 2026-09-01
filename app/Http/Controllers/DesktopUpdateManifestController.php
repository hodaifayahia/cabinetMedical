<?php

namespace App\Http\Controllers;

use App\Services\DesktopReleaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * The endpoint every installed shell polls.
 *
 * This is deliberately public and unauthenticated. The updater runs before any
 * user session exists and sends no cookies, so there is nothing to authenticate
 * against. Nothing secret is exposed: a version number, release notes, and a
 * signature that is only useful to someone who already has the matching public
 * key compiled into their build.
 */
final class DesktopUpdateManifestController extends Controller
{
    public function __invoke(DesktopReleaseService $releases): JsonResponse|Response
    {
        $release = $releases->current();

        if ($release === null) {
            // tauri-plugin-updater reads 204 as "you are already current",
            // which is exactly right when nothing has been published yet.
            return response()->noContent();
        }

        return response()->json($releases->manifest($release));
    }
}
