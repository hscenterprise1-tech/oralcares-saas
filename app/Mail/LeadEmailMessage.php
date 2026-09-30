<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A sales email composed by a rep against a lead.
 *
 * The body is authored in the CRM rather than by a template, so it arrives as
 * pre-rendered HTML. Escaping happens at composition time in the service, not
 * here, so this mailable stays a thin envelope.
 */
class LeadEmailMessage extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $subjectLine,
        public string $bodyHtml,
        public ?string $replyToAddress = null,
        public ?string $replyToName = null,
    ) {}

    public function envelope(): Envelope
    {
        $envelope = new Envelope(subject: $this->subjectLine);

        // Replies belong to the rep who wrote the message, not the app mailbox.
        if ($this->replyToAddress) {
            $envelope->replyTo = [new Address($this->replyToAddress, $this->replyToName)];
        }

        return $envelope;
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.lead-message',
            with: ['bodyHtml' => $this->bodyHtml],
        );
    }
}
