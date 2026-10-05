<?php

namespace Tests\Feature\Desktop;

use App\Configuration\ApplicationSettingRegistry;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\ApplicationSettingService;
use App\Services\SessionLockService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SessionLockServiceTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'medismart.security.default_idle_lock_minutes' => 15,
            'medismart.security.maximum_idle_lock_minutes' => 60,
        ]);
        $this->now = CarbonImmutable::parse('2026-09-01T10:00:00Z');
        $this->travelTo($this->now);
        $this->user = User::factory()->create();
    }

    public function test_a_guest_request_is_left_untouched(): void
    {
        $request = $this->request(guest: true);

        $this->service()->synchronizeUser($request);

        $this->assertSame([], array_diff_key($request->session()->all(), ['_token' => true]));
        $this->assertFalse($this->service()->isLocked($request));
    }

    public function test_the_first_request_binds_the_session_to_the_user(): void
    {
        $request = $this->request();

        $this->service()->synchronizeUser($request);

        $session = $request->session();
        $this->assertSame($this->user->getKey(), $session->get(SessionLockService::SESSION_USER_ID));
        $this->assertSame($this->now->timestamp, $session->get(SessionLockService::SESSION_LAST_ACTIVITY_AT));
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9]{40}\z/', $session->get(SessionLockService::SESSION_INSTANCE_ID));
        $this->assertFalse($this->service()->isLocked($request));
    }

    public function test_a_different_user_in_the_same_session_drops_the_previous_lock_state(): void
    {
        $request = $this->request();
        $session = $request->session();
        $session->put([
            SessionLockService::SESSION_USER_ID => $this->user->getKey() + 1000,
            SessionLockService::SESSION_LOCKED_AT => $this->now->timestamp,
            SessionLockService::SESSION_LOCK_REASON => 'manual',
            SessionLockService::SESSION_INTENDED => '/patients',
            SessionLockService::SESSION_INSTANCE_ID => str_repeat('a', 40),
            'auth.password_confirmed_at' => time(),
            '_old_input' => ['pin' => '1234'],
        ]);

        $this->service()->synchronizeUser($request);

        $this->assertSame($this->user->getKey(), $session->get(SessionLockService::SESSION_USER_ID));
        $this->assertFalse($session->has(SessionLockService::SESSION_LOCKED_AT));
        $this->assertFalse($session->has(SessionLockService::SESSION_INTENDED));
        $this->assertFalse($session->has('auth.password_confirmed_at'));
        $this->assertFalse($session->has('_old_input'));
        $this->assertNotSame(str_repeat('a', 40), $session->get(SessionLockService::SESSION_INSTANCE_ID));
    }

    public function test_the_same_user_keeps_a_valid_instance_id_and_repairs_an_invalid_one(): void
    {
        $request = $this->request();
        $this->service()->synchronizeUser($request);
        $instance = $request->session()->get(SessionLockService::SESSION_INSTANCE_ID);

        $this->service()->synchronizeUser($request);
        $this->assertSame($instance, $request->session()->get(SessionLockService::SESSION_INSTANCE_ID));

        $request->session()->put(SessionLockService::SESSION_INSTANCE_ID, 'short');
        $this->service()->synchronizeUser($request);
        $repaired = $request->session()->get(SessionLockService::SESSION_INSTANCE_ID);
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9]{40}\z/', $repaired);
        $this->assertNotSame($instance, $repaired);
    }

    public function test_the_idle_timeout_follows_the_registered_setting(): void
    {
        $this->assertSame(15 * 60, $this->service()->idleTimeoutSeconds());

        app(ApplicationSettingService::class)->set(ApplicationSettingRegistry::SECURITY_IDLE_LOCK_MINUTES, 2);

        $this->assertSame(120, $this->service()->idleTimeoutSeconds());
    }

    public function test_an_active_session_is_not_locked_before_the_timeout(): void
    {
        $request = $this->request();
        $this->service()->synchronizeUser($request);

        $this->travelTo($this->now->addMinutes(14)->addSeconds(59));

        $this->assertFalse($this->service()->lockWhenIdle($request));
        $this->assertFalse($this->service()->isLocked($request));
        $this->assertSame(1, $this->service()->remainingSeconds($request));
    }

    public function test_an_idle_session_locks_and_remembers_the_safe_page(): void
    {
        $request = $this->request(uri: '/patients?page=2');
        $this->service()->synchronizeUser($request);

        $this->travelTo($this->now->addMinutes(15));

        $this->assertTrue($this->service()->lockWhenIdle($request));
        $session = $request->session();
        $this->assertTrue($this->service()->isLocked($request));
        $this->assertSame('idle', $session->get(SessionLockService::SESSION_LOCK_REASON));
        $this->assertSame('/patients?page=2', $session->get(SessionLockService::SESSION_INTENDED));
        $this->assertSame(0, $this->service()->remainingSeconds($request));
        $this->assertDatabaseHas('audit_logs', ['action' => 'security.session_locked']);
    }

    public function test_an_idle_lock_on_an_unsafe_request_does_not_remember_its_uri(): void
    {
        $request = $this->request(method: 'POST', uri: '/patients');
        $this->service()->synchronizeUser($request);
        $this->travelTo($this->now->addHour());

        $this->assertTrue($this->service()->lockWhenIdle($request));
        $this->assertFalse($request->session()->has(SessionLockService::SESSION_INTENDED));
    }

    public function test_a_tampered_future_activity_timestamp_locks_immediately(): void
    {
        $request = $this->request();
        $this->service()->synchronizeUser($request);
        $request->session()->put(SessionLockService::SESSION_LAST_ACTIVITY_AT, $this->now->addDay()->timestamp);

        $this->assertSame(0, $this->service()->remainingSeconds($request));
        $this->assertTrue($this->service()->lockWhenIdle($request));
    }

    public function test_a_non_numeric_activity_timestamp_is_treated_as_idle(): void
    {
        $request = $this->request();
        $this->service()->synchronizeUser($request);
        $request->session()->put(SessionLockService::SESSION_LAST_ACTIVITY_AT, 'yesterday');

        $this->assertTrue($this->service()->lockWhenIdle($request));
    }

    public function test_a_numeric_string_timestamp_is_accepted(): void
    {
        $request = $this->request();
        $this->service()->synchronizeUser($request);
        $request->session()->put(SessionLockService::SESSION_LAST_ACTIVITY_AT, (string) $this->now->timestamp);

        $this->assertSame(900, $this->service()->remainingSeconds($request));
    }

    public function test_an_already_locked_session_is_not_locked_twice(): void
    {
        $request = $this->request();

        $this->assertTrue($this->service()->lock($request, 'manual'));
        $this->assertFalse($this->service()->lock($request, 'manual'));
        $this->assertFalse($this->service()->lockWhenIdle($request));
        $this->assertSame(1, AuditLog::query()->where('action', 'security.session_locked')->count());
    }

    public function test_unknown_lock_reasons_are_recorded_as_manual(): void
    {
        $request = $this->request();

        $this->service()->lock($request, 'something-else');

        $this->assertSame('manual', $request->session()->get(SessionLockService::SESSION_LOCK_REASON));
    }

    public function test_locking_drops_password_confirmation_and_flashed_input(): void
    {
        $request = $this->request();
        $request->session()->put(['auth.password_confirmed_at' => time(), '_old_input' => ['x' => 1], 'errors' => 'e']);

        $this->service()->lock($request, 'manual', '/agenda');

        $this->assertFalse($request->session()->has('auth.password_confirmed_at'));
        $this->assertFalse($request->session()->has('_old_input'));
        $this->assertFalse($request->session()->has('errors'));
        $this->assertSame('/agenda', $request->session()->get(SessionLockService::SESSION_INTENDED));
    }

    public function test_a_guest_cannot_be_locked(): void
    {
        $request = $this->request(guest: true);

        $this->assertFalse($this->service()->lock($request, 'manual'));
        $this->assertFalse($this->service()->isLocked($request));
    }

    public function test_touch_refreshes_activity_only_while_unlocked(): void
    {
        $request = $this->request();
        $this->service()->synchronizeUser($request);

        $this->travelTo($this->now->addMinutes(10));
        $this->service()->touch($request);
        $this->assertSame($this->now->addMinutes(10)->timestamp, $request->session()->get(SessionLockService::SESSION_LAST_ACTIVITY_AT));

        $this->service()->lock($request, 'manual');
        $this->travelTo($this->now->addMinutes(20));
        $this->service()->touch($request);
        $this->assertSame($this->now->addMinutes(10)->timestamp, $request->session()->get(SessionLockService::SESSION_LAST_ACTIVITY_AT));
    }

    public function test_unlocking_returns_the_intended_page_and_rotates_the_session(): void
    {
        $request = $this->request();
        $this->service()->lock($request, 'idle', '/agenda?day=2026-09-01');
        $instance = $request->session()->get(SessionLockService::SESSION_INSTANCE_ID);
        $sessionId = $request->session()->getId();
        $token = $request->session()->token();
        $this->travelTo($this->now->addMinutes(5));

        $destination = $this->service()->unlock($request, 'password');

        $session = $request->session();
        $this->assertSame('/agenda?day=2026-09-01', $destination);
        $this->assertFalse($this->service()->isLocked($request));
        $this->assertFalse($session->has(SessionLockService::SESSION_INTENDED));
        $this->assertFalse($session->has(SessionLockService::SESSION_LOCK_REASON));
        $this->assertNotSame($instance, $session->get(SessionLockService::SESSION_INSTANCE_ID));
        $this->assertNotSame($sessionId, $session->getId());
        $this->assertNotSame($token, $session->token());
        $this->assertSame($this->now->addMinutes(5)->timestamp, $session->get(SessionLockService::SESSION_LAST_ACTIVITY_AT));
        $this->assertDatabaseHas('audit_logs', ['action' => 'security.session_unlocked']);
        $this->assertSame(
            'password',
            AuditLog::query()->where('action', 'security.session_unlocked')->firstOrFail()->metadata['method'] ?? null,
        );
    }

    public function test_unlocking_without_an_intended_page_goes_to_the_dashboard(): void
    {
        $request = $this->request();
        $this->service()->lock($request, 'manual');

        $this->assertSame(route('dashboard', absolute: false), $this->service()->unlock($request, 'pin'));
    }

    public function test_an_earlier_intended_page_is_not_overwritten(): void
    {
        $request = $this->request();

        $this->service()->rememberIntended($request, '/first');
        $this->service()->rememberIntended($request, '/second');

        $this->assertSame('/first', $request->session()->get(SessionLockService::SESSION_INTENDED));
    }

    /** @return array<string, array{string|null}> */
    public static function unsafeIntendedDestinations(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'relative' => ['patients'],
            'absolute url' => ['https://evil.example/patients'],
            'protocol relative' => ['//evil.example'],
            'backslash authority' => ['/\\evil.example'],
            'embedded backslash' => ['/patients\\..'],
            'control character' => ["/patients\n"],
            'lock page' => ['/session/unlock'],
            'too long' => ['/'.str_repeat('a', 2048)],
        ];
    }

    #[DataProvider('unsafeIntendedDestinations')]
    public function test_unsafe_intended_destinations_are_never_remembered(?string $intended): void
    {
        $request = $this->request();

        $this->service()->rememberIntended($request, $intended);

        $this->assertFalse($request->session()->has(SessionLockService::SESSION_INTENDED));
    }

    public function test_a_tampered_stored_destination_falls_back_to_the_dashboard_on_unlock(): void
    {
        $request = $this->request();
        $this->service()->lock($request, 'manual');
        $request->session()->put(SessionLockService::SESSION_INTENDED, '//evil.example');

        $this->assertSame(route('dashboard', absolute: false), $this->service()->unlock($request, 'password'));
    }

    public function test_the_instance_id_match_is_exact(): void
    {
        $request = $this->request();
        $current = $this->service()->currentInstanceId($request);

        $this->assertSame(40, strlen($current));
        $this->assertTrue($this->service()->matchesCurrentInstance($request, $current));
        $this->assertFalse($this->service()->matchesCurrentInstance($request, strrev($current)));
        $this->assertFalse($this->service()->matchesCurrentInstance($request, substr($current, 0, 39)));
        $this->assertFalse($this->service()->matchesCurrentInstance($request, null));
        $this->assertFalse($this->service()->matchesCurrentInstance($request, ['id' => $current]));
        $this->assertFalse($this->service()->matchesCurrentInstance($request, str_repeat('!', 40)));
    }

    public function test_a_guest_has_no_instance_id(): void
    {
        $request = $this->request(guest: true);

        $this->assertSame('', $this->service()->currentInstanceId($request));
        $this->assertFalse($this->service()->matchesCurrentInstance($request, str_repeat('a', 40)));
    }

    private function service(): SessionLockService
    {
        return app(SessionLockService::class);
    }

    private function request(bool $guest = false, string $method = 'GET', string $uri = '/dashboard'): Request
    {
        $user = $guest ? null : $this->user;
        $request = Request::create($uri, $method);
        /** @var Session $session */
        $session = app('session')->driver('array');
        $session->flush();
        $session->start();
        $request->setLaravelSession($session);
        $request->setUserResolver(static fn (): ?User => $user);

        return $request;
    }
}
