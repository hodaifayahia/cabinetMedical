<?php

namespace Tests\Feature\Patients;

use App\Enums\Gender;
use App\Models\Cabinet;
use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `patients.gender` is an unconstrained varchar (see the create_patients_table
 * migration), while the model casts it to the Gender enum. Trimming Gender to
 * male/female therefore leaves any pre-existing 'other' / 'undisclosed' row
 * unrepresentable, and a strict enum cast throws ValueError on hydration —
 * which would 500 the patient index, show, and edit pages for that cabinet.
 *
 * The value is retired, not the row. Reading must degrade to null and leave the
 * stored value untouched, so no data is destroyed by a display change.
 */
class LegacyGenderValueTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<array{0: string}>
     */
    public static function retiredGenderValues(): array
    {
        return [
            ['other'],
            ['undisclosed'],
            // Stored under a different case: SQLite's default collation is
            // BINARY, so these are distinct values from the ones above.
            ['Other'],
            ['UNDISCLOSED'],
            // Anything else the column physically allows.
            ['non-binary'],
        ];
    }

    #[DataProvider('retiredGenderValues')]
    public function test_a_retired_gender_value_does_not_crash_hydration(string $stored): void
    {
        $patient = Patient::factory()->create();

        DB::table('patients')->where('id', $patient->getKey())->update(['gender' => $stored]);

        $fresh = Patient::query()->findOrFail($patient->getKey());

        $this->assertNull($fresh->gender, 'a retired value must read as null, not throw');

        // The row itself must be left alone: this is a display concern.
        $this->assertSame(
            $stored,
            DB::table('patients')->where('id', $patient->getKey())->value('gender'),
            'the stored value must not be rewritten by reading it',
        );
    }

    public function test_current_gender_values_still_cast(): void
    {
        $patient = Patient::factory()->create();

        DB::table('patients')->where('id', $patient->getKey())->update(['gender' => 'female']);

        $this->assertSame(Gender::FEMALE, Patient::query()->findOrFail($patient->getKey())->gender);
    }

    public function test_a_patient_index_page_survives_a_retired_value(): void
    {
        $patient = Patient::factory()->create();
        DB::table('patients')->where('id', $patient->getKey())->update(['gender' => 'other']);

        // Hydrating a collection is the path the index page actually takes.
        $this->assertCount(1, Patient::query()->whereKey($patient->getKey())->get());
    }
}
