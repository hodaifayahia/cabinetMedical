<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class NormalContentSecurityPolicyTest extends TestCase
{
    use RefreshDatabase;

    private string $originalHotFile;

    private string $testHotFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalHotFile = Vite::hotFile();
        $this->testHotFile = storage_path('framework/testing/content-security-policy/hot');
        File::ensureDirectoryExists(dirname($this->testHotFile));
        File::delete($this->testHotFile);
        Vite::useHotFile($this->testHotFile);

        Route::get('/_security/csp/plain', static fn () => response(
            '<!doctype html><html><body>CSP test</body></html>',
            200,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        ));
        Route::get('/_security/csp/payment-receipt', static fn () => view('payments.receipt', [
            'branding' => [
                'address_line' => '1 Test Street, Ghardaia',
                'clinic_name' => 'MediSmart Test Clinic',
                'doctor_name' => 'Dr Test',
                'email' => 'clinic@example.test',
                'footer' => 'Test footer',
                'footer_extra_line' => 'Test footer',
                'logo_url' => null,
                'order_number' => 'TEST-1',
                'phone' => '0555000000',
                'receipt_footer' => 'Paid',
                'specialty' => 'General Medicine',
            ],
            'currency' => 'DZD',
            'payment' => [
                'amount' => 1000.0,
                'date_label' => '2026-08-05',
                'id' => 1,
                'is_paid' => true,
                'method' => 'Cash',
                'patient_name' => 'Test Patient',
                'patient_number' => 'P-1',
                'service' => 'Consultation',
                'user_name' => 'Dr Test',
            ],
        ]));
    }

    protected function tearDown(): void
    {
        Vite::useHotFile($this->originalHotFile);
        File::delete($this->testHotFile);

        parent::tearDown();
    }

    public function test_production_inertia_document_nonces_every_executable_or_style_element(): void
    {
        $onlyOfficeOrigin = 'http://127.0.0.1:8088';
        config([
            'app.env' => 'production',
            'onlyoffice.url' => $onlyOfficeOrigin,
        ]);

        $response = $this->get('/')->assertOk();
        $policy = $this->policy($response);
        $nonce = $this->nonce($policy);

        $this->assertSame(["'none'"], $this->directive($policy, 'default-src'));
        $this->assertSame(["'none'"], $this->directive($policy, 'base-uri'));
        $this->assertSame(["'self'", $onlyOfficeOrigin], $this->directive($policy, 'connect-src'));
        $this->assertSame(["'self'"], $this->directive($policy, 'form-action'));
        $this->assertSame(["'none'"], $this->directive($policy, 'frame-ancestors'));
        $this->assertSame([$onlyOfficeOrigin], $this->directive($policy, 'frame-src'));
        $this->assertSame(["'self'", 'data:'], $this->directive($policy, 'font-src'));
        $this->assertSame(["'self'", 'data:', 'blob:'], $this->directive($policy, 'img-src'));
        $this->assertSame(["'self'"], $this->directive($policy, 'manifest-src'));
        $this->assertSame(["'self'", 'blob:'], $this->directive($policy, 'media-src'));
        $this->assertSame(["'none'"], $this->directive($policy, 'object-src'));
        $this->assertSame(
            ["'self'", "'nonce-{$nonce}'", $onlyOfficeOrigin],
            $this->directive($policy, 'script-src'),
        );
        $this->assertSame(["'none'"], $this->directive($policy, 'script-src-attr'));
        $this->assertSame(
            ["'self'", "'nonce-{$nonce}'"],
            $this->directive($policy, 'style-src'),
        );
        $this->assertSame(["'unsafe-inline'"], $this->directive($policy, 'style-src-attr'));
        $this->assertSame(["'self'", 'blob:'], $this->directive($policy, 'worker-src'));
        $this->assertNotContains("'unsafe-inline'", $this->directive($policy, 'script-src'));
        $this->assertNotContains("'unsafe-eval'", $this->directive($policy, 'script-src'));

        $html = (string) $response->getContent();
        $this->assertStringContainsString('@font-face', $html);
        $this->assertMatchesRegularExpression(
            '/<meta\b(?=[^>]*\bproperty=["\']csp-nonce["\'])(?=[^>]*\bnonce=["\']'.preg_quote($nonce, '/').'["\'])[^>]*>/i',
            $html,
        );
        $this->assertTagsUseNonce($html, 'script', $nonce);
        $this->assertTagsUseNonce($html, 'style', $nonce);
        $this->assertProtectedLinksUseNonce($html, $nonce);
    }

    public function test_each_normal_html_response_receives_a_fresh_nonce(): void
    {
        config([
            'app.env' => 'production',
            'onlyoffice.url' => '',
        ]);

        $first = $this->get('/')->assertOk();
        $second = $this->get('/')->assertOk();
        $firstNonce = $this->nonce($this->policy($first));
        $secondNonce = $this->nonce($this->policy($second));

        $this->assertNotSame($firstNonce, $secondNonce);
        $this->assertStringContainsString('nonce="'.$firstNonce.'"', (string) $first->getContent());
        $this->assertStringContainsString('nonce="'.$secondNonce.'"', (string) $second->getContent());
    }

    public function test_filament_documents_allow_the_runtime_sources_the_panel_requires(): void
    {
        config([
            'app.env' => 'production',
            'onlyoffice.url' => '',
        ]);

        $response = $this->get('/admin/login')->assertOk();
        $policy = $this->policy($response);

        $this->assertContains("'unsafe-inline'", $this->directive($policy, 'script-src'));
        $this->assertContains("'unsafe-eval'", $this->directive($policy, 'script-src'));
        $this->assertSame(["'none'"], $this->directive($policy, 'script-src-attr'));
        $this->assertContains("'unsafe-inline'", $this->directive($policy, 'style-src'));
        $this->assertSame(["'unsafe-inline'"], $this->directive($policy, 'style-src-attr'));
        $this->assertStringNotContainsString("'nonce-", implode(' ', $this->directive($policy, 'script-src')));
        $this->assertStringNotContainsString("'nonce-", implode(' ', $this->directive($policy, 'style-src')));
    }

    public function test_only_exact_canonical_loopback_onlyoffice_origins_are_allowed(): void
    {
        config(['app.env' => 'production']);

        foreach ([
            'http://localhost:8088',
            'https://127.0.0.42:8443',
            'http://[::1]:8088',
        ] as $origin) {
            config(['onlyoffice.url' => $origin]);
            $policy = $this->policy($this->get('/')->assertOk());

            $this->assertSame([$origin], $this->directive($policy, 'frame-src'));
            $this->assertContains($origin, $this->directive($policy, 'script-src'));
            $this->assertContains($origin, $this->directive($policy, 'connect-src'));
        }
    }

    public function test_remote_ambiguous_or_non_origin_onlyoffice_values_are_rejected(): void
    {
        config(['app.env' => 'production']);

        foreach ([
            'https://onlyoffice.example.test:8443',
            'http://localhost.example.test:8088',
            'http://user@localhost:8088',
            'http://localhost:8088/',
            'http://localhost:8088/web-apps',
            'http://localhost:8088?mode=edit',
            'http://localhost:8088#editor',
            'HTTP://localhost:8088',
            'ftp://localhost:8088',
            'http://2130706433:8088',
            'http://localhost:0',
            'http://localhost:65536',
        ] as $invalidOrigin) {
            config(['onlyoffice.url' => $invalidOrigin]);
            $policy = $this->policy($this->get('/')->assertOk());

            $this->assertSame(["'none'"], $this->directive($policy, 'frame-src'));
            $this->assertCount(2, $this->directive($policy, 'script-src'));
            $this->assertSame(["'self'"], $this->directive($policy, 'connect-src'));
        }
    }

    public function test_nonproduction_hmr_uses_only_the_validated_hot_origin_and_derived_websocket(): void
    {
        $hotOrigin = 'http://127.0.0.1:5173';
        File::put($this->testHotFile, $hotOrigin."\n");
        config([
            'app.env' => 'testing',
            'onlyoffice.url' => '',
        ]);

        $response = $this->get('/')->assertOk();
        $policy = $this->policy($response);
        $nonce = $this->nonce($policy);

        $this->assertSame(
            ["'self'", "'nonce-{$nonce}'", $hotOrigin],
            $this->directive($policy, 'script-src'),
        );
        $this->assertSame(
            ["'self'", "'nonce-{$nonce}'", $hotOrigin],
            $this->directive($policy, 'style-src'),
        );
        $this->assertSame(
            ["'self'", $hotOrigin, 'ws://127.0.0.1:5173'],
            $this->directive($policy, 'connect-src'),
        );
        $this->assertSame(["'self'", 'data:', $hotOrigin], $this->directive($policy, 'font-src'));
        $this->assertSame(["'self'", 'data:', 'blob:', $hotOrigin], $this->directive($policy, 'img-src'));
        $this->assertSame(["'self'", 'blob:', $hotOrigin], $this->directive($policy, 'worker-src'));
        $this->assertSame(["'none'"], $this->directive($policy, 'frame-src'));

        $html = (string) $response->getContent();
        $this->assertStringContainsString($hotOrigin.'/@vite/client', $html);
        $this->assertTagsUseNonce($html, 'script', $nonce);
    }

    public function test_untrusted_hot_file_values_are_not_added_to_the_policy(): void
    {
        config([
            'app.env' => 'testing',
            'onlyoffice.url' => '',
        ]);

        foreach ([
            'https://vite.example.test:5173',
            'http://127.0.0.1:5173/path',
            "http://127.0.0.1:5173\nhttps://vite.example.test:5173",
        ] as $invalidOrigin) {
            File::put($this->testHotFile, $invalidOrigin);
            $policy = $this->policy($this->get('/_security/csp/plain')->assertOk());

            $this->assertCount(2, $this->directive($policy, 'script-src'));
            $this->assertCount(2, $this->directive($policy, 'style-src'));
            $this->assertSame(["'self'"], $this->directive($policy, 'connect-src'));
        }
    }

    public function test_production_policy_ignores_a_stale_valid_hot_file(): void
    {
        File::put($this->testHotFile, 'http://127.0.0.1:5173');
        config([
            'app.env' => 'production',
            'onlyoffice.url' => '',
        ]);

        $policy = $this->policy($this->get('/_security/csp/plain')->assertOk());

        $this->assertCount(2, $this->directive($policy, 'script-src'));
        $this->assertCount(2, $this->directive($policy, 'style-src'));
        $this->assertSame(["'self'"], $this->directive($policy, 'connect-src'));
        $this->assertStringNotContainsString('127.0.0.1:5173', $policy);
    }

    public function test_oauth_callback_keeps_its_dedicated_nonce_only_document_policy(): void
    {
        $origin = 'http://127.0.0.1:43123';
        config([
            'medismart.runtime.desktop_supervised' => true,
            'medismart.runtime.local_url' => $origin,
            'services.google.redirect' => null,
        ]);

        $response = $this->withServerVariables([
            'HTTP_HOST' => '127.0.0.1:43123',
            'SERVER_NAME' => '127.0.0.1',
            'REMOTE_ADDR' => '127.0.0.1',
            'SERVER_PORT' => 43123,
        ])->get($origin.'/app/configuration/backup/google/callback')->assertStatus(400);
        $policy = $this->policy($response);
        $styleSources = $this->directive($policy, 'style-src');

        $this->assertCount(1, $styleSources);
        $this->assertMatchesRegularExpression("/\\A'nonce-[A-Za-z0-9_-]{43}'\\z/", $styleSources[0]);
        $nonce = substr($styleSources[0], 7, -1);
        $this->assertSame(["'none'"], $this->directive($policy, 'script-src'));
        $this->assertSame(["'none'"], $this->directive($policy, 'script-src-attr'));
        $this->assertSame(["'none'"], $this->directive($policy, 'style-src-attr'));
        $this->assertStringNotContainsString("'unsafe-inline'", $policy);
        $this->assertTagsUseNonce((string) $response->getContent(), 'style', $nonce);
    }

    public function test_payment_print_handlers_are_nonce_bound_instead_of_inline_attributes(): void
    {
        config([
            'app.env' => 'production',
            'onlyoffice.url' => '',
        ]);

        $response = $this->get('/_security/csp/payment-receipt')->assertOk();
        $policy = $this->policy($response);
        $nonce = $this->nonce($policy);
        $html = (string) $response->getContent();

        $this->assertDoesNotMatchRegularExpression('/<[^>]+\son[a-z]+\s*=/i', $html);
        $this->assertStringContainsString("addEventListener('click'", $html);
        $this->assertTagsUseNonce($html, 'script', $nonce);
        $this->assertTagsUseNonce($html, 'style', $nonce);
    }

    public function test_json_responses_do_not_receive_a_document_policy(): void
    {
        $this->getJson('/up')
            ->assertOk()
            ->assertHeaderMissing('Content-Security-Policy');
    }

    /** @param TestResponse<Response> $response */
    private function policy(TestResponse $response): string
    {
        $policy = (string) $response->headers->get('Content-Security-Policy');
        $this->assertNotSame('', $policy);

        return $policy;
    }

    private function nonce(string $policy): string
    {
        $nonceSources = array_values(array_filter(
            $this->directive($policy, 'script-src'),
            static fn (string $source): bool => str_starts_with($source, "'nonce-"),
        ));
        $this->assertCount(1, $nonceSources);
        $this->assertMatchesRegularExpression("/\\A'nonce-[A-Za-z0-9_-]{43}'\\z/", $nonceSources[0]);

        return substr($nonceSources[0], 7, -1);
    }

    /** @return list<string> */
    private function directive(string $policy, string $name): array
    {
        foreach (explode(';', $policy) as $rawDirective) {
            $parts = preg_split('/\s+/', trim($rawDirective)) ?: [];

            if (($parts[0] ?? null) === $name) {
                return array_values(array_slice($parts, 1));
            }
        }

        $this->fail("Missing Content-Security-Policy directive: {$name}");
    }

    private function assertTagsUseNonce(string $html, string $tag, string $nonce): void
    {
        preg_match_all('/<'.preg_quote($tag, '/').'\b([^>]*)>/i', $html, $matches);
        $this->assertNotEmpty($matches[1], "Expected at least one <{$tag}> element.");

        foreach ($matches[1] as $attributes) {
            $this->assertMatchesRegularExpression(
                '/\bnonce\s*=\s*["\']'.preg_quote($nonce, '/').'["\']/i',
                $attributes,
            );
        }
    }

    private function assertProtectedLinksUseNonce(string $html, string $nonce): void
    {
        preg_match_all('/<link\b([^>]*)>/i', $html, $matches);
        $protectedLinkCount = 0;

        foreach ($matches[1] as $attributes) {
            if (preg_match('/\brel\s*=\s*["\'](?:stylesheet|modulepreload|preload)["\']/i', $attributes) !== 1) {
                continue;
            }

            $protectedLinkCount++;
            $this->assertMatchesRegularExpression(
                '/\bnonce\s*=\s*["\']'.preg_quote($nonce, '/').'["\']/i',
                $attributes,
            );
        }

        $this->assertGreaterThan(0, $protectedLinkCount);
    }
}
