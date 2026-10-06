<?php

use App\Http\Controllers\Api\V1\AiRelayController;
use App\Http\Controllers\Api\V1\AppointmentController;
use App\Http\Controllers\Api\V1\AppointmentSyncController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CabinetController;
use App\Http\Controllers\Api\V1\CabinetSeatController;
use App\Http\Controllers\Api\V1\DesktopActivationController;
use App\Http\Controllers\Api\V1\PatientController;
use App\Http\Controllers\Api\V1\ScheduleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 (Sanctum personal access tokens)
|--------------------------------------------------------------------------
|
| The desktop/companion clients authenticate with plain-text personal access
| tokens minted by POST /api/v1/auth/token. Cabinet-scoped resources sit behind
| both auth:sanctum and the cabinet.active.api gate, which mirrors the web
| EnsureCabinetIsActive rules but answers with 403 JSON instead of redirects.
|
*/

Route::prefix('v1')->group(function (): void {
    // --- Public auth + onboarding -----------------------------------------
    Route::post('auth/token', [AuthController::class, 'token'])
        ->middleware('throttle:login');

    Route::post('cabinets/register', [CabinetController::class, 'register'])
        ->middleware('throttle:registration');

    Route::post('cabinets/join', [CabinetController::class, 'join'])
        ->middleware('throttle:cabinet-join');

    // An installed desktop's one-time activation: answers with a signed,
    // installation-bound entitlement the desktop then trusts offline.
    Route::post('desktop/activate', DesktopActivationController::class)
        ->middleware('throttle:license-activation')
        ->name('api.desktop.activate');

    // --- Authenticated (token present) ------------------------------------
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);

        // --- Authenticated + active, approved cabinet member --------------
        Route::middleware('cabinet.active.api')->group(function (): void {
            Route::get('appointments', [AppointmentController::class, 'index']);
            Route::post('appointments', [AppointmentController::class, 'store']);
            Route::get('appointments/{appointment}', [AppointmentController::class, 'show']);
            Route::patch('appointments/{appointment}', [AppointmentController::class, 'update']);
            Route::delete('appointments/{appointment}', [AppointmentController::class, 'destroy']);

            Route::get('sync/appointments', [AppointmentSyncController::class, 'index']);
            Route::post('sync/appointments/ack', [AppointmentSyncController::class, 'acknowledge']);
            // Mirror of the pull stream: a local-first desktop delivers its own
            // appointment changes here.
            Route::post('sync/appointments/push', [AppointmentSyncController::class, 'push']);

            Route::get('schedule', [ScheduleController::class, 'index']);

            // A local desktop's copy of the seats granted in the admin panel.
            Route::get('cabinet/seats', [CabinetSeatController::class, 'show']);

            // A local desktop's AI requests: charged to the token owner's
            // cabinet wallet, answered with the provider key held here only.
            Route::get('ai/status', [AiRelayController::class, 'status']);
            Route::post('ai/complete', [AiRelayController::class, 'complete'])
                ->middleware('throttle:30,1');
            Route::post('ai/transcribe', [AiRelayController::class, 'transcribe'])
                ->middleware('throttle:60,1');

            Route::get('patients', [PatientController::class, 'index']);
            Route::get('patients/{patient}', [PatientController::class, 'show']);
        });
    });

    require __DIR__.'/api_mobile.php';
});
