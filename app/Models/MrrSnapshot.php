<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * What the recurring revenue was, in one month.
 *
 * Growth and the trend chart need history, and there was none — so the chart
 * repeated today's MRR across six months and called it a trend. A snapshot is
 * taken once per month and never recalculated: that is the whole point, because
 * a plan price change must not rewrite what an earlier month earned.
 *
 * `unrecorded_subscriptions` is carried deliberately. A month where some active
 * subscriptions had no MRR on record is an incomplete month, and a figure that
 * hid that would be worse than no figure.
 */
class MrrSnapshot extends Model
{
    protected $table = 'company_mrr_snapshots';

    protected $fillable = [
        'period',
        'mrr',
        'active_subscriptions',
        'active_clinics',
        'arpu',
        'unrecorded_subscriptions',
        'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'mrr' => 'decimal:2',
            'arpu' => 'decimal:2',
            'active_subscriptions' => 'integer',
            'active_clinics' => 'integer',
            'unrecorded_subscriptions' => 'integer',
            'captured_at' => 'datetime',
        ];
    }

    /** Whether this month's figure covers every active subscription. */
    public function isComplete(): bool
    {
        return $this->unrecorded_subscriptions === 0;
    }
}
