<?php

namespace App\Models;

use App\Support\MedicalSpecialtyCatalog;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One entry of the platform-wide medical specialty catalogue.
 *
 * `code` is what doctor profiles store and what the patient app filters on,
 * so it is fixed at creation; the labels can be corrected at any time.
 * `is_active` only decides whether the patient app OFFERS the specialty as a
 * search filter — doctors of an inactive specialty stay findable.
 *
 * @property int $id
 * @property string $code
 * @property string $label_fr
 * @property string|null $label_ar
 * @property bool $is_active
 */
#[Fillable(['code', 'label_fr', 'label_ar', 'is_active'])]
class MedicalSpecialty extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        // The catalogue memoises the table for the request; drop it on every
        // write so the next read (in this request or a test) sees the change.
        $forget = static function (): void {
            app()->forgetInstance(MedicalSpecialtyCatalog::class);
        };

        static::saved($forget);
        static::deleted($forget);
    }
}
