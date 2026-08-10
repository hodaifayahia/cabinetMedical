<?php

use App\Http\Controllers\Api\V1\AppointmentController;
use App\Http\Controllers\Api\V1\AppointmentSyncController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CabinetController;
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

            Route::get('schedule', [ScheduleController::class, 'index']);

            Route::get('patients', [PatientController::class, 'index']);
            Route::get('patients/{patient}', [PatientController::class, 'show']);
        });
    });
});
