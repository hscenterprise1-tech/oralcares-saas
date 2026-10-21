<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Money paid to an employee ahead of their salary, recovered through payroll. */
class ClinicSalaryAdvance extends Model
{
    use ClinicScoped;

    public const OPEN = 'open';

    public const RECOVERED = 'recovered';

    protected $fillable = ['clinic_id', 'clinic_employee_id', 'amount', 'recovered_amount', 'given_on', 'payment_method', 'notes', 'status', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'recovered_amount' => 'decimal:2', 'given_on' => 'date'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'clinic_employee_id');
    }

    public function outstanding(): float
    {
        return round((float) $this->amount - (float) $this->recovered_amount, 2);
    }
}
