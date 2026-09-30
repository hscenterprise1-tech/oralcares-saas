<?php

namespace App\Services;

use App\ActivityType;
use App\Mail\LeadEmailMessage;
use App\Models\Lead;
use App\Models\LeadEmailThread;
use App\Models\MarketingContact;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Outbound email for the CRM.
 *
 * `lead_email_threads` existed with no send path, so email was the one channel
 * a rep could see history for but never use. The flow is
 * compose → validate → consent → persist → send → record outcome, and the
 * thread row is written *before* the send attempt so a hard failure still
 * leaves an auditable record rather than vanishing.
 *
 * Consent reuses the marketing suppression store rather than introducing a
 * second, divergent opt-out list: if a contact opted out of being messaged,
 * that decision applies here too.
 */
class CrmEmailService
{
    /**
     * Compose and send an email to a lead.
     *
     * @param  array{subject: string, body: string, to?: string|null}  $payload
     *
     * @throws ValidationException when the lead cannot be emailed at all
     */
    public function send(Lead $lead, array $payload, ?User $sender = null): LeadEmailThread
    {
        $recipient = trim((string) ($payload['to'] ?? $lead->email ?? ''));
        $subject = trim((string) ($payload['subject'] ?? ''));
        $body = trim((string) ($payload['body'] ?? ''));

        $this->assertSendable($lead, $recipient, $subject, $body);

        $thread = $this->openThread($lead, $recipient, $subject, $body, $sender);

        return $this->dispatch($thread, $lead, $sender);
    }

    /**
     * Retry a previously failed message.
     *
     * The original row is reused rather than duplicated, so the timeline shows
     * one conversation with an attempt count instead of a run of near-identical
     * entries.
     */
    public function retry(LeadEmailThread $thread, ?User $sender = null): LeadEmailThread
    {
        if (! $thread->isRetryable()) {
            throw ValidationException::withMessages([
                'email' => 'Only a failed outbound message can be retried.',
            ]);
        }

        $lead = $thread->lead;

        if (! $lead) {
            throw ValidationException::withMessages([
                'email' => 'The lead this message belonged to no longer exists.',
            ]);
        }

        $this->assertConsent($lead);

        $thread->update([
            'status' => LeadEmailThread::STATUS_QUEUED,
            'failed_at' => null,
            'failure_reason' => null,
        ]);

        return $this->dispatch($thread, $lead, $sender ?? $thread->sentBy);
    }

    /**
     * Conversation history for a lead, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function history(Lead $lead, int $limit = 50): array
    {
        return LeadEmailThread::query()
            ->where('lead_id', $lead->id)
            ->with('sentBy:id,name')
            ->latest('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (LeadEmailThread $thread) => [
                'id' => $thread->id,
                'direction' => $thread->direction,
                'status' => $thread->status,
                'subject' => $thread->subject,
                'body_html' => $thread->body_html,
                'to_email' => $thread->to_email,
                'sent_by' => $thread->sentBy?->name,
                'sent_at' => $thread->sent_at?->toIso8601String(),
                'failed_at' => $thread->failed_at?->toIso8601String(),
                'failure_reason' => $thread->failure_reason,
                'attempts' => $thread->attempts,
                'is_retryable' => $thread->isRetryable(),
                'occurred_at' => $thread->created_at?->toIso8601String(),
                'time' => $thread->created_at?->diffForHumans(),
            ])
            ->values()
            ->all();
    }

    /**
     * Whether this lead may currently be emailed, and why not if it may not.
     *
     * @return array{can_send: bool, reason: string|null}
     */
    public function sendability(Lead $lead): array
    {
        if (blank($lead->email)) {
            return ['can_send' => false, 'reason' => 'This lead has no email address on file.'];
        }

        if ($this->isSuppressed($lead)) {
            return ['can_send' => false, 'reason' => 'This contact has opted out of communication.'];
        }

        return ['can_send' => true, 'reason' => null];
    }

    /**
     * @throws ValidationException
     */
    protected function assertSendable(Lead $lead, string $recipient, string $subject, string $body): void
    {
        $errors = [];

        if ($recipient === '' || ! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $errors['to'] = 'A valid recipient email address is required.';
        }

        if ($subject === '') {
            $errors['subject'] = 'A subject line is required.';
        }

        if ($body === '') {
            $errors['body'] = 'The message body cannot be empty.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $this->assertConsent($lead);
    }

    /**
     * @throws ValidationException
     */
    protected function assertConsent(Lead $lead): void
    {
        if ($this->isSuppressed($lead)) {
            throw ValidationException::withMessages([
                'to' => 'This contact has opted out of communication and cannot be emailed.',
            ]);
        }
    }

    /**
     * Opt-out is stored against the marketing contact, matched on the
     * normalised phone — the same lookup the WhatsApp path uses.
     */
    protected function isSuppressed(Lead $lead): bool
    {
        $contact = $lead->marketingContact ?? MarketingContact::query()
            ->when($lead->organization_id, fn ($q) => $q->where('organization_id', $lead->organization_id))
            ->where('normalized_phone', $lead->normalized_phone)
            ->first();

        return $contact !== null
            && in_array($contact->marketing_status, ['opted_out', 'suppressed'], true);
    }

    /**
     * Persist the message before attempting delivery.
     */
    protected function openThread(Lead $lead, string $recipient, string $subject, string $body, ?User $sender): LeadEmailThread
    {
        return LeadEmailThread::create([
            'lead_id' => $lead->id,
            'message_id' => (string) Str::uuid(),
            'direction' => 'outbound',
            'status' => LeadEmailThread::STATUS_QUEUED,
            'to_email' => $recipient,
            'sent_by_user_id' => $sender?->id,
            'subject' => $subject,
            'body_html' => $this->toSafeHtml($body),
            'attempts' => 0,
        ]);
    }

    /**
     * Attempt delivery and record the outcome either way.
     *
     * A transport failure is caught rather than thrown: the rep needs the
     * message preserved and marked failed so they can retry, not a stack trace
     * and a lost draft.
     */
    protected function dispatch(LeadEmailThread $thread, Lead $lead, ?User $sender): LeadEmailThread
    {
        $thread->increment('attempts');

        try {
            Mail::to($thread->to_email)->send(new LeadEmailMessage(
                subjectLine: $thread->subject,
                bodyHtml: $thread->body_html,
                replyToAddress: $sender?->email,
                replyToName: $sender?->name,
            ));

            DB::transaction(function () use ($thread, $lead, $sender) {
                $thread->update([
                    'status' => LeadEmailThread::STATUS_SENT,
                    'sent_at' => now(),
                    'failed_at' => null,
                    'failure_reason' => null,
                ]);

                $lead->activities()->create([
                    'user_id' => $sender?->id,
                    'type' => ActivityType::Email,
                    'description' => "Email sent: {$thread->subject}",
                    'properties' => [
                        'thread_id' => $thread->id,
                        'to' => $thread->to_email,
                    ],
                ]);
            });
        } catch (Throwable $exception) {
            Log::warning('CRM email send failed', [
                'thread_id' => $thread->id,
                'lead_id' => $lead->id,
                'error' => $exception->getMessage(),
            ]);

            $thread->update([
                'status' => LeadEmailThread::STATUS_FAILED,
                'failed_at' => now(),
                'failure_reason' => Str::limit($exception->getMessage(), 500),
            ]);

            $lead->activities()->create([
                'user_id' => $sender?->id,
                'type' => ActivityType::Email,
                'description' => "Email failed to send: {$thread->subject}",
                'properties' => [
                    'thread_id' => $thread->id,
                    'to' => $thread->to_email,
                    'error' => Str::limit($exception->getMessage(), 200),
                ],
            ]);
        }

        return $thread->fresh();
    }

    /**
     * Reps compose plain text; a handful of inline tags are allowed through so
     * links and emphasis survive. Everything else is escaped, because the body
     * is echoed unescaped into the mail template.
     */
    protected function toSafeHtml(string $body): string
    {
        $escaped = e($body);
        $withBreaks = nl2br($escaped, false);

        return preg_replace_callback(
            '/&lt;(\/?(?:b|strong|i|em|u|br))&gt;/i',
            fn (array $match) => '<'.$match[1].'>',
            $withBreaks
        ) ?? $withBreaks;
    }
}
