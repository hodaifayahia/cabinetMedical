<?php

declare(strict_types=1);

use App\Enums\LicensePlan;
use App\Models\AuditLog;
use App\Models\Cabinet;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$cabinets = Cabinet::query()
    ->with(['owner', 'license', 'hostedLicenseGrants' => fn ($query) => $query->latest()->limit(5)])
    ->get()
    ->filter(static function (Cabinet $cabinet): bool {
        $license = $cabinet->license;

        return $cabinet->name === 'ClickDZ Clinic'
            || ($license?->plan === LicensePlan::TRIAL && $license?->isExpired() === true)
            || $cabinet->hostedLicenseGrants->isNotEmpty();
    })
    ->values();

foreach ($cabinets as $cabinet) {
    $license = $cabinet->license;

    echo 'Cabinet #'.$cabinet->getKey().' '.$cabinet->name.PHP_EOL;
    echo '  owner: '.($cabinet->owner?->email ?? 'n/a').PHP_EOL;
    echo '  status: '.$cabinet->status->value.PHP_EOL;
    echo '  license_plan: '.($license?->plan?->value ?? 'none').PHP_EOL;
    echo '  license_status: '.($license?->status ?? 'none').PHP_EOL;
    echo '  license_expires_at: '.($license?->expires_at?->toIso8601String() ?? 'none').PHP_EOL;
    echo '  outstanding_grants: '.$cabinet->hostedLicenseGrants->filter(fn ($grant) => $grant->isOutstanding())->count().PHP_EOL;

    foreach ($cabinet->hostedLicenseGrants as $grant) {
        echo '    grant '.$grant->getKey()
            .' type='.$grant->typeLabel()
            .' suffix='.$grant->code_suffix
            .' redeemed_at='.($grant->redeemed_at?->toIso8601String() ?? 'null')
            .' revoked_at='.($grant->revoked_at?->toIso8601String() ?? 'null')
            .PHP_EOL;
    }

    $audits = AuditLog::query()
        ->where('subject_type', Cabinet::class)
        ->where('subject_id', (string) $cabinet->getKey())
        ->whereIn('action', [
            'cabinet.activated',
            'cabinet.license_code_issued',
            'cabinet.license_code_redeemed',
            'cabinet.license_renewed',
            'cabinet.suspended',
            'cabinet.reactivated',
        ])
        ->latest()
        ->limit(6)
        ->get();

    foreach ($audits as $audit) {
        echo '    audit '.$audit->created_at?->toIso8601String()
            .' action='.$audit->action
            .' metadata='.json_encode($audit->metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            .PHP_EOL;
    }

    echo PHP_EOL;
}