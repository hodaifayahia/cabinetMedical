<?php

namespace Tests\Feature\Api\Mobile\Boundaries;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MobileTestHelpers;
use Tests\TestCase;

/**
 * Role boundary: a patient token must never open the staff API. Every
 * endpoint behind cabinet.active.api answers 403 with the
 * patient_token_forbidden reason — never 200, never leaked data.
 */
class PatientCannotAccessStaffApiTest extends TestCase
{
    use MobileTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_staff_read_endpoints_reject_a_patient_token(): void
    {
        Sanctum::actingAs($this->makePatientUser(), ['mobile']);

        $staffReads = [
            '/api/v1/patients',
            '/api/v1/appointments',
            '/api/v1/schedule',
            '/api/v1/sync/appointments',
        ];

        foreach ($staffReads as $endpoint) {
            $this->getJson($endpoint)
                ->assertStatus(403)
                ->assertJsonPath('reason', 'patient_token_forbidden')
                ->assertJsonPath('status', 'forbidden');
        }
    }

    public function test_the_staff_mobile_schedule_write_rejects_a_patient_token(): void
    {
        Sanctum::actingAs($this->makePatientUser(), ['mobile']);

        $this->putJson('/api/v1/mobile/schedule', [
            'days' => [
                ['day_of_week' => 1, 'ranges' => [['starts_at' => '08:00', 'ends_at' => '12:00']]],
            ],
        ])
            ->assertStatus(403)
            ->assertJsonPath('reason', 'patient_token_forbidden');

        $this->assertDatabaseCount('doctor_schedules', 0);
    }

    public function test_the_same_patient_token_still_opens_the_patient_surface(): void
    {
        Sanctum::actingAs($this->makePatientUser(), ['mobile']);

        // Control: the 403s above come from the role gate, not a dead token.
        $this->getJson('/api/v1/my/profile')->assertOk();
    }
}
