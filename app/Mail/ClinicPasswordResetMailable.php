<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The one-time link a clinic user asked for from "Forgot your password?".
 * The URL is built by `ClinicPasswordResetController`, which is the only
 * place that ever holds the plain token.
 */
class ClinicPasswordResetMailable extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public User $user, public string $resetUrl)
    {
        // The reset link is a bearer credential: encrypted in the queue
        // payload (and in `failed_jobs`), sent ahead of routine work.
        $this->onQueue(config('queue.queues.critical'));
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Reset your clinic portal password',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.clinic.password-reset',
        );
    }
}
