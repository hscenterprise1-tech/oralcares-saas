<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Hands a newly provisioned clinic to its owner.
 */
class OwnerInvitationMail extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public string $ownerName, public string $clinicName, public string $acceptUrl, public int $validDays)
    {
        // The accept link is a bearer credential: encrypted in the queue
        // payload (and in `failed_jobs`), sent ahead of routine work.
        $this->afterCommit();
        $this->onQueue(config('queue.queues.critical'));
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->clinicName} is ready: set up your clinic");
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.clinic.owner-invitation');
    }
}
