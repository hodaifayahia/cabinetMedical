<?php

namespace App\Http\Resources\Mobile;

use App\Enums\FamilyMemberStatus;
use App\Models\FamilyMember;
use App\Models\PatientProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A row of the patient's family circle. Dependents expose the demographics
 * stored inline; approved links expose the linked account's own profile.
 * Pending and declined links never leak the other account's identity.
 *
 * @mixin FamilyMember
 */
class FamilyMemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $profile = $this->linkedProfile();

        $firstName = $profile->first_name ?? ($this->isDependent() ? $this->first_name : null);
        $lastName = $profile->last_name ?? ($this->isDependent() ? $this->last_name : null);
        $gender = $profile->gender ?? ($this->isDependent() ? $this->gender : null);
        $dateOfBirth = $profile->date_of_birth ?? ($this->isDependent() ? $this->date_of_birth : null);
        $placeOfBirth = $profile->place_of_birth ?? ($this->isDependent() ? $this->place_of_birth : null);
        $wilayaCode = $profile->wilaya_code ?? ($this->isDependent() ? $this->wilaya_code : null);
        $baladiyaId = $profile->baladiya_id ?? ($this->isDependent() ? $this->baladiya_id : null);

        $viewerId = $request->user()?->getKey();
        $isIncoming = $viewerId !== null
            && (int) $this->linked_user_id === (int) $viewerId
            && (int) $this->owner_user_id !== (int) $viewerId;

        return [
            'id' => $this->id,
            'direction' => $isIncoming ? 'incoming' : 'outgoing',
            'can_respond' => $isIncoming && $this->status === FamilyMemberStatus::PENDING,
            'requested_by' => $isIncoming ? $this->requesterName() : null,
            'relation' => $this->relation->value,
            'relation_label' => $this->relation->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_linked' => ! $this->isDependent(),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'gender' => $gender?->value,
            'date_of_birth' => $dateOfBirth?->toDateString(),
            'place_of_birth' => $placeOfBirth,
            'wilaya_code' => $wilayaCode,
            'baladiya_id' => $baladiyaId,
            'age' => $dateOfBirth?->age,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * The display name of the account asking to link me. Shown only on
     * incoming requests so the invited patient knows who is asking; it is
     * never exposed on outgoing rows.
     */
    private function requesterName(): ?string
    {
        $profile = $this->owner?->patientProfile;

        if ($profile !== null) {
            return trim($profile->first_name.' '.$profile->last_name) ?: null;
        }

        return $this->owner?->name;
    }

    /**
     * The linked account's profile, exposed only once the link is approved.
     */
    private function linkedProfile(): ?PatientProfile
    {
        if ($this->isDependent() || $this->status !== FamilyMemberStatus::APPROVED) {
            return null;
        }

        return $this->linkedUser?->patientProfile;
    }
}
