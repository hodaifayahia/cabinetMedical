<?php

namespace App\Notifications\Mobile;

use App\Models\FamilyMember;
use App\Models\User;
use Illuminate\Notifications\Notification;

/**
 * Sent to the family circle's owner once the linked account has approved
 * or declined their link request.
 */
class FamilyLinkResponded extends Notification
{
    public function __construct(
        private readonly FamilyMember $familyMember,
        private readonly User $responder,
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
            'responder_name' => $this->responder->name,
            'relation' => $this->familyMember->relation->value,
            'status' => $this->familyMember->status->value,
        ];
    }
}
