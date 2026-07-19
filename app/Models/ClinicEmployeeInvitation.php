<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClinicEmployeeInvitation extends Model
{
    use ClinicScoped;

    protected $fillable = [
        'clinic_id',
        'clinic_employee_id',
        'email',
        'token',
        'expires_at',
        'accepted_at',
        'revoked_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'clinic_employee_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
