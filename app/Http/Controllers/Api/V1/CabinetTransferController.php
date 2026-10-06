<?php

namespace App\Http\Controllers\Api\V1;

use App\CabinetTransfer\CabinetTransferChanged;
use App\CabinetTransfer\CabinetTransferExporter;
use App\CabinetTransfer\CabinetTransferPurger;
use App\Http\Controllers\Controller;
use App\Models\Cabinet;
use App\Models\User;
use App\Support\ClinicalWorkstation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Lets a cabinet's own PC take its records from the online service, then
 * remove them here. Only the cabinet owner, with the token the PC received
 * when it was activated with that account. Deliberately not gated by the
 * licence: a cabinet can always take its records back.
 */
class CabinetTransferController extends Controller
{
    public function __construct(private readonly CabinetTransferExporter $exporter) {}

    public function manifest(Request $request): JsonResponse
    {
        return response()->json($this->exporter->manifest($this->cabinet($request)));
    }

    public function page(Request $request, string $table): JsonResponse
    {
        $cabinet = $this->cabinet($request);
        $after = max(0, (int) $request->query('after', '0'));

        try {
            return response()->json($this->exporter->page($cabinet, $table, $after));
        } catch (InvalidArgumentException) {
            abort(404);
        }
    }

    public function file(Request $request, string $key): BinaryFileResponse
    {
        $path = $this->exporter->filePath($this->cabinet($request), $key);
        abort_if($path === null, 404);

        return response()->file($path, ['Content-Type' => 'application/octet-stream']);
    }

    public function complete(Request $request, CabinetTransferPurger $purger): JsonResponse
    {
        $cabinet = $this->cabinet($request);
        $data = $request->validate([
            'fingerprint' => ['required', 'string', 'size:64'],
            'installation_id' => ['required', 'string', 'max:120'],
        ]);

        try {
            $removed = $purger->purge($cabinet, $data['fingerprint'], $data['installation_id']);
        } catch (CabinetTransferChanged $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json([
            'transferred_at' => $cabinet->refresh()->clinical_data_transferred_at?->toIso8601String(),
            'removed' => $removed,
        ]);
    }

    private function cabinet(Request $request): Cabinet
    {
        abort_unless(ClinicalWorkstation::isOnlineService(), 404);

        $user = $request->user();
        $cabinet = $user instanceof User ? $user->cabinet : null;

        abort_unless(
            $user instanceof User
                && ! $user->is_platform_admin
                && $cabinet instanceof Cabinet
                && $cabinet->owner_user_id === $user->getKey(),
            403,
            'Seul le médecin titulaire du cabinet peut transférer ses dossiers.',
        );

        return $cabinet;
    }
}
