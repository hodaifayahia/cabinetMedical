<?php

namespace Tests\Feature\Uploads;

use App\Configuration\ApplicationSettingRegistry;
use App\Models\CabinetSetting;
use App\Models\Patient;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\ApplicationSettingService;
use App\Services\QrUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class PublicUploadStandalonePageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        config([
            'app.url' => 'http://192.168.1.40:8000',
            'medismart.runtime.lan_listener_status' => 'active',
        ]);
        URL::forceRootUrl('http://192.168.1.40:8000');
        app(ApplicationSettingService::class)->set(
            ApplicationSettingRegistry::CONNECTIVITY_MANUAL_IPV4,
            '192.168.1.40',
        );
    }

    public function test_public_upload_landing_page_is_a_self_contained_document(): void
    {
        $created = app(QrUploadService::class)->create('local', User::factory()->create());
        [$selector] = $this->credentials($created['token']);

        $response = $this->get(route('upload.show', ['selector' => $selector]))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8');
        $this->assertFalse($response->headers->has('Link'));
        $this->assertFalse($response->headers->has('Content-Location'));
        $this->assertFalse($response->headers->has('Refresh'));

        $html = (string) $response->getContent();

        $this->assertStringContainsString('<!doctype html>', strtolower($html));
        $this->assertStringNotContainsString('/build', $html);
        $this->assertStringNotContainsString('/storage', $html);
        $this->assertStringNotContainsString('@vite', strtolower($html));
        $this->assertDoesNotMatchRegularExpression('/<link\b/i', $html);
        $this->assertDoesNotMatchRegularExpression('/<script\b[^>]*\bsrc\s*=/i', $html);
        $this->assertDoesNotMatchRegularExpression('/<(?:img|source|video|audio|iframe|object|embed)\b/i', $html);
        $this->assertDoesNotMatchRegularExpression('/\b(?:src|href)\s*=\s*["\'](?:\/{1,2}|https?:)/i', $html);
        $this->assertDoesNotMatchRegularExpression('/\b(?:on[a-z]+|style)\s*=/i', $html);
        $this->assertStringNotContainsString('javascript:', strtolower($html));

        preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is', $html, $styleBlocks);
        $css = implode("\n", $styleBlocks[1]);
        $this->assertDoesNotMatchRegularExpression('/@(?:import|font-face)\b/i', $css);
        $this->assertDoesNotMatchRegularExpression('/\burl\s*\(/i', $css);

        $this->assertInlineElementsUseTheResponseNonce($response, $html);
    }

    public function test_public_upload_landing_page_has_a_strict_browser_security_policy(): void
    {
        $created = app(QrUploadService::class)->create('local', User::factory()->create());
        [$selector] = $this->credentials($created['token']);

        $response = $this->get(route('upload.show', ['selector' => $selector]))->assertOk();

        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('max-age=0', $cacheControl);
        $response
            ->assertHeader('Pragma', 'no-cache')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->assertHeader('Cross-Origin-Resource-Policy', 'same-origin');

        $permissions = (string) $response->headers->get('Permissions-Policy');
        foreach (['camera=()', 'microphone=()', 'geolocation=()'] as $disabledCapability) {
            $this->assertStringContainsString($disabledCapability, $permissions);
        }

        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertNotSame('', $csp);
        $this->assertSame(["'none'"], $this->cspDirective($csp, 'default-src'));
        $this->assertSame(["'none'"], $this->cspDirective($csp, 'base-uri'));
        $this->assertSame(["'none'"], $this->cspDirective($csp, 'object-src'));
        $this->assertSame(["'none'"], $this->cspDirective($csp, 'frame-ancestors'));
        $this->assertSame(["'none'"], $this->cspDirective($csp, 'script-src-attr'));
        $this->assertSame(["'none'"], $this->cspDirective($csp, 'style-src-attr'));
        $this->assertSame(["'self'"], $this->cspDirective($csp, 'connect-src'));
        $this->assertSame(["'self'"], $this->cspDirective($csp, 'form-action'));
        $this->assertStringNotContainsString("'unsafe-inline'", $csp);
        $this->assertStringNotContainsString("'unsafe-eval'", $csp);
    }

    public function test_page_embeds_only_public_selector_and_safely_escaped_clinic_identity(): void
    {
        $clinicName = 'Clinique Etoile & Soins';
        $clinicCity = 'Oran</script><script>window.__medismart_xss = true</script>';
        $clinicPhone = '+213 555 12 34 56';
        Storage::disk('public')->put(
            'clinic-identity/should-not-be-requested.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        );
        CabinetSetting::current()->forceFill([
            'name' => $clinicName,
            'city' => $clinicCity,
            'phone' => $clinicPhone,
            'logo_path' => 'clinic-identity/should-not-be-requested.svg',
        ])->save();

        $user = User::factory()->create();
        $patient = Patient::factory()->create([
            'created_by' => $user->getKey(),
            'first_name' => 'HighlyPrivateFirstName',
            'last_name' => 'HighlyPrivateLastName',
        ]);
        $created = app(QrUploadService::class)->create('local', $user, $patient);
        [$selector, $verifier] = $this->credentials($created['token']);

        $response = $this->get(route('upload.show', ['selector' => $selector]))->assertOk();
        $html = (string) $response->getContent();

        $this->assertStringContainsString($selector, $html);
        $response->assertSeeText($clinicName)->assertSeeText($clinicPhone);
        $this->assertStringContainsString('Oran', $html);
        $this->assertStringNotContainsString('</script><script>', $html);
        $this->assertStringNotContainsString('/storage', $html);
        $this->assertStringNotContainsString('HighlyPrivateFirstName', $html);
        $this->assertStringNotContainsString('HighlyPrivateLastName', $html);
        $this->assertStringNotContainsString($verifier, $html);

        $this->assertInlineElementsUseTheResponseNonce($response, $html);
    }

    public function test_fetch_workflow_receives_json_for_authorize_upload_and_complete(): void
    {
        $created = app(QrUploadService::class)->create('local', User::factory()->create());
        [$selector, $verifier] = $this->credentials($created['token']);

        $this->postJson(route('upload.session', ['selector' => $selector]), [
            'verifier' => $verifier,
        ])->assertOk()
            ->assertJsonPath('status', UploadSession::STATUS_PENDING)
            ->assertJsonPath('files', [])
            ->assertJsonMissingPath('patient_id');

        $upload = $this->withHeader('Accept', 'application/json')->post(
            route('upload.files.store', ['selector' => $selector]),
            [
                'verifier' => $verifier,
                'files' => [$this->pdf('standalone-upload.pdf')],
            ],
        );
        $this->assertContains($upload->getStatusCode(), [200, 201]);
        $upload->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('session.status', UploadSession::STATUS_UPLOADING)
            ->assertJsonCount(1, 'session.files')
            ->assertJsonPath('session.files.0.name', 'standalone-upload.pdf')
            ->assertJsonMissingPath('session.patient_id');
        $this->assertStringNotContainsString($verifier, (string) $upload->getContent());

        $this->withHeader('Accept', 'application/json')->post(
            route('upload.complete', ['selector' => $selector]),
            ['verifier' => $verifier],
        )->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('status', UploadSession::STATUS_COMPLETED)
            ->assertJsonMissingPath('patient_id');
    }

    /** @param TestResponse<Response> $response */
    private function assertInlineElementsUseTheResponseNonce(TestResponse $response, string $html): void
    {
        $csp = (string) $response->headers->get('Content-Security-Policy');
        $scriptSources = $this->cspDirective($csp, 'script-src');
        $styleSources = $this->cspDirective($csp, 'style-src');

        $this->assertCount(1, $scriptSources);
        $this->assertCount(1, $styleSources);
        $this->assertMatchesRegularExpression("/\\A'nonce-[A-Za-z0-9+\/_=-]+'\\z/", $scriptSources[0]);
        $this->assertMatchesRegularExpression("/\\A'nonce-[A-Za-z0-9+\/_=-]+'\\z/", $styleSources[0]);

        $scriptNonce = substr($scriptSources[0], 7, -1);
        $styleNonce = substr($styleSources[0], 7, -1);

        preg_match_all('/<script\b([^>]*)>/i', $html, $scriptTags);
        preg_match_all('/<style\b([^>]*)>/i', $html, $styleTags);
        $this->assertNotEmpty($scriptTags[1]);
        $this->assertNotEmpty($styleTags[1]);

        foreach ($scriptTags[1] as $attributes) {
            $this->assertDoesNotMatchRegularExpression('/\bsrc\s*=/i', $attributes);
            $this->assertMatchesRegularExpression(
                '/\bnonce\s*=\s*["\']'.preg_quote($scriptNonce, '/').'["\']/i',
                $attributes,
            );
        }

        foreach ($styleTags[1] as $attributes) {
            $this->assertMatchesRegularExpression(
                '/\bnonce\s*=\s*["\']'.preg_quote($styleNonce, '/').'["\']/i',
                $attributes,
            );
        }
    }

    /** @return list<string> */
    private function cspDirective(string $policy, string $name): array
    {
        foreach (explode(';', $policy) as $rawDirective) {
            $parts = preg_split('/\s+/', trim($rawDirective)) ?: [];
            if (($parts[0] ?? null) === $name) {
                return array_slice($parts, 1);
            }
        }

        $this->fail("Missing Content-Security-Policy directive: {$name}");
    }

    /** @return array{string, string} */
    private function credentials(string $token): array
    {
        $parts = explode('.', $token, 2);
        $this->assertCount(2, $parts);

        return [$parts[0], $parts[1]];
    }

    private function pdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n",
        );
    }
}
