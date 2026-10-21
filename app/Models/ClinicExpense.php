<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing the clinic spent money on. Pending until someone with
 * `expense.approve` approves or rejects it; an approved expense is marked
 * paid. Written by `ClinicExpenses` only.
 */
class ClinicExpense extends Model
{
    use ClinicScoped;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const PAID = 'paid';

    protected $fillable = [
        'clinic_id', 'branch_id', 'department_id', 'category_id', 'vendor_id', 'recurring_expense_id', 'title', 'amount', 'spent_on', 'payment_method',
        'reference', 'notes', 'status', 'paid_at', 'attachment_path', 'attachment_name', 'attachment_sha256', 'created_by', 'decided_by', 'decided_at', 'rejection_reason',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'spent_on' => 'date', 'paid_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ClinicExpenseCategory::class, 'category_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(ClinicVendor::class, 'vendor_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
