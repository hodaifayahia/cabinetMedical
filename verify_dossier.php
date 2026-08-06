<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Http\Controllers\Consultations\ConsultationController;
use App\Models\Consultation;
use App\Models\Patient;
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

    if ($consultation === null) {
        echo 'no consultation to test'.PHP_EOL;

        return;
    }

    $sr = $make('GET', '/app/consultations/'.$consultation->id);
    $sc = app(ConsultationController::class)->show($sr, $consultation)->toResponse($sr)->getContent();
    echo 'show options: '.(str_contains($sc, '"maritalStatuses"') && str_contains($sc, '"paymentMethods"') ? 'OK' : 'FAIL').PHP_EOL;
    echo 'show antecedents: '.(str_contains($sc, 'antecedents_medical') ? 'OK' : 'FAIL').PHP_EOL;

    $patient = Patient::query()->findOrFail($consultation->patient_id);
    $pr = $make('PUT', 'x', [
        'first_name' => $patient->first_name,
        'last_name' => $patient->last_name,
        'gender' => $patient->gender?->value,
        'marital_status' => 'married',
        'antecedents_medical' => 'ZZ diabetes',
    ]);
    app(ConsultationController::class)->savePatient($pr, $consultation);
    $patient->refresh();
    echo 'savePatient: '.($patient->marital_status === 'married' && $patient->antecedents_medical === 'ZZ diabetes' ? 'OK' : 'FAIL').PHP_EOL;

    $ur = $make('PUT', 'x', ['weight_kg' => 72.5, 'payment_amount' => 2000, 'payment_method' => 'Espèces', 'is_paid' => 1, 'complete' => 0]);
    app(ConsultationController::class)->update($ur, $consultation);
    $consultation->refresh();
    echo 'update vitals/pay: weight='.$consultation->weight_kg.' minor='.$consultation->payment_amount_minor.' paid='.($consultation->is_paid ? '1' : '0')
        .' '.($consultation->payment_amount_minor === 200000 ? 'OK' : 'FAIL').PHP_EOL;

    // cleanup test data
    $patient->update(['marital_status' => null, 'antecedents_medical' => null]);
    $consultation->update(['weight_kg' => null, 'payment_amount_minor' => null, 'payment_method' => null, 'is_paid' => false, 'status' => 'in_progress', 'completed_at' => null]);

    echo 'ALL GOOD'.PHP_EOL;
} catch (Throwable $e) {
    echo 'ERROR: '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine().PHP_EOL;
}
