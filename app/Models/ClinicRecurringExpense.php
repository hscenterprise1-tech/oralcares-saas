<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A cost that comes back (rent, a service contract). When it falls due,
 * `ClinicExpenses::raiseDue()` records that period's expense once.
 */
class ClinicRecurringExpense extends Model
{
    use ClinicScoped;

    public const FREQUENCIES = ['weekly', 'monthly', 'quarterly', 'yearly'];

    protected $fillable = ['clinic_id', 'branch_id', 'category_id', 'vendor_id', 'title', 'amount', 'payment_method', 'frequency', 'next_due_on', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'next_due_on' => 'date', 'is_active' => 'boolean'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ClinicExpenseCategory::class, 'category_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(ClinicVendor::class, 'vendor_id');
    }
}
