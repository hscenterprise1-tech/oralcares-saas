<?php

namespace App\Models;

use App\Traits\OrganizationScoped;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MarketingCampaign extends Model
{
    use HasFactory, OrganizationScoped, SoftDeletes;

    protected $table = 'marketing_campaigns';

    protected $fillable = [
        'organization_id',
        'whatsapp_account_id',
        'audience_id',
        'template_id',
        'name',
        'description',
        'status',
        'variable_mapping',
        'scheduled_at',
        'started_at',
        'completed_at',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected $casts = [
        'variable_mapping' => 'array',
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function whatsappAccount(): BelongsTo
    {
        return $this->belongsTo(MarketingWhatsAppAccount::class, 'whatsapp_account_id');
    }

    public function audience(): BelongsTo
    {
        return $this->belongsTo(MarketingAudience::class, 'audience_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(MarketingWhatsAppTemplate::class, 'template_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(MarketingCampaignRecipient::class, 'campaign_id');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isSending(): bool
    {
        return in_array($this->status, ['preparing', 'sending']);
    }

    public function isPaused(): bool
    {
        return $this->status === 'paused';
    }

    public function canLaunch(): bool
    {
        return in_array($this->status, ['draft', 'scheduled', 'paused']);
    }

    /**
     * The one grouped query every metrics consumer reads from — the campaign
     * list, the detail page, and campaign analytics. Recipient `status` is a
     * single column, never more than one value at a time for a given row, so
     * a GROUP BY partitions every recipient into exactly one of the eight
     * states the migration documents (pending, queued, sent, delivered,
     * read, replied, failed, skipped) and those eight sum to `total`.
     * Nothing here is cached or estimated — it is always exactly what the
     * table holds at the moment this runs.
     *
     * Everything below `raw` is a *cumulative* reading built on top of those
     * exclusive buckets — `sent` here means "reached at least sent" (i.e.
     * sent + delivered + read + replied), matching the funnel language the
     * Campaign Detail page already shows (Queued → Sent → Delivered → Read →
     * Replied). A consumer that needs the exact, non-cumulative count for
     * one status — recipient filtering, for instance — reads `raw` instead.
     *
     * @return array{total: int, pending: int, queued: int, sent: int, delivered: int, read: int, replied: int, failed: int, skipped: int, raw: array<string, int>}
     */
    public function calculateMetrics(): array
    {
        $raw = $this->recipients()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $sum = fn (string ...$statuses) => array_sum(array_map(fn ($s) => (int) ($raw[$s] ?? 0), $statuses));

        return [
            'total' => array_sum($raw),
            'pending' => $sum('pending'),
            'queued' => $sum('pending', 'queued'),
            'sent' => $sum('sent', 'delivered', 'read', 'replied'),
            'delivered' => $sum('delivered', 'read', 'replied'),
            'read' => $sum('read', 'replied'),
            'replied' => $sum('replied'),
            'failed' => $sum('failed'),
            'skipped' => $sum('skipped'),
            'raw' => [
                'pending' => $sum('pending'),
                'queued' => $sum('queued'),
                'sent' => $sum('sent'),
                'delivered' => $sum('delivered'),
                'read' => $sum('read'),
                'replied' => $sum('replied'),
                'failed' => $sum('failed'),
                'skipped' => $sum('skipped'),
            ],
        ];
    }
}
