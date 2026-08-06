<?php

namespace Tests\Feature\Desktop;

use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\DoctorProfile;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Throwable;

class DoctorProfileSpecialtyLockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_a_persisted_specialty_cannot_be_changed_through_a_direct_model_update(): void
    {
        $profile = DoctorProfile::factory()->create([
            'specialty' => 'Cardiology',
            'specialty_code' => 'cardiology',
        ]);

        $this->assertNotNull($profile->specialty_locked_at);

        try {
            $profile->update([
                'specialty' => 'Neurology',
                'specialty_code' => 'neurology',
            ]);

            $this->fail('A locked specialty was changed without the administrative correction workflow.');
        } catch (AuthorizationException) {
            // The model guard is the expected boundary for ordinary writes.
        }

        $profile->refresh();

        $this->assertSame('Cardiology', $profile->specialty);
        $this->assertSame('cardiology', $profile->specialty_code);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'doctor.specialty_corrected',
            'subject_id' => (string) $profile->getKey(),
        ]);
    }

    public function test_only_an_administrator_can_correct_a_locked_specialty_and_the_change_is_audited(): void
    {
        $profile = DoctorProfile::factory()->create([
            'specialty' => 'General Medicine',
            'specialty_code' => 'general_medicine',
        ]);
        $nonAdministrator = User::factory()->create();
        $nonAdministrator->assignRole(RoleName::DOCTOR->value);

        try {
            $profile->correctLockedSpecialty('Pediatrics', 'pediatrics', $nonAdministrator);

            $this->fail('A non-administrator corrected a locked specialty.');
        } catch (AuthorizationException) {
            // Only the explicit administrator path may make this correction.
        }

        $this->assertSame('General Medicine', $profile->refresh()->specialty);

        $administrator = User::factory()->create();
        $administrator->assignRole(RoleName::ADMINISTRATOR->value);
        $profile->correctLockedSpecialty('Pediatrics', 'pediatrics', $administrator);
        $profile->refresh();

        $this->assertSame('Pediatrics', $profile->specialty);
        $this->assertSame('pediatrics', $profile->specialty_code);
        $this->assertNotNull($profile->specialty_locked_at);

        $audit = AuditLog::query()
            ->where('action', 'doctor.specialty_corrected')
            ->where('subject_type', $profile->getMorphClass())
            ->where('subject_id', (string) $profile->getKey())
            ->sole();

        $this->assertSame($administrator->getKey(), $audit->user_id);
        $metadata = $audit->getAttribute('metadata');

        if (! is_array($metadata)) {
            $this->fail('The specialty correction audit metadata was not decoded.');
        }

        $this->assertSame([
            'specialty' => 'General Medicine',
            'specialty_code' => 'general_medicine',
        ], $metadata['previous']);
        $this->assertSame([
            'specialty' => 'Pediatrics',
            'specialty_code' => 'pediatrics',
        ], $metadata['current']);
    }

    public function test_a_failed_audit_insert_rolls_back_the_specialty_correction(): void
    {
        $profile = DoctorProfile::factory()->create([
            'specialty' => 'Cardiology',
            'specialty_code' => 'cardiology',
        ]);
        $administrator = User::factory()->create();
        $administrator->assignRole(RoleName::ADMINISTRATOR->value);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER reject_specialty_correction_audit
            BEFORE INSERT ON audit_logs
            WHEN NEW.action = 'doctor.specialty_corrected'
            BEGIN
                SELECT RAISE(ABORT, 'forced audit failure');
            END
            SQL);

        $failure = null;

        try {
            $profile->correctLockedSpecialty('Neurology', 'neurology', $administrator);
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        $this->assertInstanceOf(QueryException::class, $failure);

        $profile->refresh();

        $this->assertSame('Cardiology', $profile->specialty);
        $this->assertSame('cardiology', $profile->specialty_code);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'doctor.specialty_corrected',
            'subject_id' => (string) $profile->getKey(),
        ]);
    }
}
