<?php

namespace App\Http\Controllers\Settings;

use App\Configuration\ApplicationSettingRegistry;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\IdleLockUpdateRequest;
use App\Models\AuditLog;
use App\Services\ApplicationSettingService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

final class IdleLockController extends Controller
{
    public function update(
        IdleLockUpdateRequest $request,
        ApplicationSettingService $settings,
    ): RedirectResponse {
        $key = ApplicationSettingRegistry::SECURITY_IDLE_LOCK_MINUTES;
        $previous = (int) $settings->get($key);
        $updated = (int) $settings->set($key, $request->validated('idle_lock_minutes'));

        AuditLog::record('security.idle_lock_updated', $request->user(), [
            'previous_minutes' => $previous,
            'updated_minutes' => $updated,
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Délai de verrouillage mis à jour.',
        ]);

        return to_route('security.edit');
    }
}
