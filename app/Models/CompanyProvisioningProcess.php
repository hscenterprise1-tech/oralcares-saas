<?php

namespace App\Models;

use App\ProvisioningState;
use Database\Factories\CompanyProvisioningProcessFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompanyProvisioningProcess extends Model
{
    /** @use HasFactory<CompanyProvisioningProcessFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'opportunity_id',
        'clinic_id',
        'status',
        'current_step',
        'completed_steps',
        'error_message',
        'retry_count',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProvisioningState::class,
            'completed_steps' => 'array',
        ];
    }

    /**
     * Get the opportunity linked to this provisioning process.
     *
     * @return BelongsTo<CompanyOpportunity, $this>
     */
    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(CompanyOpportunity::class, 'opportunity_id');
    }

    /**
     * Get the clinic tenant that was created.
     *
     * @return BelongsTo<Clinic, $this>
     */
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class, 'clinic_id');
    }

    /**
     * Get all activities logged for this process.
     *
     * @return HasMany<CompanyProvisioningActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(CompanyProvisioningActivity::class, 'company_provisioning_process_id');
    }
}
