<?php

namespace Tests\Feature\Api\Mobile\Family;

use App\Enums\AppointmentStatus;
use App\Enums\FamilyMemberStatus;
use App\Enums\FamilyRelation;
use App\Models\Appointment;
use App\Models\FamilyMember;
use App\Models\Patient;
use App\Models\User;
use App\Models\Wilaya;
use App\Notifications\Mobile\FamilyLinkRequested;
use App\Notifications\Mobile\FamilyLinkResponded;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MobileTestHelpers;
use Tests\TestCase;

/**
 * MODULE D — family circle: dependent profiles, consented account links,
 * approve/decline by the linked user only, delete rules, notifications.
 */
class FamilyMemberTest extends TestCase
{
    use MobileTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    // --- Dependent profiles -------------------------------------------------

    public function test_patient_can_create_a_dependent_profile(): void
    {
        $owner = $this->makePatientUser();
        Sanctum::actingAs($owner);
        Wilaya::query()->firstOrCreate(['code' => 16], ['name_fr' => 'Alger', 'name_ar' => 'الجزائر']);

        $response = $this->postJson('/api/v1/family-members', [
            'relation' => FamilyRelation::SON->value,
            'first_name' => 'Yacine',
            'last_name' => 'Benali',
            'gender' => 'male',
            'date_of_birth' => '2018-03-14',
            'place_of_birth' => 'Alger',
            'wilaya_code' => 16,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.relation', 'son')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.is_linked', false)
            ->assertJsonPath('data.first_name', 'Yacine')
            ->assertJsonPath('data.date_of_birth', '2018-03-14');

        $this->assertIsInt($response->json('data.age'));

        $this->assertDatabaseHas('family_members', [
            'owner_user_id' => $owner->getKey(),
            'relation' => 'son',
            'status' => 'active',
            'linked_user_id' => null,
            'first_name' => 'Yacine',
        ]);
    }

    public function test_dependent_creation_requires_demographics_and_a_valid_relation(): void
    {
        Sanctum::actingAs($this->makePatientUser());

        $this->postJson('/api/v1/family-members', [
            'relation' => 'cousin',
            'date_of_birth' => now()->addDay()->toDateString(),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['relation', 'first_name', 'last_name', 'gender', 'date_of_birth']);

        $this->assertDatabaseCount('family_members', 0);
    }

    public function test_index_lists_only_the_authenticated_patients_members(): void
    {
        $owner = $this->makePatientUser();
        $other = $this->makePatientUser();

        $mine = FamilyMember::factory()->dependent()->count(2)->create([
            'owner_user_id' => $owner->getKey(),
        ]);
        $foreign = FamilyMember::factory()->dependent()->create([
            'owner_user_id' => $other->getKey(),
        ]);

        Sanctum::actingAs($owner);

        $response = $this->getJson('/api/v1/family-members');

        $response->assertStatus(200)->assertJsonCount(2, 'data');

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertEqualsCanonicalizing($mine->pluck('id')->all(), $ids->all());
        $this->assertNotContains($foreign->getKey(), $ids->all());
        $this->assertArrayHasKey('meta', $response->json());
    }

    public function test_guest_cannot_access_family_members(): void
    {
        $this->getJson('/api/v1/family-members')->assertStatus(401);
    }

    // --- Link requests ------------------------------------------------------

    public function test_link_request_creates_a_pending_row_and_notifies_the_target(): void
    {
        $owner = $this->makePatientUser();
        $target = $this->makePatientUser();
        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/v1/family-members/link', [
            'phone' => $target->phone,
            'relation' => FamilyRelation::MOTHER->value,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.is_linked', true)
            // A pending link never leaks the target account's identity.
            ->assertJsonPath('data.first_name', null);

        $this->assertDatabaseHas('family_members', [
            'owner_user_id' => $owner->getKey(),
            'linked_user_id' => $target->getKey(),
            'status' => 'pending',
            'relation' => 'mother',
        ]);

        $notification = $target->notifications()->first();
        $this->assertNotNull($notification);
        $this->assertSame(FamilyLinkRequested::class, $notification->type);
        $this->assertSame($response->json('data.id'), $notification->data['family_member_id']);
        $this->assertSame($owner->name, $notification->data['owner_name']);
        $this->assertSame('pending', $notification->data['status']);
        $this->assertSame(0, $owner->notifications()->count());
    }

    public function test_patient_cannot_link_their_own_phone(): void
    {
        $owner = $this->makePatientUser();
        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/family-members/link', [
            'phone' => $owner->phone,
            'relation' => FamilyRelation::OTHER->value,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);

        $this->assertDatabaseCount('family_members', 0);
    }

    public function test_patient_cannot_link_a_non_patient_account(): void
    {
        ['cabinet' => $cabinet] = $this->makeListedClinic();
        $staff = User::factory()->create([
            'cabinet_id' => $cabinet->getKey(),
            'phone' => '0551234567',
            'approved_at' => now(),
        ]);

        Sanctum::actingAs($this->makePatientUser());

        $this->postJson('/api/v1/family-members/link', [
            'phone' => $staff->phone,
            'relation' => FamilyRelation::FATHER->value,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);

        $this->assertDatabaseCount('family_members', 0);
    }

    public function test_duplicate_link_is_rejected(): void
    {
        $owner = $this->makePatientUser();
        $target = $this->makePatientUser();

        FamilyMember::factory()->linked()->create([
            'owner_user_id' => $owner->getKey(),
            'linked_user_id' => $target->getKey(),
        ]);

        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/family-members/link', [
            'phone' => $target->phone,
            'relation' => FamilyRelation::WIFE->value,
        ])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'already_linked');

        $this->assertSame(1, FamilyMember::query()->where('owner_user_id', $owner->getKey())->count());
    }

    // --- Responding to a link request --------------------------------------

    public function test_linked_user_can_approve_and_the_owner_is_notified(): void
    {
        $owner = $this->makePatientUser();
        $target = $this->makePatientUser();
        $member = FamilyMember::factory()->linked()->create([
            'owner_user_id' => $owner->getKey(),
            'linked_user_id' => $target->getKey(),
        ]);

        Sanctum::actingAs($target);

        $this->postJson("/api/v1/family-members/{$member->getKey()}/respond", ['action' => 'approve'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'approved');

        $this->assertSame(FamilyMemberStatus::APPROVED, $member->fresh()->status);
        $this->assertTrue($member->fresh()->isUsableForBooking());

        $notification = $owner->notifications()->first();
        $this->assertNotNull($notification);
        $this->assertSame(FamilyLinkResponded::class, $notification->type);
        $this->assertSame($member->getKey(), $notification->data['family_member_id']);
        $this->assertSame($target->name, $notification->data['responder_name']);
        $this->assertSame('approved', $notification->data['status']);
    }

    public function test_linked_user_can_decline(): void
    {
        $owner = $this->makePatientUser();
        $target = $this->makePatientUser();
        $member = FamilyMember::factory()->linked()->create([
            'owner_user_id' => $owner->getKey(),
            'linked_user_id' => $target->getKey(),
        ]);

        Sanctum::actingAs($target);

        $this->postJson("/api/v1/family-members/{$member->getKey()}/respond", ['action' => 'decline'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'declined');

        $fresh = $member->fresh();
        $this->assertSame(FamilyMemberStatus::DECLINED, $fresh->status);
        $this->assertFalse($fresh->isUsableForBooking());

        $notification = $owner->notifications()->first();
        $this->assertNotNull($notification);
        $this->assertSame('declined', $notification->data['status']);
    }

    public function test_only_the_linked_user_may_respond(): void
    {
        $owner = $this->makePatientUser();
        $target = $this->makePatientUser();
        $stranger = $this->makePatientUser();
        $member = FamilyMember::factory()->linked()->create([
            'owner_user_id' => $owner->getKey(),
            'linked_user_id' => $target->getKey(),
        ]);

        // The owner who sent the request cannot self-approve it.
        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/family-members/{$member->getKey()}/respond", ['action' => 'approve'])
            ->assertStatus(403)
            ->assertJsonPath('reason', 'not_owner');

        // Nor can an unrelated patient.
        Sanctum::actingAs($stranger);
        $this->postJson("/api/v1/family-members/{$member->getKey()}/respond", ['action' => 'approve'])
            ->assertStatus(403)
            ->assertJsonPath('reason', 'not_owner');

        $this->assertSame(FamilyMemberStatus::PENDING, $member->fresh()->status);
    }

    public function test_respond_requires_a_pending_row(): void
    {
        $owner = $this->makePatientUser();
        $target = $this->makePatientUser();
        $member = FamilyMember::factory()->linked()->approved()->create([
            'owner_user_id' => $owner->getKey(),
            'linked_user_id' => $target->getKey(),
        ]);

        Sanctum::actingAs($target);

        $this->postJson("/api/v1/family-members/{$member->getKey()}/respond", ['action' => 'decline'])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'link_not_pending');

        $this->assertSame(FamilyMemberStatus::APPROVED, $member->fresh()->status);
    }

    public function test_approved_link_exposes_the_linked_accounts_profile(): void
    {
        $owner = $this->makePatientUser();
        $target = $this->makePatientUser();
        $member = FamilyMember::factory()->linked()->approved()->create([
            'owner_user_id' => $owner->getKey(),
            'linked_user_id' => $target->getKey(),
        ]);

        Sanctum::actingAs($owner);

        $response = $this->getJson('/api/v1/family-members');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.id', $member->getKey())
            ->assertJsonPath('data.0.is_linked', true)
            ->assertJsonPath('data.0.first_name', $target->patientProfile->first_name)
            ->assertJsonPath('data.0.last_name', $target->patientProfile->last_name);
    }

    // --- Booking usability boundary -----------------------------------------

    public function test_booking_is_blocked_while_a_link_is_pending(): void
    {
        ['doctor' => $doctor] = $this->makeListedClinic();
        $owner = $this->makePatientUser();
        $pending = FamilyMember::factory()->linked()->create([
            'owner_user_id' => $owner->getKey(),
            'linked_user_id' => $this->makePatientUser()->getKey(),
        ]);

        $this->assertFalse($pending->isUsableForBooking());

        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/v1/my/appointments', [
            'doctor_id' => $doctor->getKey(),
            'starts_at' => now()->addDays(3)->setTime(10, 0)->toIso8601String(),
            'family_member_id' => $pending->getKey(),
        ]);

        // The booking module must refuse a pending member (403
        // family_member_not_usable once implemented); it must never book.
        $this->assertGreaterThanOrEqual(400, $response->status());
        $this->assertDatabaseCount('appointments', 0);
    }

    // --- Deleting members ---------------------------------------------------

    public function test_owner_can_delete_a_dependent_without_upcoming_appointments(): void
    {
        ['cabinet' => $cabinet] = $this->makeListedClinic();
        $owner = $this->makePatientUser();
        $member = FamilyMember::factory()->dependent()->create([
            'owner_user_id' => $owner->getKey(),
        ]);

        $patient = Patient::factory()->create(['cabinet_id' => $cabinet->getKey()]);

        // A past scheduled appointment and a future cancelled one do not block.
        Appointment::factory()->create([
            'cabinet_id' => $cabinet->getKey(),
            'patient_id' => $patient->getKey(),
            'family_member_id' => $member->getKey(),
            'starts_at' => now()->subDays(3)->setTime(10, 0),
            'ends_at' => now()->subDays(3)->setTime(10, 30),
            'status' => AppointmentStatus::SCHEDULED,
        ]);
        Appointment::factory()->cancelled()->create([
            'cabinet_id' => $cabinet->getKey(),
            'patient_id' => $patient->getKey(),
            'family_member_id' => $member->getKey(),
            'starts_at' => now()->addDays(3)->setTime(10, 0),
            'ends_at' => now()->addDays(3)->setTime(10, 30),
        ]);

        Sanctum::actingAs($owner);

        $this->deleteJson("/api/v1/family-members/{$member->getKey()}")->assertStatus(200);

        $this->assertDatabaseMissing('family_members', ['id' => $member->getKey()]);
    }

    public function test_dependent_with_upcoming_appointment_cannot_be_deleted(): void
    {
        ['cabinet' => $cabinet] = $this->makeListedClinic();
        $owner = $this->makePatientUser();
        $member = FamilyMember::factory()->dependent()->create([
            'owner_user_id' => $owner->getKey(),
        ]);

        Appointment::factory()->create([
            'cabinet_id' => $cabinet->getKey(),
            'patient_id' => Patient::factory()->create(['cabinet_id' => $cabinet->getKey()])->getKey(),
            'family_member_id' => $member->getKey(),
            'starts_at' => now()->addDays(2)->setTime(9, 0),
            'ends_at' => now()->addDays(2)->setTime(9, 30),
            'status' => AppointmentStatus::SCHEDULED,
        ]);

        Sanctum::actingAs($owner);

        $this->deleteJson("/api/v1/family-members/{$member->getKey()}")
            ->assertStatus(422)
            ->assertJsonPath('reason', 'member_has_appointments');

        $this->assertDatabaseHas('family_members', ['id' => $member->getKey()]);
    }

    public function test_linked_member_is_always_deletable(): void
    {
        ['cabinet' => $cabinet] = $this->makeListedClinic();
        $owner = $this->makePatientUser();
        $member = FamilyMember::factory()->linked()->approved()->create([
            'owner_user_id' => $owner->getKey(),
            'linked_user_id' => $this->makePatientUser()->getKey(),
        ]);

        $appointment = Appointment::factory()->create([
            'cabinet_id' => $cabinet->getKey(),
            'patient_id' => Patient::factory()->create(['cabinet_id' => $cabinet->getKey()])->getKey(),
            'family_member_id' => $member->getKey(),
            'starts_at' => now()->addDays(2)->setTime(11, 0),
            'ends_at' => now()->addDays(2)->setTime(11, 30),
            'status' => AppointmentStatus::SCHEDULED,
        ]);

        Sanctum::actingAs($owner);

        $this->deleteJson("/api/v1/family-members/{$member->getKey()}")->assertStatus(200);

        $this->assertDatabaseMissing('family_members', ['id' => $member->getKey()]);
        // Unlinking never destroys the clinic's appointment history.
        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->getKey(),
            'family_member_id' => null,
        ]);
    }

    public function test_only_the_owner_may_delete_a_member(): void
    {
        $owner = $this->makePatientUser();
        $member = FamilyMember::factory()->dependent()->create([
            'owner_user_id' => $owner->getKey(),
        ]);

        Sanctum::actingAs($this->makePatientUser());

        $this->deleteJson("/api/v1/family-members/{$member->getKey()}")
            ->assertStatus(403)
            ->assertJsonPath('reason', 'not_owner');

        $this->assertDatabaseHas('family_members', ['id' => $member->getKey()]);
    }
}
