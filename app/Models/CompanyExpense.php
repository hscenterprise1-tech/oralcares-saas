<?php

namespace App\Models;

use App\ExpenseStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money the company spends.
 *
 * An expense now moves Submitted → Approved → Paid, and each step records who
 * took it. Before this it was written `approved` the moment it was created, in
 * a request gated on `view-expenses` — a read permission authorising a write.
 *
 * @property ExpenseStatus $status
 */
class CompanyExpense extends Model
{
    use HasFactory;

    protected $fillable = [
        'expense_number',
        'description',
        'category',
        'amount',
        'expense_date',
        'submitted_by',
        'status',
        'notes',
        'submitted_at',
        'approved_by',
        'approved_at',
        'paid_by',
        'paid_at',
        'payment_reference',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'expense_date' => 'date',
            'status' => ExpenseStatus::class,
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    /**
     * Expenses the business has committed to — approved or already paid.
     *
     * The KPI used to say `where('status', 'approved')`, which silently dropped
     * every expense once it was paid. Money does not stop having been spent
     * when it leaves the account.
     *
     * @param  Builder<self>  $query
     */
    public function scopeCommitted(Builder $query): void
    {
        $query->whereIn('status', [ExpenseStatus::Approved->value, ExpenseStatus::Paid->value]);
    }

    /**
     * Expenses still waiting on a person.
     *
     * @param  Builder<self>  $query
     */
    public function scopeAwaitingApproval(Builder $query): void
    {
        $query->whereIn('status', [ExpenseStatus::Pending->value, ExpenseStatus::Submitted->value]);
    }
}
