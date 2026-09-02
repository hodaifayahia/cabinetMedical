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

    /**
     * The contact block shown in the "Nous contacter" footer and in the
     * requirements section. These rows are materialised on a fresh install
     * and whenever a platform admin opens the landing texts screen, so the
     * fields are always there to edit instead of having to be guessed.
     *
     * The values match the frontend's built-in fallbacks, so creating them
     * changes nothing on the public page.
     *
     * @var list<array{key: string, locale: string, value: string}>
     */
    public const CONTACT_DEFAULTS = [
        // Phone and e-mail read the same in every language, so they are
        // stored once under the "all locales" bucket.
        ['key' => 'contact_phone', 'locale' => self::ALL_LOCALES, 'value' => '+213 (0) 00 00 00 00'],
        ['key' => 'contact_email', 'locale' => self::ALL_LOCALES, 'value' => 'contact@drclick.dz'],
        // Opening hours are prose, so they are stored per language.
        ['key' => 'contact_hours', 'locale' => 'ar', 'value' => 'من الأحد إلى الخميس، 9:00 – 17:00'],
        ['key' => 'contact_hours', 'locale' => 'fr', 'value' => 'Dimanche à jeudi, 9h00 – 17h00'],
        ['key' => 'contact_hours', 'locale' => 'en', 'value' => 'Sunday to Thursday, 9:00 – 17:00'],
    ];

    protected $fillable = [
        'key',
        'locale',
        'value',
    ];

    /**
     * Create any missing contact row, leaving every existing value untouched.
     *
     * Idempotent by design: safe to run on every admin page load and on a
     * reseed of a live install, and an admin's edits always survive it.
     */
    public static function ensureContactDefaults(): void
    {
        foreach (self::CONTACT_DEFAULTS as $default) {
            self::query()->firstOrCreate(
                ['key' => $default['key'], 'locale' => $default['locale']],
                ['value' => $default['value']],
            );
        }
    }
}
