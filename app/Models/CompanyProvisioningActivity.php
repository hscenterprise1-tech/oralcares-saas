<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyProvisioningActivity extends Model
{
    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_provisioning_process_id',
        'event',
        'description',
    ];

    /**
     * Get the parent provisioning process.
     *
     * @return BelongsTo<CompanyProvisioningProcess, $this>
     */
    public function process(): BelongsTo
    {
        return $this->belongsTo(CompanyProvisioningProcess::class, 'company_provisioning_process_id');
    }
}
