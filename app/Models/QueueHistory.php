<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QueueHistory extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'clinic_id',
        'branch_id',
        'clinic_queue_item_id',
        'action',
        'performed_by',
        'notes',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
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

    public function queueItem(): BelongsTo
    {
        return $this->belongsTo(ClinicQueueItem::class, 'clinic_queue_item_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'performed_by');
    }
}
