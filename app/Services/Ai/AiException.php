<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * An AI request that could not be served. The message is always safe to show
 * the doctor: it never echoes the provider's body, which may repeat clinical
 * detail.
 */
final class AiException extends RuntimeException
{
    public const INSUFFICIENT_CREDITS = 'insufficient_credits';

    public const DISABLED = 'disabled';

    public const UNAVAILABLE = 'unavailable';

    public const PROVIDER = 'provider_error';

    public const UNSUPPORTED = 'unsupported';

    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly ?int $balance = null,
    ) {
        parent::__construct($message);
    }

    public static function insufficientCredits(int $balance, int $cost): self
    {
        return new self(
            sprintf('Crédits IA insuffisants : cette action coûte %d crédit%s et il vous en reste %d.', $cost, $cost > 1 ? 's' : '', $balance),
            self::INSUFFICIENT_CREDITS,
            $balance,
        );
    }

    public function httpStatus(): int
    {
        return match ($this->reason) {
            self::INSUFFICIENT_CREDITS => 402,
            self::DISABLED => 403,
            self::UNSUPPORTED => 422,
            self::UNAVAILABLE => 503,
            default => 502,
        };
    }
}
