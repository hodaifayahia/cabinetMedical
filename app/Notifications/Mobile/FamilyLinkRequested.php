<?php

namespace App\Notifications\Mobile;

use App\Models\FamilyMember;
use App\Models\User;
use Illuminate\Notifications\Notification;

/**
 * Sent to the account another patient wants to add to their family circle.
 * The recipient must approve or decline the pending link.
 */
class FamilyLinkRequested extends Notification
{
    public function __construct(
        private readonly FamilyMember $familyMember,
        private readonly User $owner,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'family_member_id' => $this->familyMember->getKey(),
            'owner_name' => $this->owner->name,
            'relation' => $this->familyMember->relation->value,
            'status' => $this->familyMember->status->value,
        ];
    }
}
