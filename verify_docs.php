<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Http\Controllers\Consultations\ConsultationController;
use App\Models\Consultation;
use App\Models\Document;
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
    echo 'show keys: '.(str_contains($sc, '"documents"') && str_contains($sc, '"prestations"') ? 'OK' : 'FAIL').PHP_EOL;

    $dr = $make('POST', '/x', ['category' => 'courrier', 'title' => 'ZZ Test Doc', 'content' => '<p>hello</p>']);
    app(ConsultationController::class)->storeDocument($dr, $consultation);
    $d = Document::query()->where('title', 'ZZ Test Doc')->first();
    echo 'storeDocument: '.($d ? 'OK' : 'FAIL').PHP_EOL;
    $d?->delete();

    echo 'ALL GOOD'.PHP_EOL;
} catch (Throwable $e) {
    echo 'ERROR: '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine().PHP_EOL;
}
