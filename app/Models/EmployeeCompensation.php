<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What an employee earns, and from when.
 *
 * Effective-dated: a raise closes the previous arrangement rather than editing
 * it, so a run calculated last month still reads the figures that were in force
 * last month. `effective_to` is null for the arrangement currently in force.
 */
class EmployeeCompensation extends Model
{
    protected $table = 'company_employee_compensations';

    protected $fillable = [
        'employee_id', 'base_salary', 'effective_from', 'effective_to', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'base_salary' => 'decimal:2',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(CompanyEmployee::class, 'employee_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CompensationLine::class, 'compensation_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Whether this arrangement covers the given date. */
    public function coversDate(\DateTimeInterface $date): bool
    {
        if ($this->effective_from->greaterThan($date)) {
            return false;
        }

        return $this->effective_to === null || $this->effective_to->greaterThanOrEqualTo($date);
    }
}
