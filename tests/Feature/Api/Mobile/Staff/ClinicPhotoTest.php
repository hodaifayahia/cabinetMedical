<?php

namespace Tests\Feature\Api\Mobile\Staff;

use App\Enums\RoleName;
use App\Models\CabinetPublicProfile;
use App\Models\User;
use App\Support\ClinicPhotos;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MobileTestHelpers;
use Tests\TestCase;

/**
 * Clinic photos are uploaded from the staff app (no more pasted links): each
 * upload is re-encoded to a bounded JPEG on the public disk and takes effect
 * at once; the listing hands patients full links.
 */
class ClinicPhotoTest extends TestCase
{
    use MobileTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('public');
    }

    public function test_an_upload_is_shrunk_stored_and_listed_as_a_full_link(): void
    {
        $clinic = $this->makeListedClinic();
        Sanctum::actingAs($clinic['doctorUser']);

        $response = $this->post('/api/v1/mobile/clinic-profile/photos', [
            'photo' => UploadedFile::fake()->image('entrance.png', 3000, 2000),
        ], ['Accept' => 'application/json'])->assertOk();

        $stored = $this->storedPhotos($clinic['cabinet']->getKey());
        $this->assertCount(1, $stored);
        $this->assertStringStartsWith('cabinet-photos/'.$clinic['cabinet']->getKey().'/', $stored[0]);
        $this->assertStringEndsWith('.jpg', $stored[0]);
        Storage::disk('public')->assertExists($stored[0]);

        [$width, $height] = getimagesizefromstring(Storage::disk('public')->get($stored[0]));
        $this->assertSame(ClinicPhotos::MAX_SIDE, max($width, $height));

        $response->assertJsonPath('data.photos.0', Storage::disk('public')->url($stored[0]));
    }

    public function test_patients_see_the_uploaded_photo_link(): void
    {
        $clinic = $this->makeListedClinic();
        Sanctum::actingAs($clinic['doctorUser']);
        $this->post('/api/v1/mobile/clinic-profile/photos', [
            'photo' => UploadedFile::fake()->image('room.jpg', 800, 600),
        ], ['Accept' => 'application/json'])->assertOk();
        $stored = $this->storedPhotos($clinic['cabinet']->getKey());

        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/clinics/'.$clinic['cabinet']->getKey())
            ->assertOk()
            ->assertJsonPath('data.photos.0', Storage::disk('public')->url($stored[0]));
    }

    public function test_replace_swaps_the_photo_and_deletes_the_old_file(): void
    {
        $clinic = $this->makeListedClinic();
        Sanctum::actingAs($clinic['doctorUser']);
        $this->uploadPhotos(2);
        [$first, $second] = $this->storedPhotos($clinic['cabinet']->getKey());

        $this->post('/api/v1/mobile/clinic-profile/photos', [
            'photo' => UploadedFile::fake()->image('new.jpg', 800, 600),
            'replace' => 0,
        ], ['Accept' => 'application/json'])->assertOk();

        $after = $this->storedPhotos($clinic['cabinet']->getKey());
        $this->assertCount(2, $after);
        $this->assertNotSame($first, $after[0]);
        $this->assertSame($second, $after[1]);
        Storage::disk('public')->assertMissing($first);
    }

    public function test_cover_moves_a_photo_to_the_front_and_remove_deletes_it(): void
    {
        $clinic = $this->makeListedClinic();
        Sanctum::actingAs($clinic['doctorUser']);
        $this->uploadPhotos(3);
        [$a, $b, $c] = $this->storedPhotos($clinic['cabinet']->getKey());

        $this->postJson('/api/v1/mobile/clinic-profile/photos/2/cover')->assertOk();
        $this->assertSame([$c, $a, $b], $this->storedPhotos($clinic['cabinet']->getKey()));

        $this->deleteJson('/api/v1/mobile/clinic-profile/photos/1')->assertOk();
        $this->assertSame([$c, $b], $this->storedPhotos($clinic['cabinet']->getKey()));
        Storage::disk('public')->assertMissing($a);

        $this->deleteJson('/api/v1/mobile/clinic-profile/photos/5')->assertNotFound();
    }

    public function test_at_most_six_photos_and_only_readable_images(): void
    {
        $clinic = $this->makeListedClinic();
        Sanctum::actingAs($clinic['doctorUser']);
        $this->uploadPhotos(ClinicPhotos::MAX_PHOTOS);

        $this->post('/api/v1/mobile/clinic-profile/photos', [
            'photo' => UploadedFile::fake()->image('seventh.jpg', 400, 300),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('photo');

        $this->post('/api/v1/mobile/clinic-profile/photos', [
            'photo' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
            'replace' => 0,
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('photo');
    }

    public function test_saving_the_form_keeps_reorders_or_drops_photos_but_refuses_new_links(): void
    {
        $clinic = $this->makeListedClinic();
        Sanctum::actingAs($clinic['doctorUser']);
        $this->uploadPhotos(2);
        $links = $this->getJson('/api/v1/mobile/clinic-profile')->json('data.photos');
        [$first, $second] = $this->storedPhotos($clinic['cabinet']->getKey());

        // The app sends back the links it was given, in a new order, one dropped.
        $this->putJson('/api/v1/mobile/clinic-profile', ['photos' => [$links[1]]])->assertOk();
        $this->assertSame([$second], $this->storedPhotos($clinic['cabinet']->getKey()));
        Storage::disk('public')->assertMissing($first);

        $this->putJson('/api/v1/mobile/clinic-profile', [
            'photos' => [$links[1], 'https://example.com/someone-elses-photo.jpg'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('photos.1');
    }

    public function test_a_legacy_link_is_still_listed_and_kept(): void
    {
        $clinic = $this->makeListedClinic();
        CabinetPublicProfile::withoutCabinetScope()
            ->where('cabinet_id', $clinic['cabinet']->getKey())
            ->update(['photos' => json_encode(['https://cdn.example.com/old.jpg'])]);
        Sanctum::actingAs($clinic['doctorUser']);

        $this->getJson('/api/v1/mobile/clinic-profile')
            ->assertJsonPath('data.photos.0', 'https://cdn.example.com/old.jpg');

        $this->putJson('/api/v1/mobile/clinic-profile', ['photos' => ['https://cdn.example.com/old.jpg']])
            ->assertOk();
    }

    public function test_an_assistant_cannot_change_photos(): void
    {
        $clinic = $this->makeListedClinic();
        $assistant = User::factory()->create([
            'cabinet_id' => $clinic['cabinet']->getKey(),
            'approved_at' => now(),
        ]);
        $assistant->assignRole(RoleName::ASSISTANT->value);
        Sanctum::actingAs($assistant);

        $this->post('/api/v1/mobile/clinic-profile/photos', [
            'photo' => UploadedFile::fake()->image('x.jpg', 400, 300),
        ], ['Accept' => 'application/json'])->assertForbidden();
    }

    private function uploadPhotos(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->post('/api/v1/mobile/clinic-profile/photos', [
                'photo' => UploadedFile::fake()->image("photo-{$i}.jpg", 800, 600),
            ], ['Accept' => 'application/json'])->assertOk();
        }
    }

    /** @return list<string> */
    private function storedPhotos(int $cabinetId): array
    {
        return array_values(CabinetPublicProfile::withoutCabinetScope()
            ->where('cabinet_id', $cabinetId)
            ->first()?->photos ?? []);
    }
}
