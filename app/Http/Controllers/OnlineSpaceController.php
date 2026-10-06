<?php

namespace App\Http\Controllers;

use App\Models\Cabinet;
use App\Models\User;
use App\Services\DesktopDownloadService;
use App\Support\ClinicalWorkstation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A cabinet's page on the online service. Its records belong in the desktop
 * app; this page says where they are, how many are still online waiting to
 * be moved to the PC, and leads to the account, licence and staff screens.
 */
class OnlineSpaceController extends Controller
{
    public function __invoke(Request $request, DesktopDownloadService $downloads): Response|RedirectResponse
    {
        if (ClinicalWorkstation::clinicalScreensOpen()) {
            return redirect()->route('dashboard');
        }

        /** @var User $user */
        $user = $request->user();

        if ($user->is_platform_admin) {
            return redirect('/admin');
        }

        $cabinet = $user->cabinet;

        return Inertia::render('OnlineSpace', [
            'cabinet' => $cabinet instanceof Cabinet ? [
                'name' => $cabinet->name,
                'is_owner' => $cabinet->owner_user_id === $user->getKey(),
                'transferred_at' => $cabinet->clinical_data_transferred_at?->toIso8601String(),
            ] : null,
            'recordsOnline' => $cabinet instanceof Cabinet ? $this->recordsOnline($cabinet) : null,
            'download' => $downloads->sharedProps(),
        ]);
    }

    /**
     * Patient records this cabinet still has on the online service.
     *
     * @return array{patients: int, consultations: int, documents: int}
     */
    private function recordsOnline(Cabinet $cabinet): array
    {
        $count = static fn (string $table): int => Schema::hasTable($table)
            ? DB::table($table)->where('cabinet_id', $cabinet->getKey())->count()
            : 0;

        return [
            'patients' => $count('patients'),
            'consultations' => $count('consultations'),
            'documents' => $count('documents'),
        ];
    }
}
