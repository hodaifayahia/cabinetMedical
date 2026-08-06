<?php

namespace App\Actions\Encounters;

use App\Enums\EncounterStatus;
use App\Models\Encounter;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateAmendmentAction
{
    /**
     * Create an amendment (correction) to a signed encounter.
     * The amendment is a new encounter that references the previous one.
     *
     * @param  array<string, string>  $correctedSections
     */
    public function handle(
        Encounter $originalEncounter,
        User $provider,
        string $amendmentReason,
        array $correctedSections = []
    ): Encounter {
        return DB::transaction(function () use ($originalEncounter, $provider, $amendmentReason, $correctedSections) {
            if ($originalEncounter->status !== EncounterStatus::Signed) {
                throw new \RuntimeException('Can only amend signed encounters.');
            }

            $amendment = new Encounter([
                'patient_id' => $originalEncounter->patient_id,
                'appointment_id' => $originalEncounter->appointment_id,
                'provider_id' => $provider->id,
                'status' => EncounterStatus::Draft,
                'occurred_at' => $originalEncounter->occurred_at,
                'started_at' => now(),
                'amends_encounter_id' => $originalEncounter->id,
                'amendment_reason' => $amendmentReason,
                'revision_number' => 1,
                'lock_version' => 1,
            ]);

            $amendment->save();

            // Copy original encounter notes as starting point
            $originalNotes = $originalEncounter->notes()
                ->where('revision_number', $originalEncounter->revision_number)
                ->get();

            foreach ($originalNotes as $note) {
                $amendment->notes()->create([
                    'section' => $note->section,
                    'content_text' => $correctedSections[$note->section] ?? $note->content_text,
                    'content_json' => $note->content_json,
                    'author_id' => $provider->id,
                    'revision_number' => 1,
                ]);
            }

            return $amendment;
        });
    }
}
