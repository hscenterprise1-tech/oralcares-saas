<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadEmailThread extends Model
{
    use HasFactory;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    protected $table = 'lead_email_threads';

    protected $fillable = [
        'lead_id',
        'message_id',
        'direction',
        'status',
        'to_email',
        'sent_by_user_id',
        'sent_at',
        'failed_at',
        'failure_reason',
        'attempts',
        'subject',
        'body_html',
        'is_opened',
        'open_count',
        'click_count',
        'has_attachment',
    ];

    protected function casts(): array
    {
        return [
            'is_opened' => 'boolean',
            'has_attachment' => 'boolean',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }

    /**
     * Messages that did not leave the building and may be retried.
     *
     * @param  Builder<LeadEmailThread>  $query
     * @return Builder<LeadEmailThread>
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    public function isRetryable(): bool
    {
        return $this->status === self::STATUS_FAILED && $this->direction === 'outbound';
    }
}
