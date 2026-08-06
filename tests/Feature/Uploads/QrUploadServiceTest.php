<?php

namespace Tests\Feature\Uploads;

use App\Configuration\ApplicationSettingRegistry;
use App\Models\ApplicationEvent;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\ApplicationSettingService;
use App\Services\QrUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QrUploadServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stores_only_a_hash_of_the_public_upload_token(): void
    {
        $user = User::factory()->create();
        $patient = Patient::factory()->create(['created_by' => $user->getKey()]);
        config(['medismart.runtime.lan_listener_status' => 'active']);
        app(ApplicationSettingService::class)->set(
            ApplicationSettingRegistry::CONNECTIVITY_MANUAL_IPV4,
            '192.168.1.20',
        );

        $result = app(QrUploadService::class)->create('local', $user, $patient);
        $session = $result['session'];
        [$selector, $verifier] = explode('.', $result['token'], 2);

        $this->assertSame($selector, $session->public_selector);
        $this->assertNotSame($result['token'], $session->public_token_hash);
        $this->assertSame(hash('sha256', $result['token']), $session->public_token_hash);
        $this->assertFalse(DB::table('upload_sessions')->where('public_token_hash', $result['token'])->exists());
        $this->assertTrue(app(QrUploadService::class)->resolve($result['token'])?->is($session));
        $this->assertTrue(app(QrUploadService::class)->resolve($selector, $verifier)?->is($session));
        $this->assertStringContainsString('/upload/'.$selector.'#v='.$verifier, (string) $result['url']);
        $this->assertStringNotContainsString($result['token'], (string) $result['url']);

        $audit = AuditLog::query()->where('action', 'upload_session.created')->firstOrFail();
        $this->assertStringNotContainsString($result['token'], json_encode($audit->metadata, JSON_THROW_ON_ERROR));
        $event = ApplicationEvent::query()->where('event', 'UploadSessionCreated')->firstOrFail();
        /** @var array<string, mixed> $context */
        $context = $event->getAttribute('context');
        $this->assertSame((string) $session->getKey(), $context['upload_session_id']);
        $this->assertStringNotContainsString($result['token'], json_encode($context, JSON_THROW_ON_ERROR));
    }

    public function test_expired_and_revoked_tokens_cannot_be_resolved(): void
    {
        $user = User::factory()->create();
        $service = app(QrUploadService::class);
        $expired = $service->create('local', $user, options: ['expires_after_minutes' => 1]);

        $this->travel(2)->minutes();

        $this->assertNull($service->resolve($expired['token']));
        $this->assertSame(UploadSession::STATUS_EXPIRED, $expired['session']->refresh()->status);

        $active = $service->create('remote', $user);
        $service->revoke($active['session'], $user);

        $this->assertNull($service->resolve($active['token']));
        $this->assertSame(UploadSession::STATUS_REVOKED, $active['session']->refresh()->status);
        $this->assertNotSame(hash('sha256', $active['token']), $active['session']->public_token_hash);
        $this->assertTrue(ApplicationEvent::query()->where('event', 'UploadSessionExpired')->exists());
        $this->assertTrue(ApplicationEvent::query()->where('event', 'UploadSessionRevoked')->exists());
    }

    public function test_completing_a_session_invalidates_its_token(): void
    {
        $user = User::factory()->create();
        $service = app(QrUploadService::class);
        $result = $service->create('relay', $user);

        $service->complete($result['session'], $user);

        $this->assertNull($service->resolve($result['token']));
        $this->assertSame(UploadSession::STATUS_COMPLETED, $result['session']->refresh()->status);
        $this->assertNotNull($result['session']->refresh()->completed_at);
        $this->assertNotSame(hash('sha256', $result['token']), $result['session']->public_token_hash);
        $this->assertTrue(ApplicationEvent::query()->where('event', 'UploadSessionCompleted')->exists());
    }

    public function test_session_options_cannot_expand_server_upload_limits_or_mime_types(): void
    {
        config([
            'medismart.uploads.maximum_files' => 3,
            'medismart.uploads.maximum_individual_bytes' => 600,
            'medismart.uploads.maximum_total_bytes' => 1000,
            'medismart.uploads.allowed_mime_types' => ['application/pdf', 'image/jpeg'],
        ]);

        $result = app(QrUploadService::class)->create('local', User::factory()->create(), options: [
            'maximum_files' => 50,
            'maximum_individual_bytes' => 5000,
            'maximum_total_bytes' => 10000,
            'allowed_mime_types' => ['application/pdf', 'text/html'],
        ]);
        $session = $result['session'];

        $this->assertSame(3, $session->maximum_files);
        $this->assertSame(600, $session->maximum_individual_bytes);
        $this->assertSame(1000, $session->maximum_total_bytes);
        $this->assertSame(['application/pdf'], $session->allowed_mime_types);
    }
}
