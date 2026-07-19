<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyQuotationActivity extends Model
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
        'company_quotation_id',
        'company_employee_id',
        'event',
        'description',
    ];

    /**
     * Get the quotation that this activity is logged for.
     *
     * @return BelongsTo<CompanyQuotation, $this>
     */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(CompanyQuotation::class, 'company_quotation_id');
    }

    /**
     * Get the employee who performed this action.
     *
     * @return BelongsTo<CompanyEmployee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(CompanyEmployee::class, 'company_employee_id');
    }
}
