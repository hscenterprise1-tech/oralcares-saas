<?php

namespace App\Models;

use App\OpportunityStage;
use Database\Factories\CompanyOpportunityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompanyOpportunity extends Model
{
    /** @use HasFactory<CompanyOpportunityFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'lead_id',
        'title',
        'stage',
        'value',
        'assigned_id',
        'closed_at',
        'notes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stage' => OpportunityStage::class,
            'value' => 'decimal:2',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * Get the CRM lead associated with this opportunity.
     *
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    /**
     * Get the sales executive assigned to this opportunity.
     *
     * @return BelongsTo<CompanyEmployee, $this>
     */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(CompanyEmployee::class, 'assigned_id');
    }

    /**
     * Get all demos scheduled for this opportunity.
     *
     * @return HasMany<CompanyDemo, $this>
     */
    public function demos(): HasMany
    {
        return $this->hasMany(CompanyDemo::class, 'opportunity_id');
    }

    /**
     * Get all trials set up for this opportunity.
     *
     * @return HasMany<CompanyTrial, $this>
     */
    public function trials(): HasMany
    {
        return $this->hasMany(CompanyTrial::class, 'opportunity_id');
    }

    /**
     * Get the sales activities logged for this opportunity.
     *
     * @return HasMany<CompanySalesActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(CompanySalesActivity::class, 'opportunity_id');
    }

    /**
     * Get the reminders set for this opportunity.
     *
     * @return HasMany<CompanyReminder, $this>
     */
    public function reminders(): HasMany
    {
        return $this->hasMany(CompanyReminder::class, 'opportunity_id');
    }
}
