<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClinicQueue extends Model
{
    use ClinicScoped;

    protected $fillable = [
        'clinic_id',
        'branch_id',
        'name',
        'doctor_id',
        'queue_date',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'queue_date' => 'date',
            'closed_at' => 'datetime',
        ];
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'doctor_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ClinicQueueItem::class)->orderBy('position');
    }
}
