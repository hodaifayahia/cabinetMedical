<?php

namespace App\Models;

use App\Actions\Patients\GeneratePatientNumberAction;
use App\Enums\BloodGroup;
use App\Enums\Gender;
use App\Models\Concerns\BelongsToCabinet;
use Carbon\CarbonImmutable;
use Database\Factories\PatientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property CarbonImmutable|null $date_of_birth
 * @property Gender|null $gender
 * @property BloodGroup|null $blood_group
 * @property-read string $full_name
 */
#[Fillable([
    'patient_number',
    'first_name',
    'last_name',
    'date_of_birth',
    'gender',
    'marital_status',
    'profession',
    'smoking_status',
    'referred_by',
    'phone',
    'secondary_phone',
    'email',
    'address',
    'city',
    'emergency_contact_name',
    'emergency_contact_phone',
    'blood_group',
    'allergies',
    'antecedents_medical',
    'antecedents_surgical',
    'antecedents_family',
    'antecedents_gyneco',
    'antecedents_other',
    'notes',
    'created_by',
])]
class Patient extends Model
{
    /** @use HasFactory<PatientFactory> */
    use BelongsToCabinet, HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (self $patient): void {
            if (blank($patient->patient_number)) {
                $patient->patient_number = app(GeneratePatientNumberAction::class)->handle();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'gender' => Gender::class,
            'blood_group' => BloodGroup::class,
        ];
    }

    protected static function newFactory(): PatientFactory
    {
        return PatientFactory::new();
    }

    /**
     * @return Attribute<string, never>
     */
    protected function fullName(): Attribute
    {
        return Attribute::get(fn (): string => trim(sprintf('%s %s', $this->first_name, $this->last_name)));
    }

    /**
     * Filter patients by name, dossier number, phone, or email.
     *
     * @param  Builder<Patient>  $query
     */
    public function scopeSearch(Builder $query, string $term): void
    {
        $term = trim($term);

        if ($term === '') {
            return;
        }

        $like = '%'.$term.'%';

        $query->where(function (Builder $query) use ($like): void {
            $query->where('first_name', 'like', $like)
                ->orWhere('last_name', 'like', $like)
                ->orWhere('patient_number', 'like', $like)
                ->orWhere('phone', 'like', $like)
                ->orWhere('email', 'like', $like);
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<Appointment, $this>
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /**
     * @return HasMany<Encounter, $this>
     */
    public function encounters(): HasMany
    {
        return $this->hasMany(Encounter::class);
    }

    /**
     * @return HasMany<PatientAntecedent, $this>
     */
    public function antecedents(): HasMany
    {
        return $this->hasMany(PatientAntecedent::class);
    }

    /**
     * Vitals and clinical measurements recorded for this patient.
     *
     * @return HasMany<ClinicalObservation, $this>
     */
    public function observations(): HasMany
    {
        return $this->hasMany(ClinicalObservation::class);
    }
}
