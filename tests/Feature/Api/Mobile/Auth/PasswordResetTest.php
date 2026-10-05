<?php

namespace Tests\Feature\Api\Mobile\Auth;

use App\Http\Controllers\Api\V1\Mobile\PasswordResetController;
use App\Mail\MobilePasswordResetCodeMail;
use App\Models\MobilePasswordReset;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Support\MobileTestHelpers;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use MobileTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Mail::fake();
    }

    public function test_forgot_by_phone_emails_a_six_digit_code(): void
    {
        $patient = $this->makePatientUser();

        $this->postJson('/api/v1/auth/password/forgot', ['identifier' => $patient->phone])
            ->assertOk()
            ->assertJsonPath('channel', 'email')
            ->assertJsonPath('expires_in_minutes', PasswordResetController::CODE_LIFETIME_MINUTES);

        Mail::assertSent(MobilePasswordResetCodeMail::class, function (MobilePasswordResetCodeMail $mail) use ($patient): bool {
            return $mail->hasTo($patient->email) && preg_match('/^\d{6}$/', $mail->code) === 1;
        });

        $reset = MobilePasswordReset::query()->where('user_id', $patient->getKey())->sole();
        // Only the hash is stored.
        $this->assertNotSame($this->sentCode(), $reset->code_hash);
        $this->assertTrue(Hash::check($this->sentCode(), $reset->code_hash));
    }

    public function test_forgot_matches_a_spaced_or_international_phone(): void
    {
        $patient = $this->makePatientUser();
        $spaced = '+213 '.substr($patient->phone, 1, 3).' '.substr($patient->phone, 4, 2).' '.substr($patient->phone, 6, 2).' '.substr($patient->phone, 8, 2);

        $this->postJson('/api/v1/auth/password/forgot', ['identifier' => $spaced])->assertOk();

        Mail::assertSent(MobilePasswordResetCodeMail::class, fn ($mail): bool => $mail->hasTo($patient->email));
    }

    public function test_forgot_answers_the_same_for_an_unknown_account_and_sends_nothing(): void
    {
        $known = $this->makePatientUser();

        $knownBody = $this->postJson('/api/v1/auth/password/forgot', ['identifier' => $known->phone])->json();
        Mail::fake();
        $unknownBody = $this->postJson('/api/v1/auth/password/forgot', ['identifier' => '0699999999'])
            ->assertOk()
            ->json();

        $this->assertSame($knownBody, $unknownBody);
        Mail::assertNothingSent();
    }

    public function test_an_account_without_email_gets_no_code(): void
    {
        $patient = $this->makePatientUser();
        $patient->forceFill(['email' => null])->save();

        $this->postJson('/api/v1/auth/password/forgot', ['identifier' => $patient->phone])->assertOk();

        Mail::assertNothingSent();
        $this->assertDatabaseMissing('mobile_password_resets', ['user_id' => $patient->getKey()]);
    }

    public function test_a_second_request_inside_the_cooldown_keeps_the_first_code(): void
    {
        $patient = $this->makePatientUser();

        $this->postJson('/api/v1/auth/password/forgot', ['identifier' => $patient->phone])->assertOk();
        $this->postJson('/api/v1/auth/password/forgot', ['identifier' => $patient->phone])->assertOk();

        Mail::assertSentCount(1);

        $this->travel(PasswordResetController::RESEND_COOLDOWN_SECONDS + 1)->seconds();
        $this->postJson('/api/v1/auth/password/forgot', ['identifier' => $patient->phone])->assertOk();

        Mail::assertSentCount(2);
        $this->assertSame(1, MobilePasswordReset::query()->where('user_id', $patient->getKey())->count());
    }

    public function test_reset_with_the_right_code_changes_the_password_and_signs_out_everywhere(): void
    {
        $patient = $this->makePatientUser();
        $patient->createToken('old phone', ['mobile']);

        $this->postJson('/api/v1/auth/password/forgot', ['identifier' => $patient->phone])->assertOk();

        $this->postJson('/api/v1/auth/password/reset', [
            'identifier' => $patient->phone,
            'code' => $this->sentCode(),
            'password' => 'new-secret-123',
        ])->assertOk();

        $patient->refresh();
        $this->assertTrue(Hash::check('new-secret-123', $patient->password));
        $this->assertSame(0, $patient->tokens()->count());
        $this->assertDatabaseMissing('mobile_password_resets', ['user_id' => $patient->getKey()]);

        $this->postJson('/api/v1/auth/login', [
            'identifier' => $patient->phone,
            'password' => 'new-secret-123',
        ])->assertOk();
    }

    public function test_a_used_code_cannot_be_replayed(): void
    {
        $patient = $this->makePatientUser();
        $this->postJson('/api/v1/auth/password/forgot', ['identifier' => $patient->phone]);
        $payload = ['identifier' => $patient->phone, 'code' => $this->sentCode(), 'password' => 'new-secret-123'];

        $this->postJson('/api/v1/auth/password/reset', $payload)->assertOk();

        $this->postJson('/api/v1/auth/password/reset', [...$payload, 'password' => 'another-one-456'])
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'reset_code_expired');
    }

    public function test_a_wrong_code_is_rejected_and_five_wrong_codes_kill_it(): void
    {
        $patient = $this->makePatientUser();
        $this->postJson('/api/v1/auth/password/forgot', ['identifier' => $patient->phone]);
        $code = $this->sentCode();
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 1; $i < PasswordResetController::MAX_ATTEMPTS; $i++) {
            $this->postJson('/api/v1/auth/password/reset', [
                'identifier' => $patient->phone,
                'code' => $wrong,
                'password' => 'new-secret-123',
            ])->assertUnprocessable()
                ->assertJsonPath('reason', 'reset_code_invalid')
                ->assertJsonValidationErrors('code');
        }

        $this->postJson('/api/v1/auth/password/reset', [
            'identifier' => $patient->phone,
            'code' => $wrong,
            'password' => 'new-secret-123',
        ])->assertUnprocessable()->assertJsonPath('reason', 'reset_code_expired');

        // Even the right code is dead now.
        $this->postJson('/api/v1/auth/password/reset', [
            'identifier' => $patient->phone,
            'code' => $code,
            'password' => 'new-secret-123',
        ])->assertUnprocessable()->assertJsonPath('reason', 'reset_code_expired');

        $this->assertTrue(Hash::check('password', $patient->refresh()->password));
    }

    public function test_an_expired_code_is_rejected(): void
    {
        $patient = $this->makePatientUser();
        $this->postJson('/api/v1/auth/password/forgot', ['identifier' => $patient->phone]);
        $code = $this->sentCode();

        $this->travel(PasswordResetController::CODE_LIFETIME_MINUTES + 1)->minutes();

        $this->postJson('/api/v1/auth/password/reset', [
            'identifier' => $patient->phone,
            'code' => $code,
            'password' => 'new-secret-123',
        ])->assertUnprocessable()->assertJsonPath('reason', 'reset_code_expired');
    }

    public function test_reset_validates_code_format_and_password_length(): void
    {
        $patient = $this->makePatientUser();

        $this->postJson('/api/v1/auth/password/reset', [
            'identifier' => $patient->phone,
            'code' => '12ab',
            'password' => 'short',
        ])->assertUnprocessable()->assertJsonValidationErrors(['code', 'password']);
    }

    public function test_staff_can_reset_by_email(): void
    {
        ['doctorUser' => $doctor] = $this->makeListedClinic();

        $this->postJson('/api/v1/auth/password/forgot', ['identifier' => $doctor->email])->assertOk();

        $this->postJson('/api/v1/auth/password/reset', [
            'identifier' => $doctor->email,
            'code' => $this->sentCode(),
            'password' => 'doctor-new-pass',
        ])->assertOk();

        $this->assertTrue(Hash::check('doctor-new-pass', User::query()->findOrFail($doctor->getKey())->password));
    }

    public function test_forgot_is_rate_limited_per_account(): void
    {
        $patient = $this->makePatientUser();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/password/forgot', ['identifier' => $patient->phone])->assertOk();
        }

        $this->postJson('/api/v1/auth/password/forgot', ['identifier' => $patient->phone])->assertTooManyRequests();
    }

    private function sentCode(): string
    {
        $code = null;
        Mail::assertSent(MobilePasswordResetCodeMail::class, function (MobilePasswordResetCodeMail $mail) use (&$code): bool {
            $code = $mail->code;

            return true;
        });

        return (string) $code;
    }
}
