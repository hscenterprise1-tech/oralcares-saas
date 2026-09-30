<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing that happened to a ticket.
 *
 * Notes, replies and every status or assignment change share this stream,
 * because a history split across three tables is a history nobody reads. Rows
 * are appended and never modified.
 */
class CompanySupportTicketEvent extends Model
{
    protected $table = 'company_support_ticket_events';

    protected $fillable = [
        'ticket_id', 'event_type', 'body', 'from_value', 'to_value', 'is_internal', 'actor_id',
    ];

    protected function casts(): array
    {
        return ['is_internal' => 'boolean'];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(CompanySupportTicket::class, 'ticket_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
