<?php

namespace App\Models;

use App\TrialStatus;
use Database\Factories\CompanyTrialFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompanyTrial extends Model
{
    /** @use HasFactory<CompanyTrialFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'opportunity_id',
        'start_date',
        'end_date',
        'status',
        'modules_enabled',
        'max_users',
        'max_branches',
        'max_doctors',
        'storage_limit_gb',
        'feature_flags',
        'trial_owner_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'status' => TrialStatus::class,
            'modules_enabled' => 'array',
            'feature_flags' => 'array',
        ];
    }

    /**
     * Get the opportunity linked to this trial.
     *
     * @return BelongsTo<CompanyOpportunity, $this>
     */
    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(CompanyOpportunity::class, 'opportunity_id');
    }

    /**
     * Get the company employee managing/owning the trial.
     *
     * @return BelongsTo<CompanyEmployee, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(CompanyEmployee::class, 'trial_owner_id');
    }

    /**
     * Get the extension logs for this trial.
     *
     * @return HasMany<CompanyTrialExtension, $this>
     */
    public function extensions(): HasMany
    {
        return $this->hasMany(CompanyTrialExtension::class, 'company_trial_id');
    }
}
