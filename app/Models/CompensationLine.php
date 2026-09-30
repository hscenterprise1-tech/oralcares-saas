<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One component attached to one compensation arrangement. */
class CompensationLine extends Model
{
    protected $table = 'company_compensation_lines';

    protected $fillable = ['compensation_id', 'component_id', 'value'];

    protected function casts(): array
    {
        return ['value' => 'decimal:2'];
    }

    public function compensation(): BelongsTo
    {
        return $this->belongsTo(EmployeeCompensation::class, 'compensation_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(CompensationComponent::class, 'component_id');
    }
}
