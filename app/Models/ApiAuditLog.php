<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiAuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'clinic_employee_id',
        'endpoint',
        'method',
        'ip_address',
        'status_code',
        'payload_summary',
    ];

    protected function casts(): array
    {
        return [
            'payload_summary' => 'array',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'clinic_employee_id');
    }
}
