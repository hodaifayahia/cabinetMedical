<?php

namespace App\Mail;

use App\Models\Cabinet;
use App\Models\HostedLicenseGrant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent synchronously on purpose: queueing would serialize the one-time
 * plaintext code into the queue backend. The service catches delivery errors.
 */
class CabinetLicenseCodeIssuedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Cabinet $cabinet,
        public readonly HostedLicenseGrant $grant,
        public readonly string $ownerName,
        public readonly string $licenseCode,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Votre code d’activation Drclick',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.cabinet-license-code-issued',
            with: [
                'cabinetName' => $this->cabinet->name,
                'ownerName' => $this->ownerName,
                'licensePlan' => $this->grant->typeLabel(),
                'licenseCode' => $this->licenseCode,
                'activationUrl' => route('cabinet.pending'),
            ],
        );
    }

    /** @return array<int, mixed> */
    public function attachments(): array
    {
        return [];
    }
}
