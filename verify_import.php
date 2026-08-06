<?php

require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\Exam;
use App\Models\Medication;
use Illuminate\Contracts\Console\Kernel;

echo 'medications='.Medication::count().PHP_EOL;
echo 'medications_with_notes='.Medication::whereNotNull('notes')->count().PHP_EOL;
echo 'exams='.Exam::count().PHP_EOL;

$m = Medication::where('name', 'ADVIL 200')->first();
echo PHP_EOL.'sample medication (ADVIL 200):'.PHP_EOL;
echo '  dci='.$m->dci.' form='.$m->form.' dosage='.$m->dosage.PHP_EOL;
echo '  notes='.str_replace(PHP_EOL, ' / ', (string) $m->notes).PHP_EOL;

echo PHP_EOL.'exam categories:'.PHP_EOL;
foreach (Exam::selectRaw('category, COUNT(*) as c')->groupBy('category')->orderBy('category')->get() as $r) {
    echo '  '.($r->category ?? '(none)').'='.$r->c.PHP_EOL;
}
