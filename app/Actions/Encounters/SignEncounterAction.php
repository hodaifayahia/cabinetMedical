<?php

namespace App\Actions\Encounters;

use App\Enums\EncounterStatus;
use App\Models\Encounter;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SignEncounterAction
{
    /**
     * Sign an encounter, making it immutable.
     * Generates content hash and records signing metadata.
     */
    public function handle(Encounter $encounter, User $signer): Encounter
    {
        return DB::transaction(function () use ($encounter, $signer) {
            if ($encounter->status === EncounterStatus::Signed) {
                throw new \RuntimeException('Encounter is already signed.');
            }

            if (! $encounter->canBeSigned()) {
                throw new \RuntimeException('Encounter cannot be signed in its current state.');
            }

            $noteContent = $encounter->notes()
                ->where('revision_number', $encounter->revision_number)
                ->pluck('content_text', 'section')
                ->toArray();

            $contentForHash = json_encode([
                'notes' => $noteContent,
                'revision' => $encounter->revision_number,
            ]);

            $encounter->update([
                'status' => EncounterStatus::Signed,
                'signed_at' => now(),
                'signed_by' => $signer->id,
                'content_hash' => hash('sha256', (string) $contentForHash),
            ]);

            return $encounter->fresh();
        });
    }
}
