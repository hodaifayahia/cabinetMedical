<?php

namespace App\Services\Cabinet;

use App\Enums\CabinetStatus;
use App\Models\Cabinet;
use App\Models\DesktopDownloadLead;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Records a cabinet's contact before it has an owner account: the Windows
 * download form and the back office both use it. The contact's e-mail ties
 * the pending cabinet to the account its owner creates later, online or on
 * the desktop, and receives the activation code.
 */
final class CabinetLeadRegistrar
{
    /**
     * @param  array<string, mixed>  $data  name, email, phone, cabinet_name and specialization, already validated
     */
    public function register(array $data): DesktopDownloadLead
    {
        return DB::transaction(function () use ($data): DesktopDownloadLead {
            $lead = DesktopDownloadLead::query()->create([
                'name' => (string) $data['name'],
                'email' => (string) $data['email'],
                'phone' => (string) $data['phone'],
                'cabinet_name' => (string) $data['cabinet_name'],
                'specialization' => (string) $data['specialization'],
            ]);

            $existingOwner = User::query()
                ->whereRaw('LOWER(email) = ?', [$lead->email])
                ->whereNotNull('cabinet_id')
                ->first();

            $cabinet = $existingOwner?->cabinet;

            if ($cabinet === null) {
                $cabinet = Cabinet::query()
                    ->whereNull('owner_user_id')
                    ->where('status', CabinetStatus::PENDING->value)
                    ->whereNull('license_id')
                    ->whereHas('desktopDownloadLeads', fn ($query) => $query
                        ->whereRaw('LOWER(email) = ?', [$lead->email]))
                    ->latest()
                    ->first();
            }

            $cabinet ??= Cabinet::query()->create([
                'name' => $lead->cabinet_name,
                'status' => CabinetStatus::PENDING,
                'specialization' => $lead->specialization,
            ]);

            $lead->forceFill(['cabinet_id' => $cabinet->getKey()])->save();

            return $lead;
        });
    }
}
