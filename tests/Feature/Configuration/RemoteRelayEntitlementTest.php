<?php

namespace Tests\Feature\Configuration;

use App\Enums\RoleName;
use App\Models\Patient;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ActivatesSignedLicense;
use Tests\TestCase;

class RemoteRelayEntitlementTest extends TestCase
{
    use ActivatesSignedLicense;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function tearDown(): void
    {
        $this->cleanUpSignedLicenseFeatures();

        parent::tearDown();
    }

    public function test_unlicensed_relay_is_reported_and_rejected_before_provider_availability(): void
    {
        [$administrator, $patient] = $this->administratorAndPatient();

        $this->actingAs($administrator)
            ->get(route('app.configuration.connectivity-backup.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('capabilities.relay_upload.available', false)
                ->where(
                    'capabilities.relay_upload.reason',
                    'La licence active n’autorise pas l’envoi par relais sécurisé.',
                ));

        $this->actingAs($administrator)
            ->from(route('app.configuration.connectivity-backup.edit'))
            ->post(route('app.configuration.connectivity-backup.upload-sessions.store'), [
                'mode' => 'relay',
                'patient_id' => $patient->getKey(),
            ])
            ->assertRedirect(route('app.configuration.connectivity-backup.edit'))
            ->assertSessionHasErrors([
                'mode' => __('The active license does not allow secure relay uploads.'),
            ]);

        $this->assertDatabaseCount('upload_sessions', 0);
    }

    public function test_licensed_relay_remains_unavailable_until_a_provider_is_configured(): void
    {
        $this->activateSignedLicenseFeatures(['remote_relay' => true]);
        [$administrator, $patient] = $this->administratorAndPatient();

        $this->actingAs($administrator)
            ->get(route('app.configuration.connectivity-backup.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('capabilities.relay_upload.available', false)
                ->where(
                    'capabilities.relay_upload.reason',
                    'Aucun service relais central n’est configuré.',
                ));

        $this->actingAs($administrator)
            ->from(route('app.configuration.connectivity-backup.edit'))
            ->post(route('app.configuration.connectivity-backup.upload-sessions.store'), [
                'mode' => 'relay',
                'patient_id' => $patient->getKey(),
            ])
            ->assertRedirect(route('app.configuration.connectivity-backup.edit'))
            ->assertSessionHasErrors([
                'mode' => __('The secure relay is not configured on this installation.'),
            ]);

        $this->assertDatabaseCount('upload_sessions', 0);
    }

    /** @return array{0: User, 1: Patient} */
    private function administratorAndPatient(): array
    {
        $administrator = User::factory()->create();
        $administrator->assignRole(RoleName::ADMINISTRATOR->value);
        $patient = Patient::factory()->create([
            'created_by' => $administrator->getKey(),
        ]);

        return [$administrator, $patient];
    }
}
