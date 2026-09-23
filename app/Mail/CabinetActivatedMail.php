<?php

namespace App\Mail;

use App\Models\Cabinet;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Notifies a cabinet owner that their cabinet has been activated. Implements
 * ShouldQueue so delivery is offloaded to the queue when one is configured;
 * with the default sync queue it sends inline.
 *
 * CabinetFulfillmentService::notifyOwner() wraps the dispatch in a try/catch
 * so a broken mailer never fails activation — but once queued, that catch
 * only guards enqueuing; the actual send happens later on the worker, where a
 * bad SMTP config previously failed silently after three retries. failed()
 * below is Laravel's hook for a queued mailable's queue job
 * (SendQueuedMailable::failed()) and restores that visibility.
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
                'licensePlan' => $this->cabinet->license?->typeLabel(),
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

    public function failed(Throwable $exception): void
    {
        Log::warning('Cabinet activation e-mail could not be delivered by the queue worker.', [
            'cabinet_id' => $this->cabinet->getKey(),
            'error' => $exception->getMessage(),
        ]);
    }
}
