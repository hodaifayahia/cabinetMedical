<?php

use App\Backups\FirstRunBackupImporter;
use App\Enums\PermissionName;
use App\Http\Controllers\Ai\ClinicalAiController;
use App\Http\Controllers\Ai\EcgController;
use App\Http\Controllers\Appointments\AppointmentController;
use App\Http\Controllers\Appointments\AvailabilityController;
use App\Http\Controllers\Appointments\OpenMonthController;
use App\Http\Controllers\Appointments\ReminderController;
use App\Http\Controllers\Appointments\ScheduleController;
use App\Http\Controllers\Appointments\TimeOffController;
use App\Http\Controllers\Appointments\WaitingRoomController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\DesktopCabinetLoginController;
use App\Http\Controllers\Auth\DesktopPinEnrollmentController;
use App\Http\Controllers\Auth\DesktopPinLoginController;
use App\Http\Controllers\Auth\DesktopRestoreBackupController;
use App\Http\Controllers\Auth\SessionLockController;
use App\Http\Controllers\Cabinet\CabinetStatusController;
use App\Http\Controllers\Cabinet\JoinCabinetController;
use App\Http\Controllers\Cabinet\RedeemHostedLicenseCodeController;
use App\Http\Controllers\Configuration\AccountingController;
use App\Http\Controllers\Configuration\BackupController;
use App\Http\Controllers\Configuration\ClinicIdentityController;
use App\Http\Controllers\Configuration\ConnectivityAndBackupController;
use App\Http\Controllers\Configuration\DocumentTemplateController;
use App\Http\Controllers\Configuration\LicenseController;
use App\Http\Controllers\Configuration\MedicationController;
use App\Http\Controllers\Configuration\OnlineServiceController;
use App\Http\Controllers\Configuration\PrepareOfflineRestoreController;
use App\Http\Controllers\Configuration\PrepareUpdateInstallController;
use App\Http\Controllers\Configuration\ReferentialController;
use App\Http\Controllers\Configuration\RolePermissionController;
use App\Http\Controllers\Configuration\UploadSessionController;
use App\Http\Controllers\Consultations\BilanTemplateController;
use App\Http\Controllers\Consultations\ClinicalDocumentController;
use App\Http\Controllers\Consultations\ConsultationController;
use App\Http\Controllers\Consultations\ConsultationHistoryController;
use App\Http\Controllers\Consultations\DiagnosisCodeController;
use App\Http\Controllers\Consultations\PrescriptionProtocolController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DesktopDownloadController;
use App\Http\Controllers\DesktopDownloadLeadController;
use App\Http\Controllers\DesktopUpdateArtifactController;
use App\Http\Controllers\DesktopUpdateManifestController;
use App\Http\Controllers\Encounters\EncounterController;
use App\Http\Controllers\GlobalSearchController;
use App\Http\Controllers\Patients\PatientAlertController;
use App\Http\Controllers\Patients\PatientController;
use App\Http\Controllers\Patients\PatientMergeController;
use App\Http\Controllers\Patients\VaccinationController;
use App\Http\Controllers\Payments\ExpenseController;
use App\Http\Controllers\Payments\FinanceController;
use App\Http\Controllers\Payments\PaymentController;
use App\Http\Controllers\PublicUploadController;
use App\Http\Controllers\Reports\MedicalStatisticsController;
use App\Http\Controllers\Staff\PendingMemberController;
use App\Http\Controllers\Staff\StaffIndexController;
use App\Http\Controllers\Staff\StaffSeatController;
use App\Http\Controllers\Sync\MobileSyncController;
use App\Http\Middleware\EnsureGoogleOAuthLoopback;
use App\Http\Middleware\SecurePublicUploadHeaders;
use App\Models\LandingSection;
use App\Models\LandingSetting;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', static fn () => Inertia::render('Welcome', [
    'canRegister' => true,
    // A desktop nobody has set up yet may start from a clinic backup.
    'canRestoreBackup' => app(FirstRunBackupImporter::class)->available(),
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
    'landingSettings' => LandingSetting::query()
        ->get(['key', 'locale', 'value'])
        ->map(static fn (LandingSetting $setting): array => [
            'key' => $setting->key,
            'locale' => $setting->locale,
            'value' => $setting->value,
        ])
        ->values()
        ->all(),
]))->name('home');

// Compatibility aliases for links retained by older desktop builds.
Route::get('home', fn (): RedirectResponse => to_route('home'))
    ->name('legacy.home');
Route::get('password/confirm', fn (): RedirectResponse => redirect('/user/confirm-password'))
    ->middleware('auth')
    ->name('legacy.password.confirm');

Route::get('desktop/download', [DesktopDownloadLeadController::class, 'show'])
    ->name('desktop.download');
Route::post('desktop/download', [DesktopDownloadLeadController::class, 'store'])
    ->middleware('throttle:desktop-download-leads')
    ->name('desktop.download.store');
Route::get('desktop/download/file/{lead}', DesktopDownloadController::class)
    ->middleware(['signed', 'throttle:desktop-download-files'])
    ->name('desktop.download.file');

// The two endpoints an installed shell talks to. Both are public on purpose:
// the updater runs with no user session and sends no cookies, so there is
// nothing to authenticate. Integrity comes from the detached signature the
// shell verifies, not from these routes being private.
//
// The trailing slash matters. It is the exact path baked into shipped builds
// as MEDISMART_UPDATER_ENDPOINT, and an already installed copy cannot be told
// to look somewhere else.
Route::get('desktop-updates/', DesktopUpdateManifestController::class)
    ->middleware('throttle:desktop-updates')
    ->name('desktop.updates.manifest');
Route::get('desktop-updates/artifact/{release}', DesktopUpdateArtifactController::class)
    ->middleware('throttle:desktop-download-files')
    ->name('desktop.updates.artifact');

Route::middleware('guest')->group(function (): void {
    Route::get('desktop/cabinet-login', [DesktopCabinetLoginController::class, 'create'])
        ->name('desktop.cabinet-login');
    // Only while the desktop is still empty (no account, no cabinet); the
    // controller sends everyone else to the sign-in page.
    Route::get('desktop/restore-backup', [DesktopRestoreBackupController::class, 'create'])
        ->name('desktop.restore-backup');
    Route::post('desktop/restore-backup', [DesktopRestoreBackupController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('desktop.restore-backup.store');
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
    // Allow installers and email links to open the activation URL directly.
    Route::get('cabinet/license/redeem', fn (): RedirectResponse => to_route('cabinet.pending'))
        ->name('cabinet.license.redeem.form');
    Route::get('cabinet/awaiting-approval', [CabinetStatusController::class, 'awaitingApproval'])
        ->name('cabinet.awaiting-approval');
    Route::post('cabinet/sign-out', [CabinetStatusController::class, 'signOut'])
        ->name('cabinet.sign-out');
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
        Route::get('audit-logs', AuditLogController::class)
            ->middleware('permission:audit-logs.view')
            ->name('audit-logs.index');
        Route::get('waiting-room', WaitingRoomController::class)
            ->middleware('permission:appointments.view')
            ->name('waiting-room');
        Route::get('search', GlobalSearchController::class)
            ->middleware('permission:patients.view')
            ->name('search');
        Route::get('reminders', [ReminderController::class, 'index'])
            ->middleware('permission:appointments.view')
            ->name('reminders.index');
        Route::post('reminders/appointments/{appointment}', [ReminderController::class, 'markAppointmentReminded'])
            ->middleware('permission:appointments.update')
            ->name('reminders.appointments.mark');
        Route::middleware('permission:patients.update')->group(function () {
            Route::post('patients/{patient}/recalls', [ReminderController::class, 'storeRecall'])->name('patients.recalls.store');
            Route::patch('recalls/{recall}', [ReminderController::class, 'updateRecall'])->name('recalls.update');
        });
        Route::get('patients/{patient}/vaccinations/print', [VaccinationController::class, 'print'])
            ->middleware('permission:patients.view')
            ->name('patients.vaccinations.print');
        Route::middleware('permission:patients.update')->group(function () {
            Route::post('patients/{patient}/vaccinations', [VaccinationController::class, 'store'])->name('patients.vaccinations.store');
            Route::delete('vaccinations/{vaccination}', [VaccinationController::class, 'destroy'])->name('vaccinations.destroy');
        });
        Route::middleware('permission:patients.update')->group(function () {
            Route::post('patients/{patient}/alerts', [PatientAlertController::class, 'store'])->name('patients.alerts.store');
            Route::patch('patient-alerts/{alert}/deactivate', [PatientAlertController::class, 'deactivate'])->name('patient-alerts.deactivate');
            Route::delete('patient-alerts/{alert}', [PatientAlertController::class, 'destroy'])->name('patient-alerts.destroy');
        });
        Route::get('patient-duplicates', [PatientMergeController::class, 'duplicates'])
            ->middleware('permission:patients.delete')
            ->name('patients.duplicates');
        Route::middleware('permission:patients.delete')->group(function () {
            Route::get('patients/{patient}/merge/candidates', [PatientMergeController::class, 'candidates'])->name('patients.merge.candidates');
            Route::get('patients/{patient}/merge/{duplicate}', [PatientMergeController::class, 'preview'])->name('patients.merge.preview');
            Route::post('patients/{patient}/merge', [PatientMergeController::class, 'store'])->name('patients.merge.store');
        });
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
        // Two-way exchange with the online service the mobile app uses. Distinct
        // from the mobile-sync routes below, which only republish a local
        // snapshot onto this cabinet's outgoing stream.
        Route::post('appointments/sync-with-mobile', MobileSyncController::class)
            ->name('appointments.sync-with-mobile');
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
        Route::get('cim10', [DiagnosisCodeController::class, 'search'])
            ->middleware('permission:consultations.view')
            ->name('cim10.search');
        Route::put('consultations/{consultation}/diagnoses', [DiagnosisCodeController::class, 'sync'])
            ->middleware('permission:consultations.update')
            ->name('consultations.diagnoses.sync');
        Route::middleware('permission:prescriptions.create')->group(function () {
            Route::post('prescription-protocols', [PrescriptionProtocolController::class, 'store'])->name('prescription-protocols.store');
            Route::post('prescription-protocols/{protocol}/used', [PrescriptionProtocolController::class, 'used'])->name('prescription-protocols.used');
            Route::delete('prescription-protocols/{protocol}', [PrescriptionProtocolController::class, 'destroy'])->name('prescription-protocols.destroy');
        });
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

        // Reusable exam selections saved from the bilan editor. They belong to
        // the cabinet rather than to one consultation, hence no {consultation}.
        Route::post('bilan-templates', [BilanTemplateController::class, 'store'])
            ->middleware('permission:consultations.update')->name('bilan-templates.store');
        Route::delete('bilan-templates/{bilanTemplate}', [BilanTemplateController::class, 'destroy'])
            ->middleware('permission:consultations.update')->name('bilan-templates.destroy');

        // Clinical AI assistant. Each POST spends the cabinet's AI credits;
        // the GETs return stored results and the balance for free.
        Route::prefix('ai')->name('ai.')->group(function () {
            Route::get('status', [ClinicalAiController::class, 'status'])->name('status');

            Route::middleware(['permission:consultations.view', 'throttle:30,1'])->group(function () {
                Route::get('patients/{patient}/analysis', [ClinicalAiController::class, 'patientAnalysis'])
                    ->name('patients.analysis.show');
                Route::post('patients/{patient}/analysis', [ClinicalAiController::class, 'analyzePatient'])
                    ->name('patients.analysis.store');
                Route::get('consultations/{consultation}/document-analyses', [ClinicalAiController::class, 'documentAnalyses'])
                    ->name('consultations.document-analyses');
            });

            Route::middleware(['permission:consultations.update', 'throttle:30,1'])->group(function () {
                Route::post('consultations/{consultation}/consultation-text', [ClinicalAiController::class, 'consultationText'])
                    ->name('consultations.text');
                Route::post('consultations/{consultation}/exams', [ClinicalAiController::class, 'exams'])
                    ->name('consultations.exams');
                Route::post('consultations/{consultation}/documents/{document}/analysis', [ClinicalAiController::class, 'analyzeDocument'])
                    ->name('consultations.documents.analysis');
            });

            Route::post('consultations/{consultation}/prescription', [ClinicalAiController::class, 'prescription'])
                ->middleware(['permission:prescriptions.create', 'throttle:30,1'])
                ->name('consultations.prescription');

            // Copilot: a conversation that proposes actions the doctor applies.
            Route::middleware('permission:consultations.update')->group(function () {
                Route::get('consultations/{consultation}/copilot', [ClinicalAiController::class, 'copilotHistory'])
                    ->name('consultations.copilot.show');
                Route::post('consultations/{consultation}/copilot', [ClinicalAiController::class, 'copilot'])
                    ->middleware('throttle:30,1')
                    ->name('consultations.copilot.store');
                Route::delete('consultations/{consultation}/copilot', [ClinicalAiController::class, 'copilotReset'])
                    ->name('consultations.copilot.reset');

                Route::post('ecgs/{ecg}/analysis', [EcgController::class, 'analyze'])
                    ->middleware('throttle:20,1')
                    ->name('ecgs.analysis');
                Route::post('ecgs/{ecg}/chat', [EcgController::class, 'chat'])
                    ->middleware('throttle:30,1')
                    ->name('ecgs.chat');
            });
        });

        // ECG tracings of the consultation's patient.
        Route::middleware('permission:consultations.view')->group(function () {
            Route::get('consultations/{consultation}/ecgs', [EcgController::class, 'index'])->name('ecgs.index');
            Route::get('ecgs/{ecg}/file', [EcgController::class, 'file'])->name('ecgs.file');
        });
        Route::middleware('permission:consultations.update')->group(function () {
            Route::post('consultations/{consultation}/ecgs', [EcgController::class, 'store'])->name('ecgs.store');
            Route::put('ecgs/{ecg}/measurements', [EcgController::class, 'measurements'])->name('ecgs.measurements');
            Route::put('ecgs/{ecg}/conclusion', [EcgController::class, 'conclude'])->name('ecgs.conclusion');
            Route::delete('ecgs/{ecg}', [EcgController::class, 'destroy'])->name('ecgs.destroy');
        });

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
            Route::get('payments/analytics', [FinanceController::class, 'analytics'])->name('payments.analytics');
            Route::get('payments/analytics/print', [FinanceController::class, 'printAnalytics'])->name('payments.analytics.print');
            Route::get('payments/export', [FinanceController::class, 'export'])->name('payments.export');
            Route::get('payments/{consultation}/print', [PaymentController::class, 'printReceipt'])
                ->name('payments.receipt');
        });
        // Operating costs, net profit and medical statistics: doctor-level
        // (reports) only.
        Route::middleware('permission:reports.view')->group(function () {
            Route::get('statistics', MedicalStatisticsController::class)->name('statistics.index');
            Route::get('expenses', [ExpenseController::class, 'index'])->name('expenses.index');
            Route::get('expenses/export', [ExpenseController::class, 'export'])->name('expenses.export');
            Route::post('expenses', [ExpenseController::class, 'store'])->name('expenses.store');
            Route::post('expenses/recurring', [ExpenseController::class, 'copyRecurring'])->name('expenses.recurring');
            Route::patch('expenses/{expense}', [ExpenseController::class, 'update'])->name('expenses.update');
            Route::delete('expenses/{expense}', [ExpenseController::class, 'destroy'])->name('expenses.destroy');
        });
        Route::patch('payments/{consultation}', [PaymentController::class, 'update'])
            ->middleware('permission:payments.create')
            ->name('payments.update');
        Route::post('payments/{consultation}/refunds', [PaymentController::class, 'refund'])
            ->middleware('permission:payments.refund')
            ->name('payments.refunds.store');
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

                // Cabinet-authored consultation document templates ("modèles").
                Route::get('document-templates', [DocumentTemplateController::class, 'index'])->name('document-templates.index');
                Route::post('document-templates', [DocumentTemplateController::class, 'store'])->name('document-templates.store');
                Route::put('document-templates/{documentTemplate}', [DocumentTemplateController::class, 'update'])->name('document-templates.update');
                Route::delete('document-templates/{documentTemplate}', [DocumentTemplateController::class, 'destroy'])->name('document-templates.destroy');
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

            Route::middleware('permission:configuration.connectivity.manage')->group(function (): void {
                Route::get('online-service', [OnlineServiceController::class, 'edit'])->name('online-service.edit');
                Route::post('online-service', [OnlineServiceController::class, 'store'])
                    ->middleware('throttle:6,1')
                    ->name('online-service.store');
                Route::delete('online-service', [OnlineServiceController::class, 'destroy'])->name('online-service.destroy');
            });

            Route::middleware('permission:configuration.backups.manage')->group(function (): void {
                // Writes a local archive only (nothing leaves the PC), so it
                // needs no recent password confirmation.
                Route::post('backup/now', [BackupController::class, 'createNow'])
                    ->middleware('throttle:6,1')
                    ->name('backup.now');
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
                Route::put('backup/drive/automatic', [BackupController::class, 'updateDriveAutomaticUpload'])
                    ->middleware('password.confirm')
                    ->name('backup.drive.automatic');
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

            // Every approved account on a single-cabinet supervised desktop
            // may start the signed update from the in-app release notice. The
            // controller enforces that installation boundary; recent password
            // confirmation, a verified backup and the native signature check
            // remain required.
            Route::post('updates/prepare-install', PrepareUpdateInstallController::class)
                ->middleware(['password.confirm', 'throttle:update-install-prepare'])
                ->name('updates.prepare-install');

            Route::middleware('permission:configuration.connectivity.manage')->group(function (): void {
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
            Route::post('staff/seats/refresh', StaffSeatController::class)
                ->middleware('throttle:20,1')
                ->name('staff.seats.refresh');
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
