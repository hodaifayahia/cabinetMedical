<?php

namespace App\Mail;

use App\Models\Cabinet;
use App\Models\License;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CabinetLicenseUpdatedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Cabinet $cabinet,
        public readonly License $license,
        public readonly string $ownerName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'La licence de votre cabinet a été mise à jour',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.cabinet-license-updated',
            with: [
                'cabinetName' => $this->cabinet->name,
                'ownerName' => $this->ownerName,
                'licensePlan' => $this->license->plan?->label(),
                'expiresAt' => $this->license->expires_at,
                'loginUrl' => route('login'),
            ],
        );
    }

    /**
     * @return array<int, mixed>
     */
    public function attachments(): array
    {
        return [];
    }
}
