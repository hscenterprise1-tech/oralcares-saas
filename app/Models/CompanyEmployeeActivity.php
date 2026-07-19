<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyEmployeeActivity extends Model
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
        'company_employee_id',
        'event',
        'description',
        'ip_address',
        'user_agent',
    ];

    /**
     * Get the employee profile linked to this activity.
     *
     * @return BelongsTo<CompanyEmployee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(CompanyEmployee::class, 'company_employee_id');
    }
}
