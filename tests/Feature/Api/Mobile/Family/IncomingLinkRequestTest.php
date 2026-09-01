<?php

namespace Tests\Feature\Api\Mobile\Family;

use App\Enums\FamilyMemberStatus;
use App\Models\FamilyMember;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\MobileTestHelpers;
use Tests\TestCase;

/**
 * Only the invited account may answer a family link request, so that account
 * must also be able to SEE it. Without the incoming branch on the list the
 * consent flow is un-completable from the app.
 */
class IncomingLinkRequestTest extends TestCase
{
    use MobileTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    #[Test]
    public function the_invited_account_sees_the_pending_request_and_can_respond(): void
    {
        $owner = $this->makePatientUser();
        $invited = $this->makePatientUser();

        $this->actingAs($owner)
            ->postJson('/api/v1/family-members/link', [
                'phone' => $invited->phone,
                'relation' => 'wife',
            ])->assertCreated();

        $response = $this->actingAs($invited)->getJson('/api/v1/family-members')->assertOk();

        $rows = $response->json('data');
        $this->assertCount(1, $rows, 'the invited account must see the request addressed to it');
        $this->assertSame('incoming', $rows[0]['direction']);
        $this->assertTrue($rows[0]['can_respond']);
        $this->assertNotNull($rows[0]['requested_by'], 'the invited account must know who is asking');

        $this->actingAs($invited)
            ->postJson('/api/v1/family-members/'.$rows[0]['id'].'/respond', ['action' => 'approve'])
            ->assertOk();

        $this->assertSame(
            FamilyMemberStatus::APPROVED,
            FamilyMember::query()->find($rows[0]['id'])?->status,
        );
    }

    #[Test]
    public function the_owner_sees_its_own_request_as_outgoing_and_cannot_self_approve(): void
    {
        $owner = $this->makePatientUser();
        $invited = $this->makePatientUser();

        $this->actingAs($owner)->postJson('/api/v1/family-members/link', [
            'phone' => $invited->phone,
            'relation' => 'husband',
        ])->assertCreated();

        $rows = $this->actingAs($owner)->getJson('/api/v1/family-members')->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('outgoing', $rows[0]['direction']);
        $this->assertFalse($rows[0]['can_respond']);
        $this->assertNull($rows[0]['requested_by']);

        $this->actingAs($owner)
            ->postJson('/api/v1/family-members/'.$rows[0]['id'].'/respond', ['action' => 'approve'])
            ->assertForbidden()
            ->assertJsonPath('reason', 'not_owner');
    }

    #[Test]
    public function an_answered_request_leaves_the_invited_accounts_list(): void
    {
        $owner = $this->makePatientUser();
        $invited = $this->makePatientUser();

        $this->actingAs($owner)->postJson('/api/v1/family-members/link', [
            'phone' => $invited->phone,
            'relation' => 'other',
        ])->assertCreated();

        $id = $this->actingAs($invited)->getJson('/api/v1/family-members')->json('data.0.id');

        $this->actingAs($invited)
            ->postJson('/api/v1/family-members/'.$id.'/respond', ['action' => 'decline'])
            ->assertOk();

        // Declined: it is the owner's row again, not the invited account's business.
        $this->assertSame(
            [],
            $this->actingAs($invited)->getJson('/api/v1/family-members')->json('data'),
        );
    }

    #[Test]
    public function an_incoming_request_is_not_bookable_by_the_invited_account(): void
    {
        $owner = $this->makePatientUser();
        $invited = $this->makePatientUser();
        $clinic = $this->makeListedClinic();

        $this->actingAs($owner)->postJson('/api/v1/family-members/link', [
            'phone' => $invited->phone,
            'relation' => 'wife',
        ])->assertCreated();

        $id = $this->actingAs($invited)->getJson('/api/v1/family-members')->json('data.0.id');

        $this->actingAs($invited)
            ->postJson('/api/v1/my/appointments', [
                'doctor_id' => $clinic['doctor']->getKey(),
                'starts_at' => now()->addDays(3)->setTime(10, 0)->toIso8601String(),
                'family_member_id' => $id,
            ])
            ->assertForbidden()
            ->assertJsonPath('reason', 'family_member_not_usable');
    }
}
