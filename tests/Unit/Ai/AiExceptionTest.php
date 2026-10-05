<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AiException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Every AI failure reaches the screen with an HTTP status the front end maps
 * to the right next step (recharge, contact support, retry, use a photo).
 */
class AiExceptionTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function statuses(): array
    {
        return [
            'no credits left' => [AiException::INSUFFICIENT_CREDITS, 402],
            'disabled for the cabinet' => [AiException::DISABLED, 403],
            'unsupported input' => [AiException::UNSUPPORTED, 422],
            'service unavailable' => [AiException::UNAVAILABLE, 503],
            'provider failure' => [AiException::PROVIDER, 502],
            'unknown reason from a newer hosted service' => ['quota_exceeded', 502],
            'empty reason' => ['', 502],
        ];
    }

    #[DataProvider('statuses')]
    public function test_each_reason_maps_to_its_http_status(string $reason, int $status): void
    {
        $this->assertSame($status, (new AiException('x', $reason))->httpStatus());
    }

    public function test_it_is_a_runtime_exception_carrying_reason_and_balance(): void
    {
        $exception = new AiException('Message sûr', AiException::PROVIDER, 12);

        $this->assertInstanceOf(RuntimeException::class, $exception);
        $this->assertSame('Message sûr', $exception->getMessage());
        $this->assertSame(AiException::PROVIDER, $exception->reason);
        $this->assertSame(12, $exception->balance);
    }

    public function test_balance_is_optional(): void
    {
        $this->assertNull((new AiException('x', AiException::UNAVAILABLE))->balance);
    }

    public function test_insufficient_credits_names_the_cost_and_the_balance(): void
    {
        $exception = AiException::insufficientCredits(3, 5);

        $this->assertSame(AiException::INSUFFICIENT_CREDITS, $exception->reason);
        $this->assertSame(3, $exception->balance);
        $this->assertSame(402, $exception->httpStatus());
        $this->assertStringContainsString('5 crédits', $exception->getMessage());
        $this->assertStringContainsString('il vous en reste 3', $exception->getMessage());
    }

    public function test_insufficient_credits_uses_the_singular_for_one_credit(): void
    {
        $message = AiException::insufficientCredits(0, 1)->getMessage();

        $this->assertStringContainsString('coûte 1 crédit ', $message);
        $this->assertStringNotContainsString('1 crédits', $message);
        $this->assertStringContainsString('il vous en reste 0', $message);
    }
}
