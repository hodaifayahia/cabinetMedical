<?php

namespace App\Models;

use App\Enums\Gender;
use Carbon\CarbonImmutable;
use Database\Factories\PatientProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The demographic identity of a mobile patient account (one row per user).
 *
 * @property int $user_id
 * @property Gender $gender
 * @property CarbonImmutable|null $date_of_birth
 * @property int|null $wilaya_code
 * @property int|null $baladiya_id
 * @property-read string $full_name
 */
#[Fillable([
    'user_id',
    'first_name',
    'last_name',
    'gender',
    'date_of_birth',
    'place_of_birth',
    'wilaya_code',
    'baladiya_id',
    'avatar_path',
])]
class PatientProfile extends Model
{
    /** @use HasFactory<PatientProfileFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'gender' => Gender::class,
            'date_of_birth' => 'date',
            'wilaya_code' => 'integer',
        ];
    }

    protected static function newFactory(): PatientProfileFactory
    {
        return PatientProfileFactory::new();
    }

    /**
     * @return Attribute<string, never>
     */
    protected function fullName(): Attribute
    {
        return Attribute::get(fn (): string => trim(sprintf('%s %s', $this->first_name, $this->last_name)));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Baladiya, $this>
     */
    public function baladiya(): BelongsTo
    {
        return $this->belongsTo(Baladiya::class);
    }
}
