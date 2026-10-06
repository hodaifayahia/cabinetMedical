<?php

namespace Tests\Feature\Ai;

use App\Enums\AiFeature;
use App\Enums\CabinetStatus;
use App\Enums\RoleName;
use App\Models\Cabinet;
use App\Models\User;
use App\Services\Ai\AiException;
use App\Services\Ai\AiGateway;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A cabinet may keep images and dictation audio on its PC: the AI then
 * receives de-identified text only.
 */
class AiMediaSettingTest extends TestCase
{
    use RefreshDatabase;

    private User $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $cabinet = Cabinet::query()->create([
            'name' => 'Cabinet',
            'status' => CabinetStatus::ACTIVE,
            'activated_at' => now(),
        ]);
        $this->doctor = User::factory()->create(['cabinet_id' => $cabinet->getKey(), 'approved_at' => now()]);
        $this->doctor->assignRole(RoleName::ADMINISTRATOR->value);
        $cabinet->forceFill(['owner_user_id' => $this->doctor->getKey()])->save();
    }

    public function test_images_and_audio_are_allowed_by_default(): void
    {
        $this->assertTrue(app(AiGateway::class)->mediaAllowed($this->doctor));
    }

    public function test_the_doctor_can_keep_images_and_audio_on_the_pc(): void
    {
        $this->actingAs($this->doctor)
            ->put('/app/configuration/online-service/ai', ['ai_media_enabled' => false])
            ->assertRedirect('/app/configuration/online-service');

        $this->assertFalse($this->doctor->cabinet->refresh()->ai_media_enabled);
        $this->assertFalse(app(AiGateway::class)->mediaAllowed($this->doctor->refresh()));
    }

    public function test_an_image_request_is_refused_before_anything_is_sent(): void
    {
        Http::fake();
        $this->doctor->cabinet->forceFill(['ai_media_enabled' => false])->save();

        try {
            app(AiGateway::class)->complete($this->doctor->refresh(), AiFeature::ECG_CHAT, [['role' => 'user', 'content' => 'ECG']], vision: true);
            $this->fail('An image request must be refused.');
        } catch (AiException $exception) {
            $this->assertSame(AiException::DISABLED, $exception->reason);
            $this->assertStringContainsString('images', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_dictation_audio_is_refused_before_anything_is_sent(): void
    {
        Http::fake();
        $this->doctor->cabinet->forceFill(['ai_media_enabled' => false])->save();
        $audio = tempnam(sys_get_temp_dir(), 'dictee');
        file_put_contents($audio, 'audio');

        try {
            app(AiGateway::class)->transcribe($this->doctor->refresh(), $audio, 'audio/webm');
            $this->fail('Audio must be refused.');
        } catch (AiException $exception) {
            $this->assertStringContainsString('voix', $exception->getMessage());
        } finally {
            @unlink($audio);
        }

        Http::assertNothingSent();
    }
}
