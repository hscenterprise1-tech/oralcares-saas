<?php

namespace App\Models;

use App\SupportTicketPriority;
use App\SupportTicketStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer waiting for an answer.
 *
 * Deliberately not a CRM task. A task is internal work somebody owns; a ticket
 * belongs to a customer's account, carries an SLA clock and holds a
 * conversation. Nothing here writes to `tasks`.
 *
 * @property SupportTicketStatus $status
 * @property SupportTicketPriority $priority
 */
class CompanySupportTicket extends Model
{
    protected $table = 'company_support_tickets';

    protected $fillable = [
        'reference', 'organization_id', 'clinic_id', 'contact_id',
        'reporter_name', 'reporter_email', 'subject', 'body', 'category', 'channel',
        'status', 'priority', 'assigned_to', 'assigned_at',
        'first_response_due_at', 'resolution_due_at', 'first_responded_at',
        'resolved_at', 'closed_at', 'opened_by', 'resolved_by', 'resolution_note',
    ];

    protected function casts(): array
    {
        return [
            'status' => SupportTicketStatus::class,
            'priority' => SupportTicketPriority::class,
            'assigned_at' => 'datetime',
            'first_response_due_at' => 'datetime',
            'resolution_due_at' => 'datetime',
            'first_responded_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(CompanyEmployee::class, 'assigned_to');
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    /** The whole history, oldest first. Written once, never edited. */
    public function events(): HasMany
    {
        return $this->hasMany(CompanySupportTicketEvent::class, 'ticket_id')->orderBy('id');
    }

    /** @param  Builder<self>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', [SupportTicketStatus::Open->value, SupportTicketStatus::Pending->value]);
    }

    /**
     * Whether a due date has passed with nothing done about it.
     *
     * Null when no SLA target is configured for this priority — not "on time",
     * because nobody has said what on time means.
     */
    public function breachedFirstResponse(): ?bool
    {
        if (! $this->first_response_due_at) {
            return null;
        }

        return $this->first_responded_at
            ? $this->first_responded_at->greaterThan($this->first_response_due_at)
            : now()->greaterThan($this->first_response_due_at);
    }

    public function breachedResolution(): ?bool
    {
        if (! $this->resolution_due_at) {
            return null;
        }

        return $this->resolved_at
            ? $this->resolved_at->greaterThan($this->resolution_due_at)
            : now()->greaterThan($this->resolution_due_at);
    }
}
