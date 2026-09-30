<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadCallLog extends Model
{
    use HasFactory;

    protected $table = 'lead_call_logs';

    protected $fillable = [
        'lead_id',
        'user_id',
        'call_duration_seconds',
        'disposition',
        'notes',
        'next_followup_at',
    ];

    protected function casts(): array
    {
        return [
            'next_followup_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
