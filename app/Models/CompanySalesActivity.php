<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanySalesActivity extends Model
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
        'opportunity_id',
        'company_employee_id',
        'event',
        'description',
    ];

    /**
     * Get the opportunity this sales activity is logged for.
     *
     * @return BelongsTo<CompanyOpportunity, $this>
     */
    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(CompanyOpportunity::class, 'opportunity_id');
    }

    /**
     * Get the employee who performed the activity.
     *
     * @return BelongsTo<CompanyEmployee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(CompanyEmployee::class, 'company_employee_id');
    }
}
