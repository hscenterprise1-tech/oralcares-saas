<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person's own named cut of the Deals list.
 *
 * The built-in views live in DealsQueryService::SAVED_VIEWS because they mean
 * the same thing for everyone. This is only what somebody named for themselves.
 *
 * @property array<string, string> $filters
 */
class CrmSavedView extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'organization_id',
        'name',
        'filters',
    ];

    /**
     * Filter keys a saved view may carry.
     *
     * An allow-list rather than "whatever was in the query string": a saved
     * view is replayed straight into the list, so anything stored here is
     * something the query will later act on. Keeping it explicit means a
     * stored view can never smuggle in a key the filter engine did not expect.
     *
     * @var list<string>
     */
    public const ALLOWED_FILTERS = [
        'view', 'search', 'stage', 'source', 'priority', 'organization_type',
        'city', 'state', 'assigned_user_id', 'assigned_team_id', 'sort', 'direction',
        'overdue_only', 'stalled_only', 'unassigned_only', 'hot_only', 'mine_only',
        'missing_value', 'value_min', 'value_max', 'stage_age_min', 'quiet_for_days',
        'trial', 'quotation', 'payment', 'created_from', 'created_to',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'filters' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Keep only the filters the Deals query understands, dropping empties.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, string>
     */
    public static function sanitise(array $filters): array
    {
        return collect($filters)
            ->only(self::ALLOWED_FILTERS)
            ->reject(fn ($value) => $value === null || $value === '' || $value === 'all')
            ->map(fn ($value) => (string) $value)
            ->all();
    }
}
