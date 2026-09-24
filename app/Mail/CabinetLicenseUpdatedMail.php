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
use Illuminate\Support\Facades\Log;
use Throwable;

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
                'licensePlan' => $this->license->typeLabel(),
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

    public function failed(Throwable $exception): void
    {
        Log::warning('Cabinet licence-update e-mail could not be delivered by the queue worker.', [
            'cabinet_id' => $this->cabinet->getKey(),
            'error' => $exception->getMessage(),
        ]);
    }
}
