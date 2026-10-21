<?php

namespace App\Mail;

use App\Models\ClinicEmployee;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A staff member's invitation to join a clinic.
 */
class StaffInvitationMail extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, SerializesModels;

    public string $clinicName;

    public function __construct(public ClinicEmployee $employee, public string $acceptUrl, public string $inviterName)
    {
        // The accept link is a bearer credential: encrypted in the queue
        // payload (and in `failed_jobs`), sent ahead of routine work.
        $this->afterCommit();
        $this->onQueue(config('queue.queues.critical'));
        $this->clinicName = $employee->clinic()->withoutGlobalScopes()->value('display_name')
            ?: $employee->clinic()->withoutGlobalScopes()->value('name');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "You're invited to join {$this->clinicName}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.clinic.staff-invitation');
    }
}
