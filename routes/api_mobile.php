<?php

use App\Http\Controllers\Api\V1\Mobile\AuthController;
use App\Http\Controllers\Api\V1\Mobile\AvailabilityController;
use App\Http\Controllers\Api\V1\Mobile\ClinicProfileController;
use App\Http\Controllers\Api\V1\Mobile\DeviceController;
use App\Http\Controllers\Api\V1\Mobile\DoctorDirectoryController;
use App\Http\Controllers\Api\V1\Mobile\FamilyMemberController;
use App\Http\Controllers\Api\V1\Mobile\NotificationController;
use App\Http\Controllers\Api\V1\Mobile\PatientAppointmentController;
use App\Http\Controllers\Api\V1\Mobile\PatientPrescriptionController;
use App\Http\Controllers\Api\V1\Mobile\ProfileController;
use App\Http\Controllers\Api\V1\Mobile\ReferenceController;
use App\Http\Controllers\Api\V1\Mobile\StaffAppointmentController;
use App\Http\Controllers\Api\V1\Mobile\StaffPatientController;
use App\Http\Controllers\Api\V1\Mobile\StaffScheduleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mobile API (required inside the /api/v1 group of routes/api.php)
|--------------------------------------------------------------------------
|
| Patient-facing and staff-mobile endpoints. Patient accounts hold the
| Patient role with cabinet_id = null, so patient controllers always carry
| explicit ownership constraints — the BelongsToCabinet scope is inert for
| them. Owned by the FOUNDATION module: module agents implement the
| controllers but never edit this file.
|
*/

// --- Public reference + discovery --------------------------------------
Route::middleware('throttle:mobile-public')->group(function (): void {
    Route::get('wilayas', [ReferenceController::class, 'wilayas']);
    Route::get('wilayas/{wilaya}/baladiyas', [ReferenceController::class, 'baladiyas'])
        ->whereNumber('wilaya');
    Route::get('specialties', [ReferenceController::class, 'specialties']);

    Route::get('doctors', [DoctorDirectoryController::class, 'index']);
    Route::get('clinics/{cabinet}', [DoctorDirectoryController::class, 'show'])
        ->whereNumber('cabinet');
    Route::get('doctors/{doctorProfile}/availability/month', [AvailabilityController::class, 'month'])
        ->whereNumber('doctorProfile');
    Route::get('doctors/{doctorProfile}/availability/day', [AvailabilityController::class, 'day'])
        ->whereNumber('doctorProfile');
});

// --- Public auth ---------------------------------------------------------
Route::post('auth/register', [AuthController::class, 'register'])
    ->middleware('throttle:mobile-register');
Route::post('auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:mobile-login');

// --- Authenticated, any mobile role --------------------------------------
Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('devices', [DeviceController::class, 'store']);
    Route::delete('devices', [DeviceController::class, 'destroy']);
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::post('notifications/read', [NotificationController::class, 'markRead']);

    // --- Patient-only -----------------------------------------------------
    Route::middleware('mobile.patient')->group(function (): void {
        Route::get('my/profile', [ProfileController::class, 'show']);
        Route::patch('my/profile', [ProfileController::class, 'update']);

        Route::get('my/appointments', [PatientAppointmentController::class, 'index']);
        Route::post('my/appointments', [PatientAppointmentController::class, 'store']);
        Route::get('my/appointments/{publicId}', [PatientAppointmentController::class, 'show']);
        Route::patch('my/appointments/{publicId}/cancel', [PatientAppointmentController::class, 'cancel']);

        Route::get('my/prescriptions', [PatientPrescriptionController::class, 'index']);

        Route::get('family-members', [FamilyMemberController::class, 'index']);
        Route::post('family-members', [FamilyMemberController::class, 'store']);
        Route::post('family-members/link', [FamilyMemberController::class, 'link']);
        Route::post('family-members/{familyMember}/respond', [FamilyMemberController::class, 'respond']);
        Route::delete('family-members/{familyMember}', [FamilyMemberController::class, 'destroy']);
    });

    // --- Staff-only (patients rejected by cabinet.active.api; platform
    // admins and legacy null-cabinet accounts by mobile.staff.cabinet) ------
    Route::middleware(['cabinet.active.api', 'mobile.staff.cabinet'])->group(function (): void {
        Route::get('mobile/appointments/today', [StaffAppointmentController::class, 'today']);
        Route::patch('mobile/appointments/{appointment}/decline', [StaffAppointmentController::class, 'decline']);
        Route::patch('mobile/appointments/{appointment}/reschedule', [StaffAppointmentController::class, 'reschedule']);
        Route::patch('mobile/appointments/{appointment}/no-show', [StaffAppointmentController::class, 'noShow']);

        Route::post('mobile/patients', [StaffPatientController::class, 'store'])
            ->middleware('permission:patients.create');

        Route::put('mobile/schedule', [StaffScheduleController::class, 'update'])
            ->middleware('permission:appointments.configure');
        Route::post('mobile/schedule/time-off', [StaffScheduleController::class, 'storeTimeOff'])
            ->middleware('permission:appointments.configure');
        Route::delete('mobile/schedule/time-off/{timeOff}', [StaffScheduleController::class, 'destroyTimeOff'])
            ->middleware('permission:appointments.configure');

        Route::get('mobile/clinic-profile', [ClinicProfileController::class, 'show']);
        Route::put('mobile/clinic-profile', [ClinicProfileController::class, 'update'])
            ->middleware('permission:configuration.branding.manage');
    });
});
