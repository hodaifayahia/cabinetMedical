<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Voice dictation (consultation screen) needs the browser microphone, so app
 * pages allow it for their own origin only; every other powerful feature
 * stays off. The public QR upload pages keep their stricter policy
 * (PublicUploadStandalonePageTest).
 */
final class PermissionsPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_app_pages_allow_the_microphone_for_their_own_origin_only(): void
    {
        $policy = (string) $this->get('/login')->headers->get('Permissions-Policy');

        $this->assertStringContainsString('microphone=(self)', $policy);

        foreach (['camera=()', 'geolocation=()', 'payment=()', 'usb=()'] as $disabled) {
            $this->assertStringContainsString($disabled, $policy);
        }
    }
}
