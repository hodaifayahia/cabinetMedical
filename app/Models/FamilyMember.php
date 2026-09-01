<?php

namespace App\Models;

use App\Enums\FamilyMemberStatus;
use App\Enums\FamilyRelation;
use App\Enums\Gender;
use Carbon\CarbonImmutable;
use Database\Factories\FamilyMemberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A member of a patient account's family circle: either a dependent profile
 * (demographics stored inline, no account) or a link to another patient
 * account that must approve the connection.
 *
 * @property int $owner_user_id
 * @property FamilyRelation $relation
 * @property FamilyMemberStatus $status
 * @property int|null $linked_user_id
 * @property Gender|null $gender
 * @property CarbonImmutable|null $date_of_birth
 * @property int|null $wilaya_code
 * @property int|null $baladiya_id
 */
#[Fillable([
    'owner_user_id',
    'relation',
    'status',
    'linked_user_id',
    'first_name',
    'last_name',
    'gender',
    'date_of_birth',
    'place_of_birth',
    'wilaya_code',
    'baladiya_id',
])]
class FamilyMember extends Model
{
    /** @use HasFactory<FamilyMemberFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'relation' => FamilyRelation::class,
            'status' => FamilyMemberStatus::class,
            'gender' => Gender::class,
            'date_of_birth' => 'date',
            'wilaya_code' => 'integer',
        ];
    }

    protected static function newFactory(): FamilyMemberFactory
    {
        return FamilyMemberFactory::new();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function linkedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_user_id');
    }

    /**
     * @return BelongsTo<Baladiya, $this>
     */
    public function baladiya(): BelongsTo
    {
        return $this->belongsTo(Baladiya::class);
    }

    /**
     * A dependent carries its demographics inline and has no account of its own.
     */
    public function isDependent(): bool
    {
        return $this->linked_user_id === null;
    }

    /**
     * Appointments may only be booked for active dependents and approved links.
     */
    public function isUsableForBooking(): bool
    {
        return $this->isDependent()
            ? $this->status === FamilyMemberStatus::ACTIVE
            : $this->status === FamilyMemberStatus::APPROVED;
    }
}
