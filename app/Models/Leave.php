<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Leave extends Model
{
    protected $fillable = [
        'clinic_id',
        'employee_id',
        'leave_type',
        'status',
        'start_date',
        'end_date',
        'reason',
    ];

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'employee_id');
    }
}
