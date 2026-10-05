<?php

use App\Http\Controllers\Api\V1\Mobile\Admin\AdminCabinetController;
use App\Http\Controllers\Api\V1\Mobile\Admin\AdminCabinetLifecycleController;
use App\Http\Controllers\Api\V1\Mobile\Admin\AdminCabinetListingController;
use App\Http\Controllers\Api\V1\Mobile\Admin\AdminCabinetStaffController;
use App\Http\Controllers\Api\V1\Mobile\Admin\AdminCoverageController;
use App\Http\Controllers\Api\V1\Mobile\Admin\AdminFacilityTypeController;
use App\Http\Controllers\Api\V1\Mobile\Admin\AdminOverviewController;
use App\Http\Controllers\Api\V1\Mobile\Admin\AdminSpecialtyController;
use App\Http\Controllers\Api\V1\Mobile\AuthController;
use App\Http\Controllers\Api\V1\Mobile\AvailabilityController;
use App\Http\Controllers\Api\V1\Mobile\ClinicPhotoController;
use App\Http\Controllers\Api\V1\Mobile\ClinicProfileController;
use App\Http\Controllers\Api\V1\Mobile\DeviceController;
use App\Http\Controllers\Api\V1\Mobile\DoctorDirectoryController;
use App\Http\Controllers\Api\V1\Mobile\FamilyMemberController;
use App\Http\Controllers\Api\V1\Mobile\NotificationController;
use App\Http\Controllers\Api\V1\Mobile\PasswordResetController;
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
    Route::get('facility-types', [ReferenceController::class, 'facilityTypes']);

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
Route::post('auth/password/forgot', [PasswordResetController::class, 'forgot'])
    ->middleware('throttle:mobile-password-forgot');
Route::post('auth/password/reset', [PasswordResetController::class, 'reset'])
    ->middleware('throttle:mobile-password-reset');

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
        Route::middleware('permission:configuration.branding.manage')->group(function (): void {
            Route::post('mobile/clinic-profile/photos', [ClinicPhotoController::class, 'store']);
            Route::delete('mobile/clinic-profile/photos/{index}', [ClinicPhotoController::class, 'destroy'])
                ->whereNumber('index');
            Route::post('mobile/clinic-profile/photos/{index}/cover', [ClinicPhotoController::class, 'cover'])
                ->whereNumber('index');
        });
    });
});

// --- Platform back office (superadmin) ------------------------------------
// A platform admin has cabinet_id = null, so cabinet.active.api would let them
// through with no tenant and mobile.staff.cabinet would reject them outright:
// neither gate fits, and the group carries its own. Superadmins exist only
// because `platform:provision-superadmin` created one — no route below can
// mint another.
Route::middleware(['auth:sanctum', 'mobile.admin', 'throttle:mobile-admin'])
    ->prefix('admin')
    ->group(function (): void {
        Route::get('overview', AdminOverviewController::class);

        Route::get('cabinets', [AdminCabinetController::class, 'index']);
        Route::post('cabinets', [AdminCabinetController::class, 'store']);
        Route::get('cabinets/{cabinet}', [AdminCabinetController::class, 'show'])
            ->whereNumber('cabinet');

        Route::post('cabinets/{cabinet}/activate', [AdminCabinetLifecycleController::class, 'activate'])
            ->whereNumber('cabinet');
        Route::post('cabinets/{cabinet}/suspend', [AdminCabinetLifecycleController::class, 'suspend'])
            ->whereNumber('cabinet');

        Route::post('cabinets/{cabinet}/staff', [AdminCabinetStaffController::class, 'store'])
            ->whereNumber('cabinet');

        // Coverage: which wilayas/baladiyas the patient app offers at all.
        Route::get('coverage/wilayas', [AdminCoverageController::class, 'wilayas']);
        Route::post('coverage/wilayas', [AdminCoverageController::class, 'storeWilaya']);
        Route::patch('coverage/wilayas/{wilaya}', [AdminCoverageController::class, 'updateWilaya'])
            ->whereNumber('wilaya');
        Route::get('coverage/wilayas/{wilaya}/baladiyas', [AdminCoverageController::class, 'baladiyas'])
            ->whereNumber('wilaya');
        Route::patch('coverage/baladiyas/{baladiya}', [AdminCoverageController::class, 'updateBaladiya'])
            ->whereNumber('baladiya');

        // Specialty catalogue: what the patient app's specialty filter offers.
        Route::get('specialties', [AdminSpecialtyController::class, 'index']);
        Route::post('specialties', [AdminSpecialtyController::class, 'store']);
        Route::patch('specialties/{specialty}', [AdminSpecialtyController::class, 'update'])
            ->whereNumber('specialty');

        // Facility types: which search tabs (doctor / clinic / imaging) exist.
        Route::get('facility-types', [AdminFacilityTypeController::class, 'index']);
        Route::patch('facility-types/{type}', [AdminFacilityTypeController::class, 'update'])
            ->whereAlpha('type');

        Route::patch('cabinets/{cabinet}/facility-type', [AdminCabinetListingController::class, 'updateFacilityType'])
            ->whereNumber('cabinet');

        Route::patch('cabinets/{cabinet}/listing', [AdminCabinetListingController::class, 'update'])
            ->whereNumber('cabinet');
    });
