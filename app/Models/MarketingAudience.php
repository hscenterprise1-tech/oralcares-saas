<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MarketingAudience extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'name',
        'description',
        'rules',
        'status',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'rules' => 'array',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function tenantOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    /**
     * Every campaign that has used this Segment for targeting.
     *
     * This is how "used in N campaigns" and "last used" are answered — from
     * the relationship that already exists (`marketing_campaigns.audience_id`),
     * not from anything stored on the Segment itself.
     */
    public function campaigns(): HasMany
    {
        return $this->hasMany(MarketingCampaign::class, 'audience_id');
    }

    /**
     * Scope query to search by audience name or description.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (empty($term)) {
            return $query;
        }

        $clean = trim($term);

        return $query->where(function (Builder $q) use ($clean) {
            $q->where('name', 'like', "%{$clean}%")
                ->orWhere('description', 'like', "%{$clean}%");
        });
    }

    /**
     * Scope query to filter by audience status.
     */
    public function scopeFilterStatus(Builder $query, ?string $status): Builder
    {
        if (empty($status) || $status === 'all') {
            return $query;
        }

        return $query->where('status', $status);
    }
}
