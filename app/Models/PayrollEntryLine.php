<?php

namespace App\Models;

use App\CompensationComponentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A snapshotted payslip line.
 *
 * The component's code, name and type are stored by value. Renaming or retiring
 * a component leaves every historical payslip reading exactly as it did.
 */
class PayrollEntryLine extends Model
{
    protected $table = 'company_payroll_entry_lines';

    protected $fillable = [
        'entry_id', 'component_id', 'component_code', 'component_name', 'type', 'amount',
    ];

    protected function casts(): array
    {
        return [
            'type' => CompensationComponentType::class,
            'amount' => 'decimal:2',
        ];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(PayrollEntry::class, 'entry_id');
    }
}
