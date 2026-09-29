<?php

namespace App\Mail;

use App\ClinicPasswordTokenPurpose;
use App\Models\User;
use App\Services\Auth\ClinicPasswordTokenService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordSetupMailable extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $user;

    public $setupUrl;

    /**
     * Create a new message instance.
     */
    public function __construct(User $user)
    {
        $this->user = $user;

        /*
         * A fresh one-time token per email, which also retires any earlier
         * setup link sent to this user. It replaced `/{id}/{sha1(email)}`,
         * which anyone could rebuild from the user's id and email. Its 48-hour
         * signature was never checked, so it also never expired.
         */
        $token = app(ClinicPasswordTokenService::class)->issue($user, ClinicPasswordTokenPurpose::Setup);
        $this->setupUrl = route('clinic.password.setup', ['token' => $token]);
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Welcome to OralCares - Setup Your Password',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'emails.clinic.password-setup',
        );
    }
}
