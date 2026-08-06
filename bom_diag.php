<?php

use App\Http\Controllers\Appointments\AvailabilityController;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

function hex10(string $s): string
{
    $out = '';
    for ($i = 0; $i < min(12, strlen($s)); $i++) {
        $out .= sprintf('%02x ', ord($s[$i]));
    }

    return $out;
}

$bom = "\xef\xbb\xbf";

// 1) Plain json response
$plain = response()->json(['a' => 1])->getContent();
echo '1) PLAIN json  hex='.hex10($plain).' bom='.(str_starts_with($plain, $bom) ? 'YES' : 'no')."\n";

// 2) Direct controller call (bypasses middleware)
$user = User::first();
if ($user) {
    Auth::login($user);
}
$request = Request::create('/app/appointments/availability/day', 'GET', ['date' => date('Y-m-d')]);
try {
    $controller = $app->make(AvailabilityController::class);
    $resp = $controller->day($request);
    $c = $resp->getContent();
    echo '2) CONTROLLER  hex='.hex10($c).' bom='.(str_starts_with($c, $bom) ? 'YES' : 'no').' len='.strlen($c)."\n";
} catch (Throwable $e) {
    echo '2) CONTROLLER ERR: '.get_class($e).': '.$e->getMessage()."\n";
}

// 3) Full HTTP kernel (includes global + route middleware). Auth may 302.
$httpKernel = $app->make(Kernel::class);
$req2 = Request::create('/app/appointments/availability/day', 'GET', ['date' => date('Y-m-d')]);
$req2->headers->set('Accept', 'application/json');
$req2->headers->set('X-Requested-With', 'XMLHttpRequest');
$resp2 = $httpKernel->handle($req2);
$c2 = $resp2->getContent();
echo '3) KERNEL status='.$resp2->getStatusCode().' hex='.hex10($c2).' bom='.(str_starts_with($c2, $bom) ? 'YES' : 'no')."\n";

echo "DONE_DIAG\n";
