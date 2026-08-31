<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A single editable landing-page text (contact details, requirements copy…),
 * managed from the platform admin panel. `locale` is either a landing locale
 * (ar / fr / en) or "*" when the value applies to every language.
 */
class LandingSetting extends Model
{
    public const ALL_LOCALES = '*';

    protected $fillable = [
        'key',
        'locale',
        'value',
    ];
}
