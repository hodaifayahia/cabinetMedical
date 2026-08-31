<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\MarkJsonOnlyEndpointsAsXhr;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Regression guard for:
 *
 *   "All Inertia requests must receive a valid Inertia response, however a
 *    plain JSON response was received." { "options": { "challenge": ... } }
 *
 * The passkey client fetches its WebAuthn options with `fetch()` and sends no
 * X-Requested-With header, so StartSession::storeCurrentUrl() used to treat
 * that GET as an ordinary page visit and record it as the session's previous
 * URL. Every later back()/validation redirect then sent the Inertia visit to
 * that raw-JSON endpoint.
 *
 * @see MarkJsonOnlyEndpointsAsXhr
 */
class PreviousUrlPoisoningTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<array{0: string}>
     */
    public static function jsonOnlyEndpoints(): array
    {
        return [
            ['/passkeys/confirm/options'],
            ['/.well-known/passkey-endpoints'],
        ];
    }

    #[DataProvider('jsonOnlyEndpoints')]
    public function test_a_json_only_get_does_not_become_the_previous_url(string $uri): void
    {
        $user = User::factory()->create();

        // Land on a real page first, so there is a legitimate previous URL to
        // protect: the bug is not just "records something", it is "overwrites
        // the page the user was actually on".
        $this->actingAs($user)->get('/dashboard');
        $before = session('_previous.url');

        // Exactly what @laravel/passkeys sends: Accept JSON, no X-Requested-With.
        $this->actingAs($user)->get($uri, ['Accept' => 'application/json']);

        $this->assertStringNotContainsString(
            $uri,
            (string) session('_previous.url'),
            "GET {$uri} was recorded as the session's previous URL; a later back() ".
            'redirect would send an Inertia visit to a raw JSON body',
        );

        $this->assertSame(
            $before,
            session('_previous.url'),
            'the previous URL should still point at the last real page',
        );
    }

    public function test_an_ordinary_page_visit_is_still_recorded(): void
    {
        $user = User::factory()->create();

        // The middleware must not suppress previous-URL tracking generally,
        // otherwise back() stops working everywhere.
        $this->actingAs($user)->get('/dashboard');

        $this->assertStringContainsString('/dashboard', (string) session('_previous.url'));
    }
}
