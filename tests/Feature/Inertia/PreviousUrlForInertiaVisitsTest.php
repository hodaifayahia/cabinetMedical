<?php

namespace Tests\Feature\Inertia;

use App\Http\Middleware\MarkJsonOnlyEndpointsAsXhr;
use App\Http\Middleware\RecordInertiaPreviousUrl;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Inertia as InertiaFacade;
use Tests\TestCase;

/**
 * Regression guard for actions that worked but appeared to fail.
 *
 * The Inertia client sends `X-Requested-With: XMLHttpRequest`, so
 * StartSession::storeCurrentUrl() skipped every SPA navigation and the session's
 * previous URL stayed frozen at the last full page load — the dashboard, after
 * signing in. With `Referrer-Policy: no-referrer` leaving url()->previous() no
 * referrer to prefer, that stale value is what back() returned. Confirming an
 * appointment did its work and then dropped the user on the dashboard, which
 * read as the button having done nothing.
 *
 * @see RecordInertiaPreviousUrl
 */
class PreviousUrlForInertiaVisitsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Headers the Inertia client actually sends on a visit.
     *
     * The asset version has to match, or Inertia answers 409 telling the client
     * to hard-reload — which is not a page visit and must not be recorded.
     *
     * @return array<string, string>
     */
    private function inertiaHeaders(): array
    {
        return [
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
            'X-Inertia-Version' => (string) InertiaFacade::getVersion(),
        ];
    }

    public function test_an_inertia_page_visit_becomes_the_previous_url(): void
    {
        $user = User::factory()->create();

        // The full page load the session starts from.
        $this->actingAs($user)->get('/dashboard');
        $this->assertStringContainsString('/dashboard', (string) session('_previous.url'));

        // An SPA navigation away from it. `settings/profile` needs no cabinet
        // permission, so this tests the middleware and not an authorisation gate.
        $this->actingAs($user)->get('/settings/profile', $this->inertiaHeaders())->assertSuccessful();

        $this->assertStringContainsString(
            '/settings/profile',
            (string) session('_previous.url'),
            'an Inertia visit was not recorded, so back() would still return the dashboard',
        );
    }

    public function test_a_json_only_fetch_still_does_not_become_the_previous_url(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard');
        $before = session('_previous.url');

        // No X-Inertia header: a background fetch, not a page visit. The new
        // middleware must not undo MarkJsonOnlyEndpointsAsXhr.
        $this->actingAs($user)->get('/passkeys/confirm/options', ['Accept' => 'application/json']);

        $this->assertSame(
            $before,
            session('_previous.url'),
            'a JSON-only fetch was recorded as the previous URL',
        );
    }

    public function test_a_redirect_is_not_recorded_as_somewhere_to_return_to(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard');
        $before = session('_previous.url');

        // `/home` redirects; sending the user back to a redirect would bounce
        // them somewhere they never chose to be.
        $this->actingAs($user)->get('/home', $this->inertiaHeaders());

        $this->assertSame(
            $before,
            session('_previous.url'),
            'a redirect response was recorded as the previous URL',
        );
    }
}
