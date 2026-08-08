<?php

namespace App\Mail;

use App\Models\Cabinet;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Notifies a cabinet owner that their cabinet has been activated. Implements
 * ShouldQueue so delivery is offloaded to the queue when one is configured;
 * with the default sync queue it sends inline.
 */
class CabinetActivatedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Cabinet $cabinet,
        public readonly string $ownerName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Votre cabinet est maintenant actif',
        );
    }

    public function content(): Content
    {
        $this->cabinet->loadMissing('license');

        return new Content(
            markdown: 'emails.cabinet-activated',
            with: [
                'cabinetName' => $this->cabinet->name,
                'ownerName' => $this->ownerName,
                'licensePlan' => $this->cabinet->license?->plan?->label(),
                'expiresAt' => $this->cabinet->license?->expires_at,
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
