<?php

namespace App\Actions\Encounters;

use App\Enums\EncounterStatus;
use App\Models\Encounter;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SaveEncounterDraftAction
{
    /**
     * Save encounter notes with optimistic locking.
     *
     * @param  array<string, string>  $sections  Map of section name to content_text
     */
    public function handle(Encounter $encounter, User $author, array $sections, int $expectedLockVersion): Encounter
    {
        return DB::transaction(function () use ($encounter, $author, $sections, $expectedLockVersion) {
            if ($encounter->lock_version !== $expectedLockVersion) {
                throw new \RuntimeException('Optimistic lock version mismatch; your changes cannot be saved.');
            }

            foreach ($sections as $section => $content) {
                $encounter->notes()->updateOrCreate(
                    [
                        'section' => $section,
                        'revision_number' => $encounter->revision_number,
                    ],
                    [
                        'content_text' => $content,
                        'author_id' => $author->id,
                    ]
                );
            }

            $encounter->increment('lock_version');
            $encounter->status = EncounterStatus::InProgress;
            $encounter->save();

            return $encounter->fresh();
        });
    }
}
