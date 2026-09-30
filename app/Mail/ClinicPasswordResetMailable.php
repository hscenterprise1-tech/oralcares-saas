<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
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
class ClinicPasswordResetMailable extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public User $user, public string $resetUrl) {}

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
