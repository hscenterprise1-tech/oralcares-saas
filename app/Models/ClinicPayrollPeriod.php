<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One pay period (a month) of a clinic: draft, then finalised, then paid. */
class ClinicPayrollPeriod extends Model
{
    use ClinicScoped;

    public const DRAFT = 'draft';

    public const FINALISED = 'finalised';

    public const PAID = 'paid';

    protected $fillable = ['clinic_id', 'period_start', 'period_end', 'status', 'created_by', 'finalised_at', 'finalised_by'];

    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'finalised_at' => 'datetime'];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(ClinicPayrollEntry::class, 'payroll_period_id');
    }
}
