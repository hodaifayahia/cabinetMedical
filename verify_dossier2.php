<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Actions\Appointments\CreateAppointmentAction;
use App\Http\Controllers\Consultations\ConsultationController;
use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\PatientMeasurement;
use App\Models\Prescription;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;

$user = User::query()->where('email', 'test@example.com')->first() ?? User::query()->first();
auth()->login($user);

$make = function (string $method, string $uri, array $params = []) use ($user): Request {
    $request = Request::create($uri, $method, $params);
    $request->setUserResolver(fn () => $user);
    $request->headers->set('X-Inertia', 'true');

    return $request;
};

try {
    $consultation = Consultation::query()->first();

    $sr = $make('GET', '/x');
    $sc = app(ConsultationController::class)->show($sr, $consultation)->toResponse($sr)->getContent();
    echo 'show keys: '.(str_contains($sc, '"upcoming"') && str_contains($sc, '"measurements"') && str_contains($sc, '"prescriptions"') && str_contains($sc, '"cabinet"') && str_contains($sc, '"medications"') ? 'OK' : 'FAIL').PHP_EOL;

    $rv = $make('POST', '/x', ['starts_at' => '2026-08-15 10:00:00', 'title' => 'ZZ Test RV', 'notes' => 'test']);
    app(ConsultationController::class)->scheduleNext($rv, $consultation, app(CreateAppointmentAction::class));
    $appt = Appointment::query()->where('reason', 'ZZ Test RV')->first();
    echo 'scheduleNext: '.($appt ? 'OK' : 'FAIL').PHP_EOL;

    $mr = $make('POST', '/x', ['measured_at' => now()->toDateString(), 'weight_kg' => 72, 'height_cm' => 180]);
    app(ConsultationController::class)->storeMeasurement($mr, $consultation);
    $m = PatientMeasurement::query()->where('patient_id', $consultation->patient_id)->latest('id')->first();
    echo 'measurement bmi='.$m?->bmi.' '.((string) $m?->bmi === '22.2' ? 'OK' : 'FAIL').PHP_EOL;

    $pr = $make('POST', '/x', ['prescribed_at' => now()->toDateString(), 'items' => [['medication' => 'DOLIPRANE', 'dosage' => '1g x3', 'duration' => '5j']], 'notes' => '']);
    app(ConsultationController::class)->storePrescription($pr, $consultation);
    $p = Prescription::query()->where('patient_id', $consultation->patient_id)->latest('id')->first();
    echo 'prescription items='.count($p?->items ?? []).' '.(count($p?->items ?? []) === 1 ? 'OK' : 'FAIL').PHP_EOL;

    // cleanup
    $m?->delete();
    $p?->delete();
    $appt?->delete();

    echo 'ALL GOOD'.PHP_EOL;
} catch (Throwable $e) {
    echo 'ERROR: '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine().PHP_EOL;
}
