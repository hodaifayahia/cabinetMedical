<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The six-digit code of the mobile "Forgot password" flow, in French and
 * Arabic. Sent inline, never queued: the person is waiting on the next
 * screen, and a queue without a worker would swallow it.
 */
class MobilePasswordResetCodeMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly int $expiresInMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Code de réinitialisation · رمز إعادة التعيين',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.mobile-password-reset-code',
            with: [
                'code' => $this->code,
                'name' => $this->name,
                'minutes' => $this->expiresInMinutes,
            ],
        );
    }
}
