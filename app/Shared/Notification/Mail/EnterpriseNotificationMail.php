<?php

namespace App\Shared\Notification\Mail;

use App\Shared\Notification\DTO\SendNotificationDTO;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EnterpriseNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public SendNotificationDTO $dto
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->dto->title,
        );
    }

    public function content(): Content
    {
        return new Content(
            // Use a default blade template or fallback to raw html
            htmlString: '<div><h1>' . $this->dto->title . '</h1><p>' . $this->dto->message . '</p></div>'
        );
    }
}
