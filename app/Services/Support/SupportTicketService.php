<?php

namespace App\Services\Support;

use App\Models\CompanyEmployee;
use App\Models\CompanySupportSlaTarget;
use App\Models\CompanySupportTicket;
use App\Models\User;
use App\SupportTicketPriority;
use App\SupportTicketStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Running the support desk.
 *
 * Every change to a ticket writes an event, so the history is the record rather
 * than a reconstruction of it. Nothing here edits an event once written.
 *
 * What this refuses to invent: how long anything should take. The SLA clock is
 * driven entirely by `company_support_sla_targets`, which is empty until the
 * business configures it — a priority with no target produces no due date, and
 * the ticket says so instead of being measured against a made-up interval.
 * There is also no auto-assignment and no escalation: both are routing policy
 * nobody has decided.
 */
class SupportTicketService
{
    /**
     * Open a ticket.
     *
     * @param  array<string, mixed>  $data
     */
    public function open(array $data, ?User $actor = null): CompanySupportTicket
    {
        return DB::transaction(function () use ($data, $actor) {
            $priority = SupportTicketPriority::from($data['priority'] ?? SupportTicketPriority::Normal->value);

            $ticket = CompanySupportTicket::create([
                'reference' => 'SUP-'.now()->format('Ym').'-'.Str::upper(Str::random(5)),
                'organization_id' => $data['organization_id'] ?? null,
                'clinic_id' => $data['clinic_id'] ?? null,
                'contact_id' => $data['contact_id'] ?? null,
                'reporter_name' => $data['reporter_name'] ?? null,
                'reporter_email' => $data['reporter_email'] ?? null,
                'subject' => $data['subject'],
                'body' => $data['body'] ?? null,
                'category' => $data['category'] ?? null,
                'channel' => $data['channel'] ?? 'portal',
                'status' => SupportTicketStatus::Open,
                'priority' => $priority,
                'assigned_to' => $data['assigned_to'] ?? null,
                'assigned_at' => ! empty($data['assigned_to']) ? now() : null,
                'opened_by' => $actor?->id,
            ] + $this->slaDueDates($priority));

            $this->record($ticket, 'opened', null, $actor, null, $ticket->status->value);

            return $ticket->fresh(['organization', 'assignee']);
        });
    }

    /**
     * Due dates from the configured target, or none at all.
     *
     * @return array{first_response_due_at: ?Carbon, resolution_due_at: ?Carbon}
     */
    public function slaDueDates(SupportTicketPriority $priority): array
    {
        $target = CompanySupportSlaTarget::query()->where('priority', $priority->value)->first();

        return [
            'first_response_due_at' => $target?->first_response_minutes
                ? now()->addMinutes($target->first_response_minutes)
                : null,
            'resolution_due_at' => $target?->resolution_minutes
                ? now()->addMinutes($target->resolution_minutes)
                : null,
        ];
    }

    /**
     * Add a note or a reply.
     *
     * A reply that is the first thing said to the customer stops the
     * first-response clock. An internal note never does — writing a note to
     * yourself is not answering anybody.
     */
    public function comment(CompanySupportTicket $ticket, string $body, bool $internal, ?User $actor = null): CompanySupportTicket
    {
        if (trim($body) === '') {
            throw new InvalidArgumentException('A note cannot be empty.');
        }

        return DB::transaction(function () use ($ticket, $body, $internal, $actor) {
            $this->record($ticket, $internal ? 'note' : 'reply', $body, $actor, null, null, $internal);

            if (! $internal && $ticket->first_responded_at === null) {
                $ticket->update(['first_responded_at' => now()]);
            }

            return $ticket->fresh('events');
        });
    }

    /** Move a ticket along, recording the change. */
    public function transition(CompanySupportTicket $ticket, SupportTicketStatus $target, ?User $actor = null, ?string $note = null): CompanySupportTicket
    {
        if (! $ticket->status->canTransitionTo($target)) {
            throw new InvalidArgumentException(
                "A {$ticket->status->label()} ticket cannot become {$target->label()}."
            );
        }

        return DB::transaction(function () use ($ticket, $target, $actor, $note) {
            $from = $ticket->status->value;

            $stamp = match ($target) {
                SupportTicketStatus::Resolved => [
                    'resolved_at' => now(),
                    'resolved_by' => $actor?->id,
                    'resolution_note' => $note,
                ],
                SupportTicketStatus::Closed => ['closed_at' => now()],
                /* Reopening clears the resolution — it was not one. */
                SupportTicketStatus::Open => ['resolved_at' => null, 'closed_at' => null],
                default => [],
            };

            $ticket->update(['status' => $target] + $stamp);

            $this->record($ticket, 'status', $note, $actor, $from, $target->value);

            return $ticket->fresh();
        });
    }

    /** Hand a ticket to somebody, or take it back. */
    public function assign(CompanySupportTicket $ticket, ?CompanyEmployee $employee, ?User $actor = null): CompanySupportTicket
    {
        return DB::transaction(function () use ($ticket, $employee, $actor) {
            $from = $ticket->assignee?->employee_code;

            $ticket->update([
                'assigned_to' => $employee?->id,
                'assigned_at' => $employee ? now() : null,
            ]);

            $this->record($ticket, 'assignment', null, $actor, $from, $employee?->employee_code);

            return $ticket->fresh('assignee');
        });
    }

    /** Change priority, and re-derive the clock from the new target. */
    public function reprioritise(CompanySupportTicket $ticket, SupportTicketPriority $priority, ?User $actor = null): CompanySupportTicket
    {
        return DB::transaction(function () use ($ticket, $priority, $actor) {
            $from = $ticket->priority->value;

            /*
             * The due dates move with the priority, but only for what has not
             * happened yet: a first response already given is not re-clocked by
             * changing the priority afterwards.
             */
            $due = $this->slaDueDates($priority);

            $ticket->update([
                'priority' => $priority,
                'first_response_due_at' => $ticket->first_responded_at ? $ticket->first_response_due_at : $due['first_response_due_at'],
                'resolution_due_at' => $ticket->resolved_at ? $ticket->resolution_due_at : $due['resolution_due_at'],
            ]);

            $this->record($ticket, 'priority', null, $actor, $from, $priority->value);

            return $ticket->fresh();
        });
    }

    protected function record(
        CompanySupportTicket $ticket,
        string $type,
        ?string $body = null,
        ?User $actor = null,
        ?string $from = null,
        ?string $to = null,
        bool $internal = true,
    ): void {
        $ticket->events()->create([
            'event_type' => $type,
            'body' => $body,
            'from_value' => $from,
            'to_value' => $to,
            'is_internal' => $internal,
            'actor_id' => $actor?->id,
        ]);
    }
}
