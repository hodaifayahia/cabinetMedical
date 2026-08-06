<?php

namespace Tests\Feature\Configuration;

use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\CabinetSetting;
use App\Models\DriveBackupConnection;
use App\Models\GoogleDriveOAuthAttempt;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class GoogleDriveOAuthStateTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN = 'http://127.0.0.1:43123';

    private const REDIRECT_URI = self::ORIGIN.'/app/configuration/backup/google/callback';

    private User $administrator;

    private CabinetSetting $cabinet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->cabinet = CabinetSetting::current();
        $this->administrator = User::factory()->create([
            'cabinet_setting_id' => $this->cabinet->getKey(),
        ]);
        $this->administrator->assignRole(RoleName::ADMINISTRATOR->value);
        $this->actingAs($this->administrator);

        config([
            'services.google.client_id' => 'client-id',
            'services.google.client_secret' => 'client-secret',
            'services.google.redirect' => null,
            'services.google.drive_scope' => 'https://www.googleapis.com/auth/drive.file',
            'medismart.runtime.desktop_supervised' => true,
            'medismart.runtime.local_url' => self::ORIGIN,
            'medismart.runtime.remote_upload_url' => null,
        ]);

        $this->withSession(['auth.password_confirmed_at' => time()]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_prepare_persists_only_hashed_state_and_encrypted_verifier_with_exact_pkce_url(): void
    {
        [$url, $query, $attempt] = $this->prepare();

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        $this->assertSame('client-id', $query['client_id']);
        $this->assertSame(self::REDIRECT_URI, $query['redirect_uri']);
        $this->assertSame('https://www.googleapis.com/auth/drive.file', $query['scope']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('consent', $query['prompt']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/', $query['state']);
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/', $query['code_challenge']);
        $this->assertSame(hash('sha256', $query['state']), $attempt->state_sha256);
        $this->assertSame(self::REDIRECT_URI, $attempt->redirect_uri);
        $this->assertSame($this->cabinet->getKey(), $attempt->cabinet_setting_id);
        $this->assertSame($this->administrator->getKey(), $attempt->actor_id);
        $this->assertSame(GoogleDriveOAuthAttempt::STATUS_PENDING, $attempt->status);

        $verifier = $attempt->encrypted_pkce_verifier;
        $this->assertIsString($verifier);
        $this->assertSame(
            $query['code_challenge'],
            rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
        );
        $rawVerifier = $attempt->getRawOriginal('encrypted_pkce_verifier');
        $this->assertIsString($rawVerifier);
        $this->assertNotSame($verifier, $rawVerifier);
        $this->assertStringNotContainsString($query['state'], json_encode($attempt->getAttributes(), JSON_THROW_ON_ERROR));
        $this->assertFalse(session()->has('google_drive_oauth_state'));
    }

    public function test_unauthenticated_system_browser_callback_consumes_once_and_audits_stored_actor(): void
    {
        [, $query, $attempt] = $this->prepare();
        $verifier = $attempt->encrypted_pkce_verifier;
        $this->assertIsString($verifier);
        Http::fake($this->successfulGoogleResponses());
        auth()->logout();

        $response = $this->googleCallbackRequest([
            'code' => 'authorization-code',
            'state' => $query['state'],
        ]);

        $response
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertSee('Google Drive est connect', false)
            ->assertSee('revenir &agrave; MediSmart', false)
            ->assertDontSee('authorization-code')
            ->assertDontSee($query['state'])
            ->assertDontSee('access-token')
            ->assertDontSee('refresh-token');

        $attempt->refresh();
        $this->assertSame(GoogleDriveOAuthAttempt::STATUS_COMPLETED, $attempt->status);
        $this->assertNotNull($attempt->consumed_at);
        $this->assertNull($attempt->encrypted_pkce_verifier);
        $connection = DriveBackupConnection::query()->sole();
        $this->assertSame('doctor@example.test', $connection->email);
        $this->assertSame('refresh-token', $connection->refresh_token);
        $audit = AuditLog::query()->where('action', 'backup.drive_connected')->sole();
        $this->assertSame($this->administrator->getKey(), $audit->user_id);
        $this->assertSame('installed_app_pkce_s256', $audit->metadata['oauth_profile']);

        Http::assertSent(static function (Request $request) use ($verifier): bool {
            return $request->url() === 'https://oauth2.googleapis.com/token'
                && $request['code'] === 'authorization-code'
                && $request['code_verifier'] === $verifier
                && $request['redirect_uri'] === self::REDIRECT_URI
                && $request['client_secret'] === 'client-secret';
        });
        Http::assertSentCount(2);
    }

    public function test_public_desktop_client_omits_client_secret_during_exchange(): void
    {
        config(['services.google.client_secret' => null]);
        [, $query] = $this->prepare();
        Http::fake($this->successfulGoogleResponses());

        $this->googleCallbackRequest([
            'code' => 'public-client-code',
            'state' => $query['state'],
        ])->assertOk();

        Http::assertSent(static function (Request $request): bool {
            return $request->url() === 'https://oauth2.googleapis.com/token'
                && ! array_key_exists('client_secret', $request->data())
                && is_string($request['code_verifier']);
        });
    }

    public function test_new_connection_rejects_a_token_response_without_a_durable_refresh_grant(): void
    {
        [, $query, $attempt] = $this->prepare();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'short-lived-access-token',
                'expires_in' => 3600,
            ]),
            'https://www.googleapis.com/drive/v3/about*' => Http::response([
                'user' => ['emailAddress' => 'doctor@example.test'],
            ]),
        ]);

        $this->googleCallbackRequest([
            'code' => 'authorization-code',
            'state' => $query['state'],
        ])->assertStatus(400);

        Http::assertSentCount(1);
        $this->assertDatabaseCount('drive_backup_connections', 0);
        $attempt->refresh();
        $this->assertSame(GoogleDriveOAuthAttempt::STATUS_FAILED, $attempt->status);
        $this->assertSame('token_exchange_failed', $attempt->failure_code);
        $this->assertNull($attempt->encrypted_pkce_verifier);
    }

    public function test_reauthorization_preserves_an_existing_refresh_token_when_google_omits_a_new_one(): void
    {
        DriveBackupConnection::query()->create([
            'cabinet_setting_id' => $this->cabinet->getKey(),
            'email' => 'old@example.test',
            'access_token' => 'old-access-token',
            'refresh_token' => 'existing-refresh-token',
            'token_expires_at' => now()->subMinute(),
        ]);
        [, $query] = $this->prepare();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'new-access-token',
                'expires_in' => 3600,
            ]),
            'https://www.googleapis.com/drive/v3/about*' => Http::response([
                'user' => ['emailAddress' => 'new@example.test'],
            ]),
        ]);

        $this->googleCallbackRequest([
            'code' => 'authorization-code',
            'state' => $query['state'],
        ])->assertOk();

        $connection = DriveBackupConnection::query()->sole();
        $this->assertSame('existing-refresh-token', $connection->refresh_token);
        $this->assertSame('new-access-token', $connection->access_token);
        $this->assertSame('new@example.test', $connection->email);
    }

    public function test_missing_or_wrong_state_never_claims_an_attempt_or_calls_google(): void
    {
        [, $query, $attempt] = $this->prepare();
        Http::fake();

        $this->googleCallbackRequest(['code' => 'authorization-code'])->assertStatus(400);
        $this->googleCallbackRequest([
            'code' => 'authorization-code',
            'state' => str_repeat($query['state'][0] === 'a' ? 'b' : 'a', 43),
        ])->assertStatus(400);

        Http::assertNothingSent();
        $this->assertSame(GoogleDriveOAuthAttempt::STATUS_PENDING, $attempt->fresh()->status);
        $this->assertNull($attempt->fresh()->consumed_at);
        $this->assertDatabaseCount('drive_backup_connections', 0);
    }

    public function test_expired_attempt_is_terminal_and_discards_the_verifier_before_exchange(): void
    {
        [, $query, $attempt] = $this->prepare();
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(11));
        Http::fake();

        $this->googleCallbackRequest([
            'code' => 'authorization-code',
            'state' => $query['state'],
        ])->assertStatus(400);

        Http::assertNothingSent();
        $attempt->refresh();
        $this->assertSame(GoogleDriveOAuthAttempt::STATUS_EXPIRED, $attempt->status);
        $this->assertSame('expired', $attempt->failure_code);
        $this->assertNull($attempt->encrypted_pkce_verifier);
    }

    public function test_wrong_origin_is_404_and_does_not_burn_the_valid_attempt(): void
    {
        [, $query, $attempt] = $this->prepare();
        Http::fake();

        $this->withServerVariables([
            'HTTP_HOST' => '127.0.0.1:43124',
            'SERVER_NAME' => '127.0.0.1',
            'SERVER_PORT' => 43124,
            'REMOTE_ADDR' => '127.0.0.1',
        ])->get('http://127.0.0.1:43124/app/configuration/backup/google/callback?'.http_build_query([
            'code' => 'authorization-code',
            'state' => $query['state'],
        ]))->assertNotFound();
        config(['medismart.runtime.desktop_supervised' => false]);
        $this->googleCallbackRequest([
            'code' => 'authorization-code',
            'state' => $query['state'],
        ])->assertNotFound();

        Http::assertNothingSent();
        $this->assertSame(GoogleDriveOAuthAttempt::STATUS_PENDING, $attempt->fresh()->status);
        $this->assertNull($attempt->fresh()->consumed_at);
    }

    public function test_replay_and_already_claimed_race_state_never_exchange_twice(): void
    {
        [, $query, $attempt] = $this->prepare();
        Http::fake($this->successfulGoogleResponses());
        $parameters = [
            'code' => 'authorization-code',
            'state' => $query['state'],
        ];

        $this->googleCallbackRequest($parameters)->assertOk();
        $this->googleCallbackRequest($parameters)->assertStatus(400);
        Http::assertSentCount(2);

        [, $racingQuery, $racingAttempt] = $this->prepare();
        $racingAttempt->update([
            'status' => GoogleDriveOAuthAttempt::STATUS_CLAIMED,
            'consumed_at' => now(),
        ]);
        $this->googleCallbackRequest([
            'code' => 'second-authorization-code',
            'state' => $racingQuery['state'],
        ])->assertStatus(400);

        Http::assertSentCount(2);
        $this->assertSame(GoogleDriveOAuthAttempt::STATUS_COMPLETED, $attempt->fresh()->status);
        $this->assertSame(GoogleDriveOAuthAttempt::STATUS_CLAIMED, $racingAttempt->fresh()->status);
    }

    public function test_callback_revalidates_the_stored_actor_permissions_before_exchange(): void
    {
        [, $query, $attempt] = $this->prepare();
        $this->administrator->syncRoles([]);
        Http::fake();
        auth()->logout();

        $this->googleCallbackRequest([
            'code' => 'authorization-code',
            'state' => $query['state'],
        ])->assertStatus(400);

        Http::assertNothingSent();
        $attempt->refresh();
        $this->assertSame(GoogleDriveOAuthAttempt::STATUS_FAILED, $attempt->status);
        $this->assertSame('authorization_changed', $attempt->failure_code);
        $this->assertNull($attempt->encrypted_pkce_verifier);
        $audit = AuditLog::query()->where('action', 'backup.drive_oauth_failed')->sole();
        $this->assertSame($this->administrator->getKey(), $audit->user_id);
        $this->assertSame('authorization_changed', $audit->metadata['reason_code']);
    }

    public function test_callback_revalidates_the_attempt_cabinet_binding_before_exchange(): void
    {
        [, $query, $attempt] = $this->prepare();
        $otherCabinet = CabinetSetting::query()->create([
            ...CabinetSetting::defaults(),
            'name' => 'Other cabinet',
        ]);
        $this->administrator->update(['cabinet_setting_id' => $otherCabinet->getKey()]);
        Http::fake();

        $this->googleCallbackRequest([
            'code' => 'authorization-code',
            'state' => $query['state'],
        ])->assertStatus(400);

        Http::assertNothingSent();
        $attempt->refresh();
        $this->assertSame(GoogleDriveOAuthAttempt::STATUS_FAILED, $attempt->status);
        $this->assertSame('authorization_changed', $attempt->failure_code);
        $this->assertNull($attempt->encrypted_pkce_verifier);
        $this->assertDatabaseCount('drive_backup_connections', 0);
    }

    public function test_provider_denial_and_token_failure_become_safe_generic_terminal_states(): void
    {
        [, $deniedQuery, $deniedAttempt] = $this->prepare();
        Http::fake();

        $this->googleCallbackRequest([
            'error' => 'access_denied',
            'error_description' => 'sensitive provider detail',
            'state' => $deniedQuery['state'],
        ])->assertStatus(400)
            ->assertDontSee('access_denied')
            ->assertDontSee('sensitive provider detail');
        Http::assertNothingSent();
        $this->assertSame(GoogleDriveOAuthAttempt::STATUS_FAILED, $deniedAttempt->fresh()->status);
        $this->assertSame('provider_denied', $deniedAttempt->fresh()->failure_code);
        $this->assertNull($deniedAttempt->fresh()->encrypted_pkce_verifier);

        [, $failedQuery, $failedAttempt] = $this->prepare();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'error' => 'invalid_grant',
                'error_description' => 'do not expose this detail',
            ], 400),
        ]);
        $this->googleCallbackRequest([
            'code' => 'rejected-code',
            'state' => $failedQuery['state'],
        ])->assertStatus(400)
            ->assertDontSee('invalid_grant')
            ->assertDontSee('do not expose this detail');

        $this->assertSame(GoogleDriveOAuthAttempt::STATUS_FAILED, $failedAttempt->fresh()->status);
        $this->assertSame('token_exchange_failed', $failedAttempt->fresh()->failure_code);
        $this->assertNull($failedAttempt->fresh()->encrypted_pkce_verifier);
        $this->assertDatabaseCount('drive_backup_connections', 0);
    }

    public function test_prepare_fails_closed_when_unsupervised_redirect_mismatched_or_scope_broadened(): void
    {
        foreach ([
            ['medismart.runtime.desktop_supervised' => false],
            ['services.google.redirect' => 'http://127.0.0.1:43124/app/configuration/backup/google/callback'],
            ['services.google.drive_scope' => 'https://www.googleapis.com/auth/drive'],
        ] as $override) {
            config($override);
            $this->localRequest()->postJson(self::ORIGIN.'/app/configuration/backup/google/prepare')
                ->assertStatus(503)
                ->assertExactJson([
                    'message' => 'Impossible de preparer la connexion Google Drive.',
                ])
                ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
            $this->assertDatabaseCount('google_drive_oauth_attempts', 0);

            config([
                'medismart.runtime.desktop_supervised' => true,
                'services.google.redirect' => null,
                'services.google.drive_scope' => 'https://www.googleapis.com/auth/drive.file',
            ]);
        }
    }

    public function test_cleanup_expires_pending_and_abandoned_claims_and_prunes_only_old_terminal_rows(): void
    {
        $createAttempt = function (string $name): GoogleDriveOAuthAttempt {
            return GoogleDriveOAuthAttempt::query()->create([
                'state_sha256' => hash('sha256', $name),
                'encrypted_pkce_verifier' => str_repeat(substr($name, 0, 1), 64),
                'redirect_uri' => self::REDIRECT_URI,
                'cabinet_setting_id' => $this->cabinet->getKey(),
                'actor_id' => $this->administrator->getKey(),
                'status' => GoogleDriveOAuthAttempt::STATUS_PENDING,
                'expires_at' => now()->addMinutes(10),
            ]);
        };

        $pending = $createAttempt('pending');
        $pending->update(['expires_at' => now()->subMinute()]);
        $claimed = $createAttempt('claimed');
        $claimed->update([
            'status' => GoogleDriveOAuthAttempt::STATUS_CLAIMED,
            'consumed_at' => now()->subMinutes(10),
            'expires_at' => now()->subMinutes(6),
        ]);
        $recent = $createAttempt('recent');
        $recent->update([
            'status' => GoogleDriveOAuthAttempt::STATUS_COMPLETED,
            'encrypted_pkce_verifier' => null,
        ]);
        $old = $createAttempt('old');
        $old->update([
            'status' => GoogleDriveOAuthAttempt::STATUS_FAILED,
            'encrypted_pkce_verifier' => null,
            'failed_at' => now()->subDays(8),
        ]);
        DB::table('google_drive_oauth_attempts')
            ->where('id', $old->getKey())
            ->update(['updated_at' => now()->subDays(8)]);

        $this->artisan('medismart:oauth-attempts:prune')->assertSuccessful();

        $this->assertSame(GoogleDriveOAuthAttempt::STATUS_EXPIRED, $pending->fresh()->status);
        $this->assertNull($pending->fresh()->encrypted_pkce_verifier);
        $this->assertSame('claim_timeout', $claimed->fresh()->failure_code);
        $this->assertNull($claimed->fresh()->encrypted_pkce_verifier);
        $this->assertNotNull($recent->fresh());
        $this->assertNull($old->fresh());
    }

    /**
     * @return array{0: string, 1: array<string, string>, 2: GoogleDriveOAuthAttempt}
     */
    private function prepare(): array
    {
        $response = $this->localRequest()
            ->postJson(self::ORIGIN.'/app/configuration/backup/google/prepare')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertJsonStructure(['authorization_url']);
        $json = $response->json();
        $this->assertIsArray($json);
        $this->assertSame(['authorization_url'], array_keys($json));
        $url = $json['authorization_url'];
        $this->assertIsString($url);
        $queryString = parse_url($url, PHP_URL_QUERY);
        $this->assertIsString($queryString);
        parse_str($queryString, $query);
        $this->assertIsArray($query);

        /** @var array<string, string> $query */
        return [
            $url,
            $query,
            GoogleDriveOAuthAttempt::query()
                ->where('state_sha256', hash('sha256', $query['state']))
                ->firstOrFail(),
        ];
    }

    /** @param array<string, string> $parameters */
    private function googleCallbackRequest(array $parameters): TestResponse
    {
        return $this->localRequest()->get(
            self::ORIGIN.'/app/configuration/backup/google/callback?'.http_build_query($parameters),
        );
    }

    private function localRequest(): self
    {
        return $this->withServerVariables([
            'HTTP_HOST' => '127.0.0.1:43123',
            'SERVER_NAME' => '127.0.0.1',
            'REMOTE_ADDR' => '127.0.0.1',
            'SERVER_PORT' => 43123,
        ]);
    }

    /** @return array<string, Response> */
    private function successfulGoogleResponses(): array
    {
        return [
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'access-token',
                'refresh_token' => 'refresh-token',
                'expires_in' => 3600,
            ]),
            'https://www.googleapis.com/drive/v3/about*' => Http::response([
                'user' => ['emailAddress' => 'doctor@example.test'],
            ]),
        ];
    }
}
