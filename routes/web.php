<?php

use App\Enums\PermissionName;
use App\Http\Controllers\Appointments\AppointmentController;
use App\Http\Controllers\Appointments\AvailabilityController;
use App\Http\Controllers\Appointments\OpenMonthController;
use App\Http\Controllers\Appointments\ScheduleController;
use App\Http\Controllers\Appointments\TimeOffController;
use App\Http\Controllers\Auth\DesktopCabinetLoginController;
use App\Http\Controllers\Auth\DesktopPinEnrollmentController;
use App\Http\Controllers\Auth\DesktopPinLoginController;
use App\Http\Controllers\Auth\SessionLockController;
use App\Http\Controllers\Cabinet\CabinetStatusController;
use App\Http\Controllers\Cabinet\JoinCabinetController;
use App\Http\Controllers\Cabinet\RedeemHostedLicenseCodeController;
use App\Http\Controllers\Configuration\AccountingController;
use App\Http\Controllers\Configuration\BackupController;
use App\Http\Controllers\Configuration\ClinicIdentityController;
use App\Http\Controllers\Configuration\ConnectivityAndBackupController;
use App\Http\Controllers\Configuration\LicenseController;
use App\Http\Controllers\Configuration\MedicationController;
use App\Http\Controllers\Configuration\PrepareOfflineRestoreController;
use App\Http\Controllers\Configuration\PrepareUpdateInstallController;
use App\Http\Controllers\Configuration\ReferentialController;
use App\Http\Controllers\Configuration\RolePermissionController;
use App\Http\Controllers\Configuration\UploadSessionController;
use App\Http\Controllers\Consultations\ClinicalDocumentController;
use App\Http\Controllers\Consultations\ConsultationController;
use App\Http\Controllers\Consultations\ConsultationHistoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DesktopDownloadController;
use App\Http\Controllers\DesktopDownloadLeadController;
use App\Http\Controllers\Encounters\EncounterController;
use App\Http\Controllers\Patients\PatientController;
use App\Http\Controllers\Payments\PaymentController;
use App\Http\Controllers\PublicUploadController;
use App\Http\Controllers\Staff\PendingMemberController;
use App\Http\Controllers\Staff\StaffIndexController;
use App\Http\Middleware\EnsureGoogleOAuthLoopback;
use App\Http\Middleware\SecurePublicUploadHeaders;
use App\Models\LandingSection;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', static fn () => Inertia::render('Welcome', [
    'canRegister' => true,
    'landingSections' => LandingSection::query()
        ->published()
        ->orderBy('sort_order')
        ->orderBy('id')
        ->get([
            'locale',
            'slug',
            'section_type',
            'eyebrow',
            'title',
            'body',
            'cta_label',
            'cta_url',
            'image_url',
            'items',
        ])
        ->map(static fn (LandingSection $section): array => [
            'locale' => $section->locale,
            'slug' => $section->slug,
            'section_type' => $section->section_type,
            'eyebrow' => $section->eyebrow,
            'title' => $section->title,
            'body' => $section->body,
            'cta_label' => $section->cta_label,
            'cta_url' => $section->cta_url,
            'image_url' => $section->image_url,
            'items' => is_array($section->items) ? $section->items : [],
        ])
        ->values()
        ->all(),
]))->name('home');

Route::get('desktop/download', [DesktopDownloadLeadController::class, 'show'])
    ->name('desktop.download');
Route::post('desktop/download', [DesktopDownloadLeadController::class, 'store'])
    ->middleware('throttle:desktop-download-leads')
    ->name('desktop.download.store');
Route::get('desktop/download/file/{lead}', DesktopDownloadController::class)
    ->middleware(['signed', 'throttle:desktop-download-files'])
    ->name('desktop.download.file');

Route::middleware('guest')->group(function (): void {
    Route::get('desktop/cabinet-login', [DesktopCabinetLoginController::class, 'create'])
        ->name('desktop.cabinet-login');
    Route::post('desktop/cabinet-login', [DesktopCabinetLoginController::class, 'store'])
        ->middleware('throttle:desktop-cabinet-login')
        ->name('desktop.cabinet-login.store');
});

// Desktop PIN authentication is separate from Fortify's email/password flow.
Route::post('desktop/pin/login', DesktopPinLoginController::class)
    ->middleware('throttle:desktop-pin-login')
    ->name('desktop.pin.login');

Route::post('desktop/pin/enroll', DesktopPinEnrollmentController::class)
    ->middleware(['auth', 'verified', 'throttle:desktop-pin-enroll'])
    ->name('desktop.pin.enroll');

// Public "join an existing cabinet" flow (rate limited).
Route::middleware('throttle:cabinet-join')->group(function (): void {
    Route::get('join', [JoinCabinetController::class, 'create'])->name('cabinet.join');
    Route::post('join', [JoinCabinetController::class, 'store'])->name('cabinet.join.store');
});

// Cabinet lifecycle status screens for authenticated but gated users.
Route::middleware('auth')->group(function (): void {
    Route::get('cabinet/pending', [CabinetStatusController::class, 'pending'])
        ->name('cabinet.pending');
    Route::get('cabinet/awaiting-approval', [CabinetStatusController::class, 'awaitingApproval'])
        ->name('cabinet.awaiting-approval');
    Route::post('cabinet/license/redeem', RedeemHostedLicenseCodeController::class)
        ->middleware('throttle:license-activation')
        ->name('cabinet.license.redeem');
});

Route::middleware('auth')->prefix('session')->name('session-lock.')->group(function (): void {
    Route::get('locked', [SessionLockController::class, 'show'])->name('show');
    Route::post('lock', [SessionLockController::class, 'lock'])->name('lock');
    Route::post('lock/idle', [SessionLockController::class, 'lockIdle'])->name('lock-idle');
    Route::post('activity', [SessionLockController::class, 'activity'])->name('activity');
    Route::post('unlock', [SessionLockController::class, 'unlock'])
        ->middleware('throttle:session-unlock')
        ->name('unlock');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::redirect('app', '/dashboard')->name('app.home');

    Route::prefix('app')->name('app.')->group(function () {
        Route::resource('patients', PatientController::class)->except(['destroy']);
        Route::get('patients/{patient}/json', [PatientController::class, 'showJson'])->name('patients.json.show');
        Route::post('patients/json', [PatientController::class, 'storeJson'])->name('patients.json.store');
        Route::put('patients/{patient}/json', [PatientController::class, 'updateJson'])->name('patients.json.update');

        Route::prefix('patients/{patient}')->name('patients.')->group(function () {
            Route::resource('encounters', EncounterController::class)->except(['destroy']);
            Route::post('encounters/{encounter}/sign', [EncounterController::class, 'sign'])
                ->name('encounters.sign');
            Route::get('encounters/{encounter}/amend', [EncounterController::class, 'createAmendment'])
                ->name('encounters.create-amendment');
            Route::post('encounters/{encounter}/amend', [EncounterController::class, 'storeAmendment'])
                ->name('encounters.store-amendment');
        });

        Route::get('appointments', [AppointmentController::class, 'index'])->name('appointments.index');
        Route::get('appointments/print', [AppointmentController::class, 'printList'])
            ->name('appointments.print');
        Route::post('appointments', [AppointmentController::class, 'store'])->name('appointments.store');
        Route::middleware('permission:configuration.manage')->group(function () {
            Route::post('appointments/prestations', [AppointmentController::class, 'storePrestation'])
                ->name('appointments.prestations.store');
            Route::put('appointments/prestations/{source}/{id}', [AppointmentController::class, 'updatePrestation'])
                ->name('appointments.prestations.update');
        });
        Route::patch('appointments/{appointment}/confirm', [AppointmentController::class, 'confirm'])
            ->name('appointments.confirm');
        Route::patch('appointments/{appointment}/check-in', [AppointmentController::class, 'checkIn'])
            ->name('appointments.check-in');
        Route::patch('appointments/{appointment}/cancel', [AppointmentController::class, 'cancel'])
            ->name('appointments.cancel');
        Route::post('appointments/mobile-sync', [AppointmentController::class, 'syncMobileDay'])
            ->name('appointments.mobile-sync-day');
        Route::post('appointments/{appointment}/mobile-sync', [AppointmentController::class, 'syncMobile'])
            ->name('appointments.mobile-sync');
        Route::get('appointments/availability/month', [AvailabilityController::class, 'month'])
            ->name('appointments.availability.month');
        Route::get('appointments/availability/day', [AvailabilityController::class, 'day'])
            ->name('appointments.availability.day');

        Route::middleware('permission:consultations.view')->group(function () {
            Route::get('consultations', [ConsultationController::class, 'index'])->name('consultations.index');
            Route::get('patients/{patient}/consultation-history', [ConsultationHistoryController::class, 'index'])
                ->name('consultations.history');
            Route::get('consultation-history/{consultation}', [ConsultationHistoryController::class, 'show'])
                ->name('consultations.history.show');
            Route::get('consultations/{consultation}', [ConsultationController::class, 'show'])->name('consultations.show');
        });
        Route::post('consultations/{appointment}/start', [ConsultationController::class, 'start'])
            ->middleware('permission:consultations.create')->name('consultations.start');
        Route::put('consultations/{consultation}', [ConsultationController::class, 'update'])
            ->middleware('permission:consultations.update')->name('consultations.update');
        Route::put('consultations/{consultation}/patient', [ConsultationController::class, 'savePatient'])
            ->middleware('permission:consultations.update')->name('consultations.patient.update');
        Route::post('consultations/{consultation}/schedule-next', [ConsultationController::class, 'scheduleNext'])
            ->middleware('permission:consultations.update')->name('consultations.schedule-next');
        Route::post('consultations/{consultation}/measurements', [ConsultationController::class, 'storeMeasurement'])
            ->middleware('permission:consultations.update')->name('consultations.measurements.store');
        Route::delete('consultations/{consultation}/measurements/{measurement}', [ConsultationController::class, 'deleteMeasurement'])
            ->middleware('permission:consultations.update')->name('consultations.measurements.destroy');
        Route::post('consultations/{consultation}/prescriptions', [ConsultationController::class, 'storePrescription'])
            ->middleware('permission:prescriptions.create')->name('consultations.prescriptions.store');
        Route::post('consultations/{consultation}/prescriptions/{prescription}/word-document', [ConsultationController::class, 'createPrescriptionDocument'])
            ->middleware('permission:prescriptions.create')->name('consultations.prescriptions.word-document');
        Route::post('consultations/{consultation}/documents', [ConsultationController::class, 'storeDocument'])
            ->middleware('permission:consultations.update')->name('consultations.documents.store');
        Route::post('consultations/{consultation}/uploaded-documents', [ConsultationController::class, 'uploadFile'])
            ->middleware('permission:consultations.update')->name('consultations.uploaded-documents.store');
        Route::delete('consultations/{consultation}/uploaded-documents/{document}', [ConsultationController::class, 'destroyUploadedFile'])
            ->middleware('permission:consultations.update')->name('consultations.uploaded-documents.destroy');
        Route::post('consultations/{consultation}/word-documents', [ClinicalDocumentController::class, 'store'])
            ->middleware('permission:consultations.update')->name('consultations.word-documents.store');
        Route::post('consultations/{consultation}/word-documents/{document}/convert', [ClinicalDocumentController::class, 'convert'])
            ->middleware('permission:consultations.update')->name('consultations.word-documents.convert');

        Route::middleware('permission:appointments.configure')->group(function () {
            Route::get('appointments/configure', [ScheduleController::class, 'edit'])->name('appointments.configure');
            Route::put('appointments/schedule', [ScheduleController::class, 'update'])->name('appointments.schedule.update');
            Route::post('appointments/open-months', [OpenMonthController::class, 'store'])->name('appointments.open-months.store');
            Route::delete('appointments/open-months/{openMonth}', [OpenMonthController::class, 'destroy'])
                ->name('appointments.open-months.destroy');
            Route::post('appointments/time-off', [TimeOffController::class, 'store'])->name('appointments.time-off.store');
            Route::delete('appointments/time-off/{timeOff}', [TimeOffController::class, 'destroy'])
                ->name('appointments.time-off.destroy');
        });

        Route::middleware('permission:payments.view')->group(function () {
            Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
            Route::get('payments/print', [PaymentController::class, 'printReport'])->name('payments.print');
            Route::get('payments/{consultation}/print', [PaymentController::class, 'printReceipt'])
                ->name('payments.receipt');
        });
        Route::patch('payments/{consultation}', [PaymentController::class, 'update'])
            ->middleware('permission:payments.create')
            ->name('payments.update');
        Route::post('consultations/{consultation}/payments', [PaymentController::class, 'store'])
            ->middleware('permission:payments.create')
            ->name('consultations.payments.store');

        Route::prefix('configuration')->name('configuration.')->group(function () {
            Route::get('/', static function () {
                $user = request()->user();

                abort_unless($user instanceof User, 401);

                if ($user->can(PermissionName::CONFIGURATION_BRANDING_MANAGE->value)) {
                    return to_route('app.configuration.identity.edit');
                }

                if ($user->can(PermissionName::CONFIGURATION_MANAGE->value)) {
                    return to_route('app.configuration.medications.index');
                }

                if ($user->can(PermissionName::STAFF_MANAGE->value)) {
                    return to_route('app.configuration.roles-permissions.index');
                }

                return to_route('app.configuration.connectivity-backup.edit');
            })->middleware('permission:configuration.manage|configuration.branding.manage|configuration.connectivity.manage|configuration.backups.manage|configuration.restore.manage|configuration.drive.manage|configuration.licensing.manage|configuration.diagnostics.view|staff.manage')
                ->name('index');

            Route::get('roles-permissions', [RolePermissionController::class, 'index'])
                ->name('roles-permissions.index');
            Route::put('roles-permissions', [RolePermissionController::class, 'update'])
                ->name('roles-permissions.update');
            Route::put('roles-permissions/users/{user}', [RolePermissionController::class, 'assignRole'])
                ->whereNumber('user')
                ->name('roles-permissions.users.role.update');

            Route::middleware('permission:configuration.manage')->group(function (): void {
                Route::get('medications', [MedicationController::class, 'index'])->name('medications.index');
                Route::post('medications', [MedicationController::class, 'store'])->name('medications.store');
                Route::put('medications/{medication}', [MedicationController::class, 'update'])->name('medications.update');
                Route::delete('medications/{medication}', [MedicationController::class, 'destroy'])->name('medications.destroy');
                Route::get('accounting', [AccountingController::class, 'edit'])->name('accounting.edit');
                Route::put('accounting', [AccountingController::class, 'update'])->name('accounting.update');
                Route::get('ref/{referential}', [ReferentialController::class, 'index'])->name('referentials.index');
                Route::post('ref/{referential}', [ReferentialController::class, 'store'])->name('referentials.store');
                Route::put('ref/{referential}/{id}', [ReferentialController::class, 'update'])->name('referentials.update');
                Route::delete('ref/{referential}/{id}', [ReferentialController::class, 'destroy'])->name('referentials.destroy');
            });

            Route::middleware('permission:configuration.branding.manage')->group(function (): void {
                Route::get('identity', [ClinicIdentityController::class, 'edit'])->name('identity.edit');
                Route::post('identity', [ClinicIdentityController::class, 'update'])->name('identity.update');
                Route::delete('identity/logo', [ClinicIdentityController::class, 'destroyLogo'])->name('identity.logo.destroy');
                Route::get('identity/specialty/confirm', [ClinicIdentityController::class, 'confirmSpecialtyCorrection'])
                    ->name('identity.specialty.confirm');
                Route::patch('identity/specialty', [ClinicIdentityController::class, 'correctSpecialty'])
                    ->middleware('password.confirm')
                    ->name('identity.specialty.correct');
            });

            Route::get('connectivity-backup', [ConnectivityAndBackupController::class, 'edit'])
                ->middleware('permission:configuration.connectivity.manage|configuration.backups.manage|configuration.restore.manage|configuration.drive.manage|configuration.licensing.manage|configuration.diagnostics.view')
                ->name('connectivity-backup.edit');
            Route::get('connectivity-backup/confirm-sensitive-actions', [ConnectivityAndBackupController::class, 'confirmSensitiveActions'])
                ->middleware('permission:configuration.connectivity.manage|configuration.backups.manage|configuration.restore.manage|configuration.drive.manage|configuration.licensing.manage')
                ->name('connectivity-backup.confirm-sensitive-actions');
            Route::put('connectivity-backup', [ConnectivityAndBackupController::class, 'update'])
                ->middleware('permission:configuration.connectivity.manage|configuration.backups.manage')
                ->name('connectivity-backup.update');

            Route::middleware('permission:configuration.backups.manage')->group(function (): void {
                Route::get('backup/local', [BackupController::class, 'local'])
                    ->middleware('password.confirm')
                    ->name('backup.local');
                Route::post('backup/local/encrypted', [BackupController::class, 'encryptedLocal'])
                    ->middleware('password.confirm')
                    ->name('backup.local.encrypted');
            });

            Route::middleware('permission:configuration.restore.manage')->group(function (): void {
                Route::post('backup/restore', [BackupController::class, 'restore'])
                    ->middleware('password.confirm')
                    ->name('backup.restore');
                Route::post('backup/restore/prepare', PrepareOfflineRestoreController::class)
                    ->middleware(['password.confirm', 'throttle:offline-restore-prepare'])
                    ->name('backup.restore.prepare');
            });

            Route::middleware('permission:configuration.drive.manage')->group(function (): void {
                Route::post('backup/google/prepare', [BackupController::class, 'prepareGoogleOAuth'])
                    ->middleware('password.confirm')
                    ->name('backup.google.prepare');
                Route::get('backup/google/files', [BackupController::class, 'driveFiles'])->name('backup.google.files');
                Route::post('backup/google/files/{fileId}/download', [BackupController::class, 'downloadDriveFile'])
                    ->where('fileId', '[A-Za-z0-9_-]{1,200}')
                    ->middleware('password.confirm')
                    ->name('backup.google.files.download');
                Route::delete('backup/google/files/{fileId}', [BackupController::class, 'deleteDriveFile'])
                    ->where('fileId', '[A-Za-z0-9_-]{1,200}')
                    ->middleware('password.confirm')
                    ->name('backup.google.files.destroy');
                Route::delete('backup/google', [BackupController::class, 'disconnectDrive'])
                    ->middleware('password.confirm')
                    ->name('backup.google.disconnect');
                Route::post('backup/google/test', [BackupController::class, 'testDriveConnection'])->name('backup.google.test');
                Route::post('backup/drive', [BackupController::class, 'storeDrive'])
                    ->middleware('password.confirm')
                    ->name('backup.drive.store');
                Route::delete('backup/drive/{backupRecordId}/upload', [BackupController::class, 'cancelDriveUpload'])
                    ->whereUuid('backupRecordId')
                    ->name('backup.drive.cancel');
            });

            Route::middleware('permission:configuration.licensing.manage')->group(function (): void {
                Route::post('license/activate', [LicenseController::class, 'store'])
                    ->middleware(['password.confirm', 'throttle:license-activation'])
                    ->name('license.activate');
                Route::post('license/refresh', [LicenseController::class, 'refresh'])
                    ->middleware(['password.confirm', 'throttle:license-activation'])
                    ->name('license.refresh');
                Route::delete('license', [LicenseController::class, 'destroy'])
                    ->middleware(['password.confirm', 'throttle:license-activation'])
                    ->name('license.destroy');
            });

            Route::middleware('permission:configuration.connectivity.manage')->group(function (): void {
                Route::post('updates/prepare-install', PrepareUpdateInstallController::class)
                    ->middleware(['password.confirm', 'throttle:update-install-prepare'])
                    ->name('updates.prepare-install');
                Route::post('connectivity-backup/upload-sessions', [UploadSessionController::class, 'store'])
                    ->name('connectivity-backup.upload-sessions.store');
                Route::post('connectivity-backup/upload-sessions/{uploadSession}/test', [UploadSessionController::class, 'test'])
                    ->name('connectivity-backup.upload-sessions.test');
                Route::delete('connectivity-backup/upload-sessions/{uploadSession}', [UploadSessionController::class, 'destroy'])
                    ->name('connectivity-backup.upload-sessions.destroy');
                Route::get('connectivity-backup/uploaded-documents/{uploadedDocument}/preview', [UploadSessionController::class, 'preview'])
                    ->name('connectivity-backup.uploaded-documents.preview');
                Route::post('connectivity-backup/uploaded-documents/{uploadedDocument}/accept', [UploadSessionController::class, 'accept'])
                    ->name('connectivity-backup.uploaded-documents.accept');
                Route::post('connectivity-backup/uploaded-documents/{uploadedDocument}/reject', [UploadSessionController::class, 'reject'])
                    ->name('connectivity-backup.uploaded-documents.reject');
            });
        });

        Route::middleware('permission:staff.manage')->group(function () {
            Route::get('staff', StaffIndexController::class)->name('staff.index');
            Route::post('staff', [StaffIndexController::class, 'store'])->name('staff.store');
            Route::put('staff/{user}', [StaffIndexController::class, 'update'])->name('staff.update');
            Route::delete('staff/{user}', [StaffIndexController::class, 'destroy'])->name('staff.destroy');

            Route::get('staff/pending', [PendingMemberController::class, 'index'])->name('staff.pending.index');
            Route::post('staff/pending/{user}/approve', [PendingMemberController::class, 'approve'])
                ->name('staff.pending.approve');
            Route::delete('staff/pending/{user}', [PendingMemberController::class, 'reject'])
                ->name('staff.pending.reject');
        });
    });
});

Route::middleware(EnsureGoogleOAuthLoopback::class)
    ->get('app/configuration/backup/google/callback', [BackupController::class, 'googleCallback'])
    ->name('app.configuration.backup.google.callback');

Route::pattern('selector', '[A-Za-z0-9_-]{22}');

Route::middleware(['throttle:public-uploads', SecurePublicUploadHeaders::class])
    ->prefix('upload')
    ->name('upload.')
    ->group(function (): void {
        Route::get('{selector}', [PublicUploadController::class, 'show'])->name('show');
        Route::post('{selector}/authorize', [PublicUploadController::class, 'session'])->name('session');
        Route::post('{selector}/files', [PublicUploadController::class, 'store'])->name('files.store');
        Route::post('{selector}/complete', [PublicUploadController::class, 'complete'])->name('complete');
    });

Route::get('app/clinical-documents/{document}/file', [ClinicalDocumentController::class, 'file'])->name('clinical-documents.file');
Route::post('app/clinical-documents/{document}/callback', [ClinicalDocumentController::class, 'callback'])->name('clinical-documents.callback');

require __DIR__.'/settings.php';
